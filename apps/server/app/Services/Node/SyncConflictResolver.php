<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a queued sync conflict by accepting the on-site or the central
 * version, and audits the decision (technical spec 10.3; data/API 7.5, 14.2).
 *
 * A conflict is a disagreement between two versions of one entity: the version
 * this install already holds, and the version a stored remote operation would
 * write. Technical spec 10.3 gives a reviewer exactly two choices, and both are
 * named after a node rather than after a side of the wire, so the resolver's
 * first job is to work out which of the two versions is the on-site one. It does
 * that from node roles: the central side is whichever of the two nodes has the
 * `central` role, and the other side is the on-site side. Reading it from roles
 * rather than from direction is what makes the same decision mean the same thing
 * on both nodes — on central the remote version is the on-site one, and on the
 * on-site node it is the reverse.
 *
 * Accepting the remote version applies the stored operation, which is the only
 * way local state can move; the operation is applied through
 * {@see NodeOperationReceiver::applyResolvedConflict()} so the entity write and
 * the `applied` mark stay together and appliers learn that the disagreement was
 * reviewed. Accepting the local version writes nothing: the version the reviewer
 * kept is already in place, and the operation stays `conflicted` because it was
 * never applied and never will be. Operations are append-only (data/API 13.3),
 * so a superseded operation is annotated, not erased.
 *
 * Values are never edited here. Conflicts are "not manually corrected inside the
 * conflict resolver" (technical spec 10.3), so the resolver has no path that
 * writes an entity to anything other than one of the two versions the queue row
 * already shows.
 *
 * Resolution is audited (technical spec 10.3; data/API 7.5, 8), and the audit
 * event is written in the same transaction as the resolution so a resolved
 * conflict cannot exist without the record of who resolved it.
 *
 * M19.17 added a second shape of conflict with no second set of rules. A write
 * queued on a device and refused because its module went inactive (MOD-017) is
 * still two versions and still resolved by keeping one of them — but one of the
 * two is an organization's configuration rather than another node's copy of a
 * record, so only accepting central can be carried out.
 * {@see self::resolveDeviceWrite()} is that path, and it refuses the other
 * choice rather than pretending to take it.
 */
class SyncConflictResolver
{
    public const AUDIT_RESOLVED = 'sync_conflict.resolved';

    /** The version this install already holds won. */
    public const SIDE_LOCAL = 'local';

    /** The version the stored remote operation carries won. */
    public const SIDE_REMOTE = 'remote';

    public function __construct(
        private readonly NodeOperationReceiver $receiver,
        private readonly NodeSetupService $nodes,
        private readonly EventAuthority $authority,
        private readonly AuditService $audit,
    ) {}

    /**
     * Resolve a conflict in favour of the on-site node's version.
     *
     * @throws SyncConflictResolutionException
     */
    public function acceptOnsite(SyncConflict $conflict, User $reviewer): SyncConflict
    {
        return $this->resolve($conflict, SyncConflict::RESOLUTION_ACCEPT_ONSITE, $reviewer);
    }

    /**
     * Resolve a conflict in favour of the central node's version.
     *
     * @throws SyncConflictResolutionException
     */
    public function acceptCentral(SyncConflict $conflict, User $reviewer): SyncConflict
    {
        return $this->resolve($conflict, SyncConflict::RESOLUTION_ACCEPT_CENTRAL, $reviewer);
    }

