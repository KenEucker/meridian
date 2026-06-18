<?php

namespace App\Services\Auth;

use App\Mail\MagicLinkLoginMail;
use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MagicLinkService
{
    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function sendLoginLink(string $email): void
    {
        $normalizedEmail = $this->normalizeEmail($email);

        if ($normalizedEmail === '' || ! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        $verificationUrl = $this->createVerificationUrl($normalizedEmail);

        if (config('mail.default') === 'log') {
            Log::info('Meridian magic login link (copy this URL): '.$verificationUrl);
        }

        Mail::to($normalizedEmail)->send(new MagicLinkLoginMail($verificationUrl));
    }

    public function createVerificationUrl(string $normalizedEmail): string
    {
        $relativeSignedUrl = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->addMinutes(config('meridian.magic_link.expires_minutes')),
            ['email' => $normalizedEmail],
            absolute: false,
        );

        return URL::to($relativeSignedUrl);
    }

    public function authenticateFromVerifiedEmail(string $email): User
    {
        $normalizedEmail = $this->normalizeEmail($email);

        if ($normalizedEmail === '' || ! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        $user = $this->resolveUserForVerifiedEmail(
            $normalizedEmail,
            (bool) config('meridian.magic_link.allow_account_creation', true),
        );

        if ($user->isDisabled()) {
            throw new DisabledUserException('This account is disabled.');
        }

        $this->syncEmailAuthIdentity($user, $normalizedEmail);

        Auth::login($user);

        return $user;
    }

    public function resolveUserForVerifiedEmail(string $normalizedEmail, bool $allowAccountCreation = true): User
    {
        $existingUser = User::query()->where('email', $normalizedEmail)->first();

        if ($existingUser !== null) {
            if ($existingUser->email_verified_at === null) {
                $existingUser->forceFill(['email_verified_at' => now()])->save();
            }

            return $existingUser;
        }

        if (! $allowAccountCreation) {
            throw new AccountCreationDisabledException('Magic-link account creation is disabled.');
        }

        $user = User::query()->create([
            'name' => Str::before($normalizedEmail, '@'),
            'email' => $normalizedEmail,
            'password' => Hash::make(Str::random(64)),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    public function syncEmailAuthIdentity(User $user, string $normalizedEmail): AuthIdentity
    {
        return AuthIdentity::query()->updateOrCreate(
            [
                'provider' => AuthIdentity::PROVIDER_EMAIL,
                'provider_subject' => $normalizedEmail,
            ],
            [
                'user_id' => $user->id,
                'provider_email' => $normalizedEmail,
                'provider_email_verified' => true,
            ],
        );
    }
}
