<?php

namespace App\Services\Auth;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Node;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Node\NodeSetupService;
use Illuminate\Support\Carbon;

/**
 * Establishes and renews trust between a user and the device they signed in from
 * (AUTH-021; technical spec 12.1, 12.2, 12.4).
 *
 * Nothing wrote a `device_trusts` row. Three Field Report services read them —
 * acceptance, append, and photo upload all refuse an origin device that is not
 * actively trusted for the submitting user — and the only row that ever existed
 * was the one `meridian:seed-local-field-fixture` seeds. So every Field Report a
 * genuinely signed-in person filed was refused with "Field Report origin device
 * is not actively trusted for the submitting user", and the only account that
 * could file one was the seeded fixture.
 *
 * Sign-in is where trust belongs, and the spec already says so: trust is per
 * user/device pair (12.1), and the device public key "is registered with the
 * server during device trust setup" (12.4) — which is the descriptor the client
 * sends when it asks for a token. A person proving who they are, from a device
 * that proved it holds usable key material, is the trust event; there is no
 * second ceremony in Alpha 1 for there to be one.
 *
 * Two properties matter and they pull in opposite directions:
 *
 *  1. **Signing in renews.** The window is six weeks from the last sign-in
 *     (12.2), not six weeks from the first, so somebody working an event does
 *     not lose their device partway through it.
 *  2. **A revoked trust stays revoked.** Renewal never clears `revoked_at`. God
 *     Mode revoking a device is an act about that device, and a sign-in
 *     undoing it would make revocation last exactly until its holder opened the
 *     app.
 */
class DeviceTrustService
{
    public const AUDIT_TRUSTED = 'device_trust.established';

    public const AUDIT_RENEWED = 'device_trust.renewed';

    public function __construct(
        private readonly AuditService $audit,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * Trust this device for this user, or renew the trust it already holds.
     *
     * Returns null when the pair holds a revoked trust, because that is a
     * refusal to trust rather than a failure: the caller still issued a token —
     * revocation of a *device* is `ApiDeviceResolver`'s to enforce, and it does —
     * and what this reports is that the device stays untrusted for that person.
     */
    public function trust(User $user, Device $device, ?Carbon $at = null): ?DeviceTrust
    {
        $at ??= Carbon::now();

        $held = DeviceTrust::query()
            ->where('user_id', $user->getKey())
            ->where('device_id', $device->getKey())
            ->first();

        if ($held?->isRevoked() === true) {
            return null;
        }

        if ($held instanceof DeviceTrust) {
            $held->forceFill([
                'last_seen_at' => $at,
                'expires_at' => DeviceTrust::expiresAtFrom($at),
                'trusted_node_fingerprint' => $this->nodeFingerprint(),
            ])->save();

            $this->record($held, $user, $device, self::AUDIT_RENEWED);

            return $held;
        }

        $trust = DeviceTrust::query()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
            'trusted_node_fingerprint' => $this->nodeFingerprint(),
            'first_trusted_at' => $at,
            'last_seen_at' => $at,
            'expires_at' => DeviceTrust::expiresAtFrom($at),
            'revoked_at' => null,
        ]);

        $this->record($trust, $user, $device, self::AUDIT_TRUSTED);

        return $trust;
    }

    /**
     * Which node this device was trusted at.
     *
     * The node's own public key, digested: a device that trusted a node is
     * recorded against the identity that node signs with, so the same trust
     * presented to a different install is visibly a trust of somewhere else.
     *
     * The column is not nullable, and an install with no node configured still
     * has to record something true. `unconfigured-node` is that: it says the
     * trust was established before this install had an identity to record,
     * rather than digesting a placeholder into something that looks like a key.
     */
    private function nodeFingerprint(): string
    {
        $node = $this->nodes->activeNode();

        if (! $node instanceof Node || $node->public_key === null) {
            return 'unconfigured-node';
        }

        return hash('sha256', (string) $node->public_key);
    }

    private function record(
        DeviceTrust $trust,
        User $user,
        Device $device,
        string $action,
    ): void {
        $this->audit->recordForEntity(
            entity: $trust,
            action: $action,
            actorUser: $user,
            actorDevice: $device,
            after: [
                'user_id' => $trust->user_id,
                'device_id' => $trust->device_id,
                'first_trusted_at' => $trust->first_trusted_at?->toIso8601String(),
                'expires_at' => $trust->expires_at?->toIso8601String(),
            ],
            sourceContext: AuditEvent::SOURCE_API,
        );
    }
}
