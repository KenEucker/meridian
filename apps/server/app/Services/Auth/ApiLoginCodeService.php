<?php

namespace App\Services\Auth;

use App\Mail\ApiLoginCodeMail;
use App\Models\ApiLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Issues and redeems the login codes an API client exchanges for a bearer token
 * (AUTH-019; technical spec 11.4; data/API 5.4).
 *
 * The web magic link signs a URL and hands the browser a session. A client
 * application cannot use that: it has to complete verification "without leaving
 * the application", so what arrives in the mailbox has to be something the
 * person can carry back into the app they started in. That is a short code, and
 * the exchange happens on {@see \App\Http\Controllers\Auth\ApiAuthController}
 * rather than through a browser redirect.
 *
 * What is proven is unchanged from the web path — control of a verified email
 * address — so this reuses {@see MagicLinkService} for email normalization,
 * user resolution, and the email auth identity, and differs only in what it
 * hands back.
 */
class ApiLoginCodeService
{
    public function __construct(private readonly MagicLinkService $magicLinks) {}

    /**
     * Issue a code for an address and mail it.
     *
     * Codes are issued for any syntactically valid address, whether or not it
     * resolves to a user, so that the request endpoint's response cannot be
     * used to learn who has a Meridian account. Whether the address can
     * actually sign in is decided at redemption.
     *
     * @throws InvalidArgumentException when the address is not a valid email
     */
    public function issue(string $email): IssuedApiLoginCode
    {
        $normalizedEmail = $this->requireValidEmail($email);

        $plaintextCode = ApiLoginCodeGenerator::generate();

        $record = ApiLoginCode::query()->create([
            'email' => $normalizedEmail,
            'code_hash' => ApiLoginCodeGenerator::hash($plaintextCode),
            'expires_at' => now()->addMinutes($this->expiresMinutes()),
            'attempts' => 0,
        ]);

        $issued = new IssuedApiLoginCode($record, $plaintextCode);

        // The raw code is never written to a log or an audit entry, only mailed
        // (the rule data/API 12.4 states for shared-workstation codes). In local
        // development with MAIL_MAILER=log the mail transport writes the message
        // body to the Laravel log, which is where a developer reads it from.
        Mail::to($normalizedEmail)->send(new ApiLoginCodeMail(
            $issued->formattedCode(),
            $this->expiresMinutes(),
        ));

        return $issued;
    }

    /**
     * Exchange an address and code for the user it authenticates.
     *
     * @throws InvalidArgumentException when the address is not a valid email
     * @throws ApiLoginException when the code or the account cannot sign in
     */
    public function redeem(string $email, string $code): User
    {
        $normalizedEmail = $this->requireValidEmail($email);
        $codeHash = ApiLoginCodeGenerator::hash($code);

        // Consuming the code inside a locked read is what makes it single use:
        // two requests racing the same code cannot both find it redeemable.
        $record = DB::transaction(function () use ($normalizedEmail, $codeHash): ?ApiLoginCode {
            $found = ApiLoginCode::query()
                ->where('email', $normalizedEmail)
                ->where('code_hash', $codeHash)
                ->redeemable()
                ->lockForUpdate()
                ->first();

            $found?->forceFill(['used_at' => now()])->save();

            return $found;
        });

        if (! $record instanceof ApiLoginCode) {
            // Recorded outside the transaction above so the count survives the
            // refusal it is counting.
            $this->recordFailedAttempt($normalizedEmail);

            throw ApiLoginException::invalidCode();
        }

        // Resolution runs after the code is spent. A refusal here — a disabled
        // account, or an unknown address while account creation is off — does
        // not hand the code back for another try: the code did its job, and the
        // refusal is about the account rather than about possession of the
        // address.
        $user = $this->resolveUser($normalizedEmail);

        $this->retireOutstandingCodes($normalizedEmail);

        return $user;
    }

    public function expiresMinutes(): int
    {
        $minutes = (int) config('meridian.api_tokens.login_code.expires_minutes', 15);

        return $minutes > 0 ? $minutes : 15;
    }

    /**
     * @throws ApiLoginException
     */
    private function resolveUser(string $normalizedEmail): User
    {
        try {
            $user = $this->magicLinks->resolveUserForVerifiedEmail(
                $normalizedEmail,
                (bool) config('meridian.magic_link.allow_account_creation', true),
            );
        } catch (AccountCreationDisabledException) {
            throw ApiLoginException::accountCreationDisabled();
        }

        if ($user->isDisabled()) {
            throw ApiLoginException::accountDisabled();
        }

        $this->magicLinks->syncEmailAuthIdentity($user, $normalizedEmail);

        return $user;
    }

    /**
     * Count a wrong code against every code outstanding for the address. A
     * failed attempt cannot be attributed to one issued code, because the
     * submitted value matched none of them.
     */
    private function recordFailedAttempt(string $normalizedEmail): void
    {
        ApiLoginCode::query()
            ->where('email', $normalizedEmail)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->increment('attempts');
    }

    /**
     * A successful sign-in retires every other code outstanding for the
     * address, so a code requested twice does not leave a second one live.
     */
    private function retireOutstandingCodes(string $normalizedEmail): void
    {
        ApiLoginCode::query()
            ->where('email', $normalizedEmail)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function requireValidEmail(string $email): string
    {
        $normalizedEmail = $this->magicLinks->normalizeEmail($email);

        if ($normalizedEmail === '' || ! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        return $normalizedEmail;
    }
}
