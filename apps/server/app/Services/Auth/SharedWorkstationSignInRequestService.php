<?php

namespace App\Services\Auth;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Node;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\SharedWorkstationSignInRequest;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Node\NodeSetupService;
use Illuminate\Support\Facades\DB;

/**
 * Opens, grants, and collects workstation sign-in requests (AUTH-032 through
 * AUTH-037; technical spec 13.4; data/API 12.4A).
 *
 * The scan path in three moves, each held by a different party:
 *
 *  1. **The locked workstation opens** a request and receives two values with
 *     different jobs: the request id, which is public and travels in the QR,
 *     and the pickup secret, which never leaves the machine. The split is the
 *     whole reason the unauthenticated open is safe — everything a bystander
 *     can photograph is the public half, and the half that later collects a
 *     session key was never on screen.
 *  2. **A phone holding a session grants** the request for its own user only.
 *     There is no user field to name anybody else (AUTH-033), and a grant
 *     claiming a node this is not is refused with both nodes named rather than
 *     issued into the wrong database (AUTH-034).
 *  3. **The workstation collects** with its pickup secret. A grant is
 *     collectable once and only by the opener, so a shoulder-surfed QR lets
 *     somebody sign *themselves* in there and never take the session.
 *
 * What a collected sign-in request establishes is a shared-workstation session
 * under technical spec 13.3 and nothing more (AUTH-035): the same session a
 * typed code produces, with the same five-minute inactivity rule, no API
 * token, and no device trust.
 *
 * Opening, granting, and collection are audited by request identifier. The
 * pickup secret and the session key appear in no audit entry, no log, and no
 * export (AUTH-037).
 */
class SharedWorkstationSignInRequestService
{
    public const AUDIT_OPENED = 'shared_workstation_sign_in_request.opened';

    public const AUDIT_GRANTED = 'shared_workstation_sign_in_request.granted';

    public const AUDIT_COLLECTED = 'shared_workstation_sign_in_request.collected';

    /**
     * How long an open request stays scannable. Short on purpose: the QR is
     * standing on a screen in a public place, and the Kiosk replaces an expired
     * request with a fresh one rather than leaving a stale square rendered.
     */
    public const DEFAULT_TTL_SECONDS = 120;

