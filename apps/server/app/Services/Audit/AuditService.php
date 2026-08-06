<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable write path for system audit events (requirements 2.4, technical spec
 * section 23, data/API specification sections 8 and 14.1).
 *
 * This service is the single place audit-sensitive workflows should call to
 * persist an immutable {@see AuditEvent}. It captures the actor (user, device,
 * node), the affected entity, the organization/event/department scope, optional
 * before/after snapshots, an optional reason, the source context, and optional
 * signature metadata. Enforcement gates, Orchid surfaces, and sync of audit
 * records are delivered by their owning tasks.
 */
class AuditService
{
    public function __construct(private readonly AuditPolicy $policy) {}

    /**
     * Record an audit event for an explicit entity type and identifier.
     *
     * Returns null when the organization's configuration does not record this
     * action. Callers treat the return as informational — nothing in Meridian
     * branches on whether an audit row was written, and nothing should: an
     * operation succeeding or failing is not a question about the record of it.
     * {@see AuditPolicy} decides, and the floor it enforces means the entries
     * requirements 2.4 and data/API section 8 oblige are never the ones skipped.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $signatureMetadata
     */
    public function record(
        string $action,
        string $entityType,
        int|string $entityId,
        ?User $actorUser = null,
        ?Device $actorDevice = null,
        ?Node $actorNode = null,
        ?string $organizationId = null,
        ?string $eventId = null,
        ?string $departmentId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
        ?array $signatureMetadata = null,
    ): ?AuditEvent {
        if (! $this->policy->shouldRecord($organizationId, $action)) {
            return null;
        }

        return AuditEvent::query()->create([
            'organization_id' => $organizationId,
            'event_id' => $eventId,
            'department_id' => $departmentId,
            'actor_user_id' => $actorUser?->getKey(),
            'actor_device_id' => $actorDevice?->getKey(),
            'actor_node_id' => $actorNode?->getKey(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'before_json' => $before,
            'after_json' => $after,
            'reason' => $reason,
            'source_context' => $sourceContext,
            'signature_metadata_json' => $signatureMetadata,
        ]);
    }

    /**
     * Record an audit event whose entity type and identifier are derived from
     * an Eloquent model.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $signatureMetadata
     */
    public function recordForEntity(
        Model $entity,
        string $action,
        ?User $actorUser = null,
        ?Device $actorDevice = null,
        ?Node $actorNode = null,
        ?string $organizationId = null,
        ?string $eventId = null,
        ?string $departmentId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
        ?array $signatureMetadata = null,
    ): ?AuditEvent {
        return $this->record(
            action: $action,
            entityType: $entity->getMorphClass(),
            entityId: $entity->getKey(),
            actorUser: $actorUser,
            actorDevice: $actorDevice,
            actorNode: $actorNode,
            organizationId: $organizationId,
            eventId: $eventId,
            departmentId: $departmentId,
            before: $before,
            after: $after,
            reason: $reason,
            sourceContext: $sourceContext,
            signatureMetadata: $signatureMetadata,
        );
    }
}