    /**
     * Record a review decision and carry it out.
     *
     * @throws SyncConflictResolutionException
     */
    public function resolve(SyncConflict $conflict, string $resolution, User $reviewer): SyncConflict
    {
        if (! in_array($resolution, SyncConflict::RESOLUTIONS, true)) {
            throw SyncConflictResolutionException::unknownResolution($resolution);
        }

        if (! $conflict->isOpen()) {
            throw SyncConflictResolutionException::alreadyResolved($conflict);
        }

        if ($conflict->isDeviceWrite()) {
            return $this->resolveDeviceWrite($conflict, $resolution, $reviewer);
        }

        $operation = $conflict->operation()->first();

        if (! $operation instanceof NodeOperation) {
            throw SyncConflictResolutionException::unknownOriginNode($conflict);
        }

        $acceptedSide = $this->sideFor($conflict, $resolution, $operation);

        // Applying runs first and outside the resolution transaction. A
        // resolution that claimed the disagreement was settled while local state
        // never moved would be worse than no resolution at all, so the conflict
        // is only closed once the accepted version is actually in place.
        if ($acceptedSide === self::SIDE_REMOTE) {
            $operation = $this->receiver->applyResolvedConflict($operation);

            if (! $operation->isApplied()) {
                throw SyncConflictResolutionException::notApplied($operation);
            }
        }

        return DB::transaction(function () use ($conflict, $operation, $resolution, $acceptedSide, $reviewer): SyncConflict {
            $before = [
                'status' => $conflict->status,
                'resolution' => $conflict->resolution,
                'operation_status' => $operation->status,
            ];

            $conflict->forceFill([
                'status' => SyncConflict::STATUS_RESOLVED,
                'resolution' => $resolution,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ])->save();

            if ($acceptedSide === self::SIDE_LOCAL) {
                // The operation keeps its `conflicted` status because that is
                // what happened to it: it was received, it disagreed with local
                // state, and it was never applied. Only the reason is rewritten,
                // so the operation log says why it stopped there.
                $operation->forceFill([
                    'failure_reason' => sprintf(
                        'Superseded by God-mode conflict resolution "%s"; the local version was kept.',
                        $conflict->resolutionLabel(),
                    ),
                ])->save();
            }

            $this->audit->recordForEntity(
                entity: $conflict,
                action: self::AUDIT_RESOLVED,
                actorUser: $reviewer,
                eventId: $operation->event_id,
                before: $before,
                after: [
                    'status' => SyncConflict::STATUS_RESOLVED,
                    'resolution' => $resolution,
                    'accepted_side' => $acceptedSide,
                    'operation_uuid' => (string) $operation->uuid,
                    'operation_status' => $operation->status,
                    'entity_type' => $conflict->entity_type,
                    'entity_id' => $conflict->entity_id,
                    'accepted_value' => $acceptedSide === self::SIDE_LOCAL
                        ? $conflict->local_value_json
                        : $conflict->remote_value_json,
                ],
                reason: sprintf(
                    'Sync conflict on %s resolved as "%s"; the %s version was kept.',
                    (string) $conflict->entity_type,
                    $conflict->resolutionLabel(),
                    $acceptedSide,
                ),
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );

            return $conflict->refresh();
        });
    }