    public function __construct(
        private readonly AuditService $audit,
        private readonly SharedWorkstationSignInRequestThrottle $throttle,
        private readonly SharedWorkstationSessionService $sessions,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * A locked trusted workstation opens a request (AUTH-032).
     *
     * Unauthenticated at the route for the same reason the pinned-context read
     * is — a locked workstation has no credential to ask with — which is why
     * everything here is refusal-first: untrusted, revoked, and unpinned
     * workstations are refused rather than opened, and the per-workstation
     * limit is checked before a row is written.
     *
     * @throws SharedWorkstationSignInRequestException
     */
    public function open(
        SharedWorkstation $workstation,
        string $purpose = SharedWorkstationSignInRequest::PURPOSE_SIGN_IN,
        ?SharedWorkstationSession $session = null,
    ): OpenedSharedWorkstationSignInRequest {
        $this->requireTrustedWorkstation($workstation);

        if ($workstation->event_id === null || ! $workstation->event()->exists()) {
            throw SharedWorkstationSignInRequestException::workstationContextUnpinned();
        }

        $this->throttle->guardOpen($workstation);

        $pickupSecret = SharedWorkstationSignInPickupSecret::generate();
        $openedAt = now();

        $record = DB::transaction(function () use ($workstation, $purpose, $session, $pickupSecret, $openedAt): SharedWorkstationSignInRequest {
            /** @var SharedWorkstationSignInRequest $record */
            $record = SharedWorkstationSignInRequest::query()->create([
                'shared_workstation_id' => $workstation->getKey(),
                'event_id' => $workstation->event_id,
                'purpose' => $purpose,
                'shared_workstation_session_id' => $session?->getKey(),
                'pickup_secret_hash' => SharedWorkstationSignInPickupSecret::hash($pickupSecret),
                'expires_at' => $openedAt->copy()->addSeconds($this->ttlSeconds()),
            ]);

            // Audited in the same transaction, by identifier and scope only:
            // the pickup secret exists in the response to the opener and
            // nowhere else (AUTH-037).
            $this->audit->recordForEntity(
                entity: $record,
                action: self::AUDIT_OPENED,
                actorDevice: $workstation->device()->first(),
                organizationId: $workstation->organization_id,
                eventId: $workstation->event_id,
                departmentId: $workstation->department_id,
                after: [
                    'sign_in_request_id' => $record->getKey(),
                    'shared_workstation_id' => $workstation->getKey(),
                    'event_id' => $workstation->event_id,
                    'purpose' => $purpose,
                    'expires_at' => $record->expires_at?->toIso8601String(),
                ],
                sourceContext: AuditEvent::SOURCE_API,
            );

            return $record;
        });

        return new OpenedSharedWorkstationSignInRequest($record, $pickupSecret);
    }

    /**
     * A device holding a session grants the request for its own user
     * (AUTH-033), against the node that issued it (AUTH-034).
     *
     * `$claimedNodeId` is the node identity the granting device read out of the
     * QR. A mismatch is refused before anything else is looked at, because the
     * mistake it catches — a phone pointed at central, standing in front of a
     * workstation that lives on an on-site node — would otherwise surface as a
     * baffling failure at the kiosk keyboard.
     *
     * @throws SharedWorkstationSignInRequestException
     */
    public function grant(
        SharedWorkstationSignInRequest $request,
        User $user,
        ?string $claimedNodeId = null,
    ): SharedWorkstationSignInRequest {
        $this->requireLocalNode($claimedNodeId);

        $this->throttle->guardGrant($user);

        if ($user->isDisabled()) {
            throw SharedWorkstationSignInRequestException::accountDisabled();
        }

        // A re-authentication request is bound to a live session, and only that
        // session's own user confirms it (AUTH-036). Checked at grant so the
        // person is refused on their phone, where the message can say so,
        // rather than granted here and refused later at collection.
        if ($request->purpose === SharedWorkstationSignInRequest::PURPOSE_REAUTHENTICATION) {
            $sessionUserId = $request->session()->first()?->user_id;

            if ($sessionUserId === null || (string) $sessionUserId !== (string) $user->getKey()) {
                throw SharedWorkstationSignInRequestException::grantScope();
            }
        }

        return DB::transaction(function () use ($request, $user): SharedWorkstationSignInRequest {
            /** @var SharedWorkstationSignInRequest $locked */
            $locked = SharedWorkstationSignInRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isExpired() || $locked->isCollected()) {
                throw SharedWorkstationSignInRequestException::requestExpired();
            }

            if ($locked->isGranted()) {
                throw SharedWorkstationSignInRequestException::alreadyGranted();
            }

            $locked->forceFill([
                'granted_by_user_id' => $user->getKey(),
                'granted_at' => now(),
            ])->save();

            $workstation = $locked->sharedWorkstation()->first();

            $this->audit->recordForEntity(
                entity: $locked,
                action: self::AUDIT_GRANTED,
                actorUser: $user,
                organizationId: $workstation?->organization_id,
                eventId: $locked->event_id,
                departmentId: $workstation?->department_id,
                after: [
                    'sign_in_request_id' => $locked->getKey(),
                    'shared_workstation_id' => $locked->shared_workstation_id,
                    'event_id' => $locked->event_id,
                    'purpose' => $locked->purpose,
                    'granted_by_user_id' => $user->getKey(),
                ],
                sourceContext: AuditEvent::SOURCE_API,
            );

            return $locked;
        });
    }

