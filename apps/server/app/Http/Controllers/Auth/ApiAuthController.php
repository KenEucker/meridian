<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\ApiDeviceResolver;
use App\Services\Auth\ApiLoginCodeService;
use App\Services\Auth\ApiLoginException;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\Auth\ApiTokenRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * API login endpoints for Meridian client applications (AUTH-018, AUTH-019,
 * AUTH-024; technical spec 11.4; data/API 5.4).
 *
 * A client posts an email address, the node mails a login code, and the client
 * posts that code back and receives a bearer token — all of it over the API, so
 * the user never leaves the application and the client never depends on being
 * served same-origin by the node it talks to.
 *
 * The provider handoff endpoints in data/API 5.4 (`GET /api/auth/{provider}/start`
 * and `POST /api/auth/session`) are Google and Discord login through a system
 * browser and arrive with M16.3.
 */
class ApiAuthController extends Controller
{
    public function __construct(
        private readonly ApiLoginCodeService $loginCodes,
        private readonly ApiTokenIssuer $tokens,
        private readonly ApiTokenRevoker $revoker,
        private readonly ApiDeviceResolver $devices,
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
            'client_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'device' => ['sometimes', 'array'],
            'device.id' => ['sometimes', 'string', 'max:64'],
            'device.label' => ['sometimes', 'string', 'max:255'],
            'device.platform' => ['sometimes', 'string', 'max:64'],
            'device.public_key' => ['sometimes', 'string', 'max:'.ApiDeviceResolver::MAX_PUBLIC_KEY_LENGTH],
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

        $token = $this->tokens->issue($user, $device, $validated['client_name'] ?? null);

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
        ], 201);
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
