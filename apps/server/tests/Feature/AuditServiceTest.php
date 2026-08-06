<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Device;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_persists_a_full_audit_event(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();
        $user = User::factory()->create();
        $device = Device::factory()->create();
        $node = Node::factory()->create();
        $entityId = (string) Str::uuid();

        $audit = app(AuditService::class)->record(
            action: 'organization.status.changed',
            entityType: 'staff_organization_status',
            entityId: $entityId,
            actorUser: $user,
            actorDevice: $device,
            actorNode: $node,
            organizationId: $organization->id,
            eventId: $event->id,
            departmentId: $department->id,
            before: ['status' => 'prospective'],
            after: ['status' => 'active'],
            reason: 'Promoted after department assignment.',
            sourceContext: AuditEvent::SOURCE_ORCHID,
            signatureMetadata: ['algo' => 'ed25519'],
        );

        $this->assertDatabaseHas('audit_events', [
            'id' => $audit->id,
            'action' => 'organization.status.changed',
            'entity_type' => 'staff_organization_status',
            'entity_id' => $entityId,
            'actor_user_id' => $user->id,
            'actor_device_id' => $device->id,
            'actor_node_id' => $node->id,
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'department_id' => $department->id,
            'reason' => 'Promoted after department assignment.',
            'source_context' => AuditEvent::SOURCE_ORCHID,
        ]);

        $audit->refresh();

        $this->assertSame(['status' => 'prospective'], $audit->before_json);
        $this->assertSame(['status' => 'active'], $audit->after_json);
        $this->assertSame(['algo' => 'ed25519'], $audit->signature_metadata_json);
        $this->assertNotNull($audit->created_at);
    }

    public function test_record_defaults_optional_fields_to_null_and_system_source(): void
    {
        $audit = app(AuditService::class)->record(
            action: 'permission.role.granted',
            entityType: 'team_grant',
            entityId: (string) Str::uuid(),
        );

        $this->assertNull($audit->actor_user_id);
        $this->assertNull($audit->actor_device_id);
        $this->assertNull($audit->actor_node_id);
        $this->assertNull($audit->organization_id);
        $this->assertNull($audit->event_id);
        $this->assertNull($audit->department_id);
        $this->assertNull($audit->before_json);
        $this->assertNull($audit->after_json);
        $this->assertNull($audit->reason);
        $this->assertNull($audit->signature_metadata_json);
        $this->assertSame(AuditEvent::SOURCE_SYSTEM, $audit->source_context);
    }

    public function test_record_for_entity_derives_entity_type_and_id_from_model(): void
    {
        $organization = Organization::factory()->create();

        $audit = app(AuditService::class)->recordForEntity(
            entity: $organization,
            action: 'organization.archived',
            organizationId: $organization->id,
        );

        $this->assertSame($organization->getMorphClass(), $audit->entity_type);
        $this->assertSame((string) $organization->getKey(), $audit->entity_id);
        $this->assertSame($organization->id, $audit->organization_id);
    }

    public function test_relationships_resolve_actor_and_scope(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $audit = app(AuditService::class)->record(
            action: 'organization.archived',
            entityType: 'organization',
            entityId: $organization->id,
            actorUser: $user,
            organizationId: $organization->id,
        );

        $audit->refresh();

        $this->assertTrue($audit->actorUser->is($user));
        $this->assertTrue($audit->organization->is($organization));
    }

    public function test_for_entity_scope_filters_by_type_and_id(): void
    {
        $service = app(AuditService::class);

        $incidentId = (string) Str::uuid();
        $otherIncidentId = (string) Str::uuid();
        $fieldReportId = (string) Str::uuid();

        $service->record(action: 'a', entityType: 'incident', entityId: $incidentId);
        $service->record(action: 'b', entityType: 'incident', entityId: $incidentId);
        $service->record(action: 'c', entityType: 'incident', entityId: $otherIncidentId);
        $service->record(action: 'd', entityType: 'field_report', entityId: $fieldReportId);

        $this->assertSame(2, AuditEvent::query()->forEntity('incident', $incidentId)->count());
    }
}
