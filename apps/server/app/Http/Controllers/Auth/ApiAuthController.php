<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\ApiDeviceResolver;
use App\Services\Auth\ApiLoginCodeService;
use App\Services\Auth\ApiLoginException;
use App\Services\Auth\ApiProviderHandoffService;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\Auth\ApiTokenRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * API login endpoints for Meridian client applications (AUTH-018, AUTH-019,
 * AUTH-020, AUTH-024; technical spec 11.4; data/API 5.4).
 *
 * A client posts an email address, the node mails a login code, and the client
 * posts that code back and receives a bearer token — all of it over the API, so
 * the user never leaves the application and the client never depends on being
 * served same-origin by the node it talks to.
 *
 * Google and Discord login cannot work that way, because the provider consent
 * screen belongs in a browser and the provider exchange is not reimplemented in
 * the client. Those go through the handoff endpoints here instead: the client
 * starts a handoff, opens the system browser at the provider, and exchanges the
 * code the browser brings back for a token.
 */
class ApiAuthController extends Controller
{
    public function __construct(
        private readonly ApiLoginCodeService $loginCodes,
        private readonly ApiTokenIssuer $tokens,
        private readonly ApiTokenRevoker $revoker,
        private readonly ApiDeviceResolver $devices,
        private readonly ApiProviderHandoffService $handoffs,
    ) {}