    /**
     * Resolve a refused device write (MOD-017; technical spec 15A.8).
     *
     * The two choices are the same two, and only one of them is available.
     * Accepting central keeps what this node holds, which for this kind of
     * conflict is the organization's own module configuration: the write is not
     * applied and the row is closed with a named reviewer against it. Accepting
     * on-site would mean applying the write, and the module that owns it is not
     * active — there is no operation to apply and applying one would write a
     * record for capability the organization has switched off.
     *
     * Nothing is deleted either way (MOD-022). The refusal stays on the queue as
     * history, and the work itself is still on the device, which is what makes
     * "activate the module and send again" a real answer rather than a
     * consolation.
     *
     * @throws SyncConflictResolutionException
     */
    private function resolveDeviceWrite(
        SyncConflict $conflict,
        string $resolution,
        User $reviewer,
    ): SyncConflict {
        if ($resolution !== SyncConflict::RESOLUTION_ACCEPT_CENTRAL) {
            throw SyncConflictResolutionException::deviceWriteNotApplicable($conflict);
        }

        return DB::transaction(function () use ($conflict, $resolution, $reviewer): SyncConflict {
            $before = [
                'status' => $conflict->status,
                'resolution' => $conflict->resolution,
            ];

            $conflict->forceFill([
                'status' => SyncConflict::STATUS_RESOLVED,
                'resolution' => $resolution,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->recordForEntity(
                entity: $conflict,
                action: self::AUDIT_RESOLVED,
                actorUser: $reviewer,
                /*
                 * The organization whose module configuration was kept, so the
                 * decision lands in that organization's own trail rather than
                 * only in the platform-wide one. It is read from the row the
                 * refusal wrote rather than re-derived, because the record the
                 * write was about was never created.
                 */
                organizationId: is_string($conflict->remote_value_json['organization_id'] ?? null)
                    ? $conflict->remote_value_json['organization_id']
                    : null,
                before: $before,
                after: [
                    'status' => SyncConflict::STATUS_RESOLVED,
                    'resolution' => $resolution,
                    'accepted_side' => self::SIDE_LOCAL,
                    'origin_operation_uuid' => $conflict->origin_operation_uuid,
                    'entity_type' => $conflict->entity_type,
                    'entity_id' => $conflict->entity_id,
                    'accepted_value' => $conflict->remote_value_json,
                ],
                reason: sprintf(
                    'Queued %s write refused for an inactive module was closed; the organization\'s module '
                    .'configuration was kept and the write was not applied.',
                    (string) $conflict->entity_type,
                ),
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );

            return $conflict->refresh();
        });
    }

    /**
     * The resolution technical spec 10.3 defaults to for this conflict.
     *
     * "Active event window + event-scoped records: accept on-site. Central/global
     * records: accept central." An operation that names an event is an
     * event-scoped record, and the window decides whether on-site holds
     * authority over it right now; everything else is central or global content
     * that central owns. This is a recommendation shown to the reviewer, not an
     * automatic resolution — technical spec 10.3 has a human choose.
     */
    public function defaultResolutionFor(SyncConflict $conflict): string
    {
        if ($conflict->isDeviceWrite()) {
            // The only resolution a refused device write has (MOD-017), so the
            // recommendation and the available choice are the same one.
            return SyncConflict::RESOLUTION_ACCEPT_CENTRAL;
        }

        $eventId = $conflict->operation()->first()?->event_id;

        if ($eventId === null) {
            return SyncConflict::RESOLUTION_ACCEPT_CENTRAL;
        }

        $event = Event::query()->find($eventId);

        if (! $event instanceof Event || ! $this->authority->isActive($event)) {
            return SyncConflict::RESOLUTION_ACCEPT_CENTRAL;
        }

        return SyncConflict::RESOLUTION_ACCEPT_ONSITE;
    }

    /**
     * Which of the two stored versions the chosen resolution names.
     *
     * @throws SyncConflictResolutionException
     */
    private function sideFor(SyncConflict $conflict, string $resolution, NodeOperation $operation): string
    {
        $localNode = $this->nodes->activeNode();

        if (! $localNode instanceof Node) {
            throw SyncConflictResolutionException::nodeNotConfigured();
        }

        $remoteNode = $operation->originNode()->first();

        if (! $remoteNode instanceof Node) {
            throw SyncConflictResolutionException::unknownOriginNode($conflict);
        }

        $localIsCentral = $localNode->isCentral();
        $remoteIsCentral = $remoteNode->isCentral();

        // One central node and one field node is the shape technical spec 10.1
        // describes, and it is the shape that makes "accept on-site" and "accept
        // central" name different versions. Anything else leaves both choices
        // pointing at the same place, which is a decision this resolver must not
        // make on a reviewer's behalf.
        if ($localIsCentral === $remoteIsCentral) {
            throw SyncConflictResolutionException::sidesNotDistinguishable(
                (string) $localNode->node_role,
                (string) $remoteNode->node_role,
            );
        }

        $centralSide = $localIsCentral ? self::SIDE_LOCAL : self::SIDE_REMOTE;
        $onsiteSide = $localIsCentral ? self::SIDE_REMOTE : self::SIDE_LOCAL;

        return $resolution === SyncConflict::RESOLUTION_ACCEPT_CENTRAL
            ? $centralSide
            : $onsiteSide;
    }
}