    /**
     * The opening workstation collects with its pickup secret (AUTH-032).
     *
     * Null means "not granted yet" — the pending answer a polling Kiosk gets —
     * and everything wrong about the ask itself is an exception: a request
     * that is not this workstation's, a wrong secret, and a spent request are
     * all the same unknown, so a guess learns nothing about which.
     *
     * A granted sign-in request establishes a shared-workstation session and
     * nothing more (AUTH-035). Re-authentication requests are not collectable
     * here: they confirm a live session rather than establish one, and a
     * request opened for one purpose cannot be collected as the other.
     *
     * @throws SharedWorkstationSignInRequestException
     */
    public function collect(
        SharedWorkstation $workstation,
        SharedWorkstationSignInRequest $request,
        string $pickupSecret,
    ): ?EstablishedSharedWorkstationSession {
        $this->requireTrustedWorkstation($workstation);
        $this->requireOwnRequest($workstation, $request, $pickupSecret);

        if ($request->purpose !== SharedWorkstationSignInRequest::PURPOSE_SIGN_IN) {
            throw SharedWorkstationSignInRequestException::purposeMismatch();
        }

        if ($request->isCollected()) {
            throw SharedWorkstationSignInRequestException::requestUnknown();
        }

        if ($request->isExpired()) {
            throw SharedWorkstationSignInRequestException::requestExpired();
        }

        if (! $request->isGranted()) {
            return null;
        }

        return DB::transaction(function () use ($workstation, $request): EstablishedSharedWorkstationSession {
            /** @var SharedWorkstationSignInRequest $locked */
            $locked = SharedWorkstationSignInRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-checked under the lock: two polls racing the same grant must
            // produce one session, not two.
            if ($locked->isCollected()) {
                throw SharedWorkstationSignInRequestException::requestUnknown();
            }

            $locked->forceFill(['collected_at' => now()])->save();

            $user = $locked->grantedByUser()->first();

            // Disabled between grant and collection resolves to nobody, the
            // same answer the session guard would give a moment later. The
            // request is spent either way — possession was proved.
            if (! $user instanceof User || $user->isDisabled()) {
                throw SharedWorkstationSignInRequestException::accountDisabled();
            }

            $established = $this->sessions->startFromSignInRequest($workstation, $locked);

            $this->audit->recordForEntity(
                entity: $locked,
                action: self::AUDIT_COLLECTED,
                actorUser: $user,
                actorDevice: $workstation->device()->first(),
                organizationId: $workstation->organization_id,
                eventId: $locked->event_id,
                departmentId: $workstation->department_id,
                after: [
                    'sign_in_request_id' => $locked->getKey(),
                    'shared_workstation_id' => $workstation->getKey(),
                    'event_id' => $locked->event_id,
                    'purpose' => $locked->purpose,
                    'granted_by_user_id' => $locked->granted_by_user_id,
                    'shared_workstation_session_id' => $established->record->getKey(),
                ],
                sourceContext: AuditEvent::SOURCE_API,
            );

            return $established;
        });
    }

    /**
     * The identity a request travels under: this install's own node (AUTH-034).
     */
    public function localNode(): ?Node
    {
        return $this->nodes->activeNode();
    }

    public function ttlSeconds(): int
    {
        $configured = config('meridian.workstation_sign_in_requests.ttl_seconds', self::DEFAULT_TTL_SECONDS);

        if (! is_numeric($configured)) {
            return self::DEFAULT_TTL_SECONDS;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * AUTH-034: a grant presented to a node other than the request's issuing
     * node is refused. The request only resolves on the node that holds it, so
     * what this checks is the claim the QR made — a mismatch means the granting
     * device is pointed at a different node than the workstation is.
     *
     * @throws SharedWorkstationSignInRequestException
     */
    private function requireLocalNode(?string $claimedNodeId): void
    {
        if ($claimedNodeId === null || $claimedNodeId === '') {
            return;
        }

        $local = $this->localNode();

        if ($local instanceof Node && (string) $local->getKey() === $claimedNodeId) {
            return;
        }

        // Pairing may have taught this node the claimed peer's name, in which
        // case the refusal can name both ends.
        $claimed = Node::query()->find($claimedNodeId);

        throw SharedWorkstationSignInRequestException::foreignNode(
            (string) ($local?->node_name ?? 'this node'),
            $claimed?->node_name,
        );
    }

    /**
     * Only the opener collects (AUTH-032): the request must belong to this
     * workstation and the presented secret must hash to the stored hash. All
     * failures are the same unknown.
     *
     * @throws SharedWorkstationSignInRequestException
     */
    private function requireOwnRequest(
        SharedWorkstation $workstation,
        SharedWorkstationSignInRequest $request,
        string $pickupSecret,
    ): void {
        $belongsHere = (string) $request->shared_workstation_id === (string) $workstation->getKey();

        $secretMatches = hash_equals(
            (string) $request->pickup_secret_hash,
            SharedWorkstationSignInPickupSecret::hash($pickupSecret),
        );

        if (! $belongsHere || ! $secretMatches) {
            throw SharedWorkstationSignInRequestException::requestUnknown();
        }
    }

    /**
     * @throws SharedWorkstationSignInRequestException
     */
    private function requireTrustedWorkstation(SharedWorkstation $workstation): void
    {
        if (! $workstation->isTrusted()) {
            throw SharedWorkstationSignInRequestException::workstationUntrusted();
        }
    }
}