    /**
     * `POST /api/auth/magic-link` — request a login code for an email address.
     *
     * The response is the same whether or not the address resolves to a user,
     * so the endpoint cannot be used to learn who holds a Meridian account.
     */
    public function requestMagicLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        try {
            $this->loginCodes->issue($validated['email']);
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => 'Enter a valid email address.',
                'errors' => ['email' => ['Enter a valid email address.']],
            ], 422);
        }

        return response()->json([
            'status' => 'sent',
            'expires_in_minutes' => $this->loginCodes->expiresMinutes(),
        ], 202);
    }

    /**
     * `POST /api/auth/magic-link/verify` — exchange a login code for a token.
     *
     * The request carries the device the token will be bound to (AUTH-021). A
     * client sends a stable install identifier it generates once, plus the
     * label, platform, and device public key the node needs the first time it
     * sees that identifier. A request that cannot supply a resolvable device is
     * refused rather than issued an unbound token.
     */
    public function verifyMagicLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            ...$this->clientRules(),
        ]);

        // The device is checked before the code is spent, so a client sending a
        // good code from an unusable device gets its code back rather than
        // losing a single-use credential to a refusal it can correct. The
        // `devices` row itself is written afterwards, so a failed sign-in does
        // not register hardware that never authenticated.
        try {
            $identity = $this->devices->identify($validated['device'] ?? null);
        } catch (ApiLoginException $exception) {
            return $this->refusal($exception);
        }

        try {
            $user = $this->loginCodes->redeem($validated['email'], $validated['code']);
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => 'Enter a valid email address.',
                'errors' => ['email' => ['Enter a valid email address.']],
            ], 422);
        } catch (ApiLoginException $exception) {
            return $this->refusal($exception);
        }

        try {
            $device = $this->devices->register($identity);
        } catch (ApiLoginException $exception) {
            return $this->refusal($exception);
        }

        return $this->issuedTokenResponse(
            $user,
            $device,
            $validated['client_name'] ?? null,
            ApiTokenIssuer::REASON_LOGIN_CODE,
        );
    }

    /**
     * `GET /api/auth/{provider}/start` — begin a Google or Discord login the
     * system browser completes (AUTH-020).
     *
     * The client says which provider it wants, which client target it is, and
     * the PKCE challenge for the verifier it is holding. It gets back the
     * provider authorization URL to open the system browser at, and the state
     * that will come back with the result.
     *
     * This answers the client rather than redirecting the browser itself, so
     * that a node with an unconfigured provider, or one that does not offer
     * provider login to this client target, is a refusal the application can
     * explain — instead of an error page in a browser the application cannot
     * see. The browser is only ever opened at the provider and returned to the
     * client's own registered address.
     */
    public function startProviderHandoff(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate([
            'client' => ['required', 'string', 'max:32'],
            // A base64url SHA-256 digest, per RFC 7636. The method is accepted
            // only to be refused when it is not S256: a plain challenge is no
            // stronger than the code it protects.
            'code_challenge' => [
                'required',
                'string',
                'min:'.ApiProviderHandoffService::MIN_CODE_CHALLENGE_LENGTH,
                'max:'.ApiProviderHandoffService::MAX_CODE_CHALLENGE_LENGTH,
                'regex:/^[A-Za-z0-9\-._~]+$/',
            ],
            'code_challenge_method' => ['sometimes', 'string', 'in:S256'],
        ]);

        try {
            $handoff = $this->handoffs->start(
                $provider,
                $validated['client'],
                $validated['code_challenge'],
            );
        } catch (ApiLoginException $exception) {
            return $this->refusal($exception);
        }

        return response()->json([
            // Where the client opens the system browser. The provider exchange
            // that follows is the node's work, not the client's.
            'authorization_url' => $handoff->authorizationUrl,
            'provider' => $handoff->record->provider,
            'client_target' => $handoff->record->client_target,
            // Where the browser will be returned to, so a client can confirm the
            // node expects the scheme this build actually registered.
            'return_url' => $handoff->record->redirect_uri,
            // Carried back on the return leg. A client that sees a different
            // state is looking at a sign-in it did not start.
            'state' => $handoff->state,
            'expires_at' => $handoff->record->expires_at?->toIso8601String(),
        ], 201);
    }

    /**
     * `POST /api/auth/session` — exchange a completed handoff for a bearer token
     * (AUTH-020, AUTH-021).
     *
     * The client posts the code the system browser brought back, the verifier
     * for the challenge it started with, and the device the token will be bound
     * to. Holding the code is not sufficient: on mobile and desktop the return
     * leg travels through a custom scheme another application can register, so
     * the verifier is what proves this is the client that started the sign-in.
     */
    public function createSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:128'],
            'code_verifier' => [
                'required',
                'string',
                'min:'.ApiProviderHandoffService::MIN_CODE_CHALLENGE_LENGTH,
                'max:'.ApiProviderHandoffService::MAX_CODE_CHALLENGE_LENGTH,
                'regex:/^[A-Za-z0-9\-._~]+$/',
            ],
            ...$this->clientRules(),
        ]);

        // Ordered exactly as the login code exchange is, and for the same
        // reason: the device is checked before the handoff is spent, so a client
        // that can correct its device identity has not lost the sign-in doing
        // so, and no `devices` row is written for hardware that never completed
        // one.
        try {
            $identity = $this->devices->identify($validated['device'] ?? null);
        } catch (ApiLoginException $exception) {
            return $this->refusal($exception);
        }

        try {
            $handoff = $this->handoffs->redeem($validated['code'], $validated['code_verifier']);
            $user = $handoff->user()->firstOrFail();
            $device = $this->devices->register($identity);
        } catch (ApiLoginException $exception) {
            return $this->refusal($exception);
        }

        return $this->issuedTokenResponse(
            $user,
            $device,
            $validated['client_name'] ?? null,
            ApiTokenIssuer::REASON_PROVIDER_HANDOFF,
            ['provider' => $handoff->provider],
        );
    }

    /**
     * `DELETE /api/auth/session` — revoke the token this request authenticated
     * with, so the client that holds it stops authenticating on its next
     * request.
     *
     * This is a client disposing of its own token. God Mode revocation by token
     * and by device (AUTH-022) is in the console.
     */
    public function destroySession(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->revoker->revokeCurrentToken($user);
        }

        return response()->json(['status' => 'revoked'], 200);
    }

    /**
     * The request fields every token issuance path carries: what the client
     * calls itself, and the device the token will be bound to (AUTH-021). A
     * client sends a stable install identifier it generates once, plus the
     * label, platform, and device public key the node needs the first time it
     * sees that identifier.
     *
     * @return array<string, list<string>>
     */
    private function clientRules(): array
    {
        return [
            'client_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'device' => ['sometimes', 'array'],
            'device.id' => ['sometimes', 'string', 'max:64'],
            'device.label' => ['sometimes', 'string', 'max:255'],
            'device.platform' => ['sometimes', 'string', 'max:64'],
            'device.public_key' => ['sometimes', 'string', 'max:'.ApiDeviceResolver::MAX_PUBLIC_KEY_LENGTH],
        ];
    }

    /**
     * The one response shape that hands a client a bearer token, whichever login
     * path earned it.
     *
     * @param  array<string, mixed>  $extra
     */
    private function issuedTokenResponse(
        User $user,
        Device $device,
        ?string $clientName,
        string $reason,
        array $extra = [],
    ): JsonResponse {
        $token = $this->tokens->issue($user, $device, $clientName, $reason);

        return response()->json([
            // The one moment the plaintext token exists outside the client that
            // will hold it. It is not logged, audited, or retrievable again.
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            // Echoed so a client can confirm which device record its token is
            // bound to, and notice if it has been issuing under a new
            // identifier on every sign-in.
            'device' => [
                'id' => $device->getKey(),
                'label' => $device->device_label,
                'platform' => $device->platform,
            ],
            ...$extra,
        ], 201);
    }

    /**
     * A refused login attempt, carrying the stable reason code every client
     * explains the refusal from.
     */
    private function refusal(ApiLoginException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'reason' => $exception->reason,
        ], $exception->status);
    }
}
