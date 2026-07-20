<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IncidentReadHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_ic_viewer_can_list_event_incidents_only(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_viewer', $event);
        $older = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000001',
            'title' => 'Older event incident',
            'updated_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);
        $newer = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000002',
            'title' => 'Newer event incident',
            'updated_at' => Carbon::parse('2027-07-04T21:00:00Z'),
        ]);
        Incident::factory()->forEvent($otherEvent)->create([
            'incident_number' => 'INC-2027-000999',
            'title' => 'Other event incident',
        ]);

        $this->actingAs($actor)
            ->getJson("/api/events/{$event->id}/incidents")
            ->assertOk()
            ->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('incidents.0.id', $newer->id)
            ->assertJsonPath('incidents.0.priority_label', Incident::PRIORITY_ROUTINE)
            ->assertJsonPath('incidents.1.id', $older->id)
            ->assertJsonCount(2, 'incidents')
            ->assertJsonMissing(['title' => 'Other event incident']);
    }

    public function test_ic_operator_can_open_incident_detail_and_view_is_audited(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000003',
            'status' => Incident::STATUS_MONITORING,
            'priority_label' => Incident::PRIORITY_SERIOUS,
            'title' => 'Radio check at Gate A',
            'location_name' => 'Gate A',
            'location_details' => 'North side of entry.',
            'created_by_user_id' => $actor->id,
        ]);
        $type = IncidentType::factory()->create([
            'organization_id' => $event->organization_id,
            'name' => 'Radio',
        ]);
        $incident->incidentTypes()->attach($type->id, [
            'id' => '11111111-1111-4111-8111-111111111111',
            'created_at' => Carbon::parse('2027-07-04T20:20:00Z'),
        ]);
        $responder = Staff::factory()->create(['preferred_name' => 'Vera']);
        IncidentStaff::factory()->create([
            'incident_id' => $incident->id,
            'staff_id' => $responder->id,
            'created_at' => Carbon::parse('2027-07-04T20:21:00Z'),
        ]);
        $opened = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_INCIDENT_OPENED,
            'body' => null,
            'created_at' => Carbon::parse('2027-07-04T20:18:00Z'),
        ]);
        $note = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => 'Radio relay established.',
            'created_at' => Carbon::parse('2027-07-04T20:30:00Z'),
        ]);

        $this->actingAs($actor)
            ->getJson("/api/events/{$event->id}/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('incident.id', $incident->id)
            ->assertJsonPath('incident.incident_number', 'INC-2027-000003')
            ->assertJsonPath('incident.status', Incident::STATUS_MONITORING)
            ->assertJsonPath('incident.priority_label', Incident::PRIORITY_SERIOUS)
            ->assertJsonPath('incident.incident_type_names.0', 'Radio')
            ->assertJsonPath('incident.responders.0.staff_id', $responder->id)
            ->assertJsonPath('incident.responders.0.display_name', 'Vera')
            ->assertJsonPath('incident.title', 'Radio check at Gate A')
            ->assertJsonPath('incident.location_name', 'Gate A')
            ->assertJsonPath('incident.location_details', 'North side of entry.')
            ->assertJsonPath('incident.created_by_user_id', $actor->id)
            ->assertJsonPath('incident.created_by_name', $actor->name)
            ->assertJsonPath('incident.timeline_entries.0.id', $opened->id)
            ->assertJsonPath('incident.timeline_entries.0.entry_type', IncidentTimelineEntry::TYPE_INCIDENT_OPENED)
            ->assertJsonPath('incident.timeline_entries.0.actor_name', $actor->name)
            ->assertJsonPath('incident.timeline_entries.1.id', $note->id)
            ->assertJsonPath('incident.timeline_entries.1.entry_type', IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE)
            ->assertJsonPath('incident.timeline_entries.1.body', 'Radio relay established.');

        $audit = AuditEvent::query()->where('action', 'incident.viewed')->sole();
        $this->assertSame($incident->id, $audit->entity_id);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($event->organization_id, $audit->organization_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($event->ic_department_id, $audit->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
    }

    public function test_ic_lead_can_open_incident_detail(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();

        $this->actingAs($actor)
            ->getJson("/api/events/{$event->id}/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('incident.id', $incident->id);
    }

    public function test_non_ic_roles_cannot_list_or_open_incidents(): void
    {
        foreach ([
            ['organizer', false],
            ['department_lead', false],
        ] as [$roleCode, $eventScoped]) {
            $event = $this->eventWithIncidentCommandDepartment();
            $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);
            $incident = Incident::factory()->forEvent($event)->create();

            $this->actingAs($actor)
                ->getJson("/api/events/{$event->id}/incidents")
                ->assertForbidden()
                ->assertJsonPath('message', 'This page requires Incident Command access for the event configured IC department.');

            $this->actingAs($actor)
                ->getJson("/api/events/{$event->id}/incidents/{$incident->id}")
                ->assertForbidden()
                ->assertJsonPath('message', 'This page requires Incident Command access for the event configured IC department.');
        }

        $this->assertDatabaseMissing('audit_events', ['action' => 'incident.viewed']);
    }

    public function test_wrong_event_or_revoked_ic_grant_cannot_read_incidents(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $wrongEventActor = $this->userWithEventRole('ic_viewer', $this->eventWithIncidentCommandDepartment());
        $revokedActor = $this->userWithEventRole('ic_operator', $event, revoked: true);
        $incident = Incident::factory()->forEvent($event)->create();

        foreach ([$wrongEventActor, $revokedActor] as $actor) {
            $this->actingAs($actor)
                ->getJson("/api/events/{$event->id}/incidents")
                ->assertForbidden();

            $this->actingAs($actor)
                ->getJson("/api/events/{$event->id}/incidents/{$incident->id}")
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('audit_events', ['action' => 'incident.viewed']);
    }

    public function test_unauthenticated_user_cannot_read_incidents(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create();

        $this->getJson("/api/events/{$event->id}/incidents")->assertUnauthorized();
        $this->getJson("/api/events/{$event->id}/incidents/{$incident->id}")->assertUnauthorized();
    }

    public function test_detail_route_fails_closed_for_incident_outside_event(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($otherEvent)->create();

        $this->actingAs($actor)
            ->getJson("/api/events/{$event->id}/incidents/{$incident->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_events', ['action' => 'incident.viewed']);
    }

    private function eventWithIncidentCommandDepartment(): Event
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        return Event::factory()->for($organization)->create([
            'ic_department_id' => $department->id,
            'starts_at' => Carbon::parse('2027-07-04 09:00:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
        ]);
    }

    private function userWithEventRole(string $roleCode, Event $event, bool $revoked = false): User
    {
        return $this->userWithRole($roleCode, $event, eventScoped: true, revoked: $revoked);
    }

    private function userWithRole(
        string $roleCode,
        Event $event,
        bool $eventScoped,
        bool $revoked = false,
    ): User {
        $department = $eventScoped && in_array($roleCode, ['ic_lead', 'ic_operator', 'ic_viewer'], true)
            ? Department::query()->findOrFail($event->ic_department_id)
            : Department::factory()->for($event->organization)->create();

        if (in_array($roleCode, ['organizer', 'lead_organizer'], true)) {
            $event->organization->forceFill(['organizers_department_id' => $department->id])->save();
        }

        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        $grantAttributes = [
            'team_id' => $team->id,
            'event_id' => $eventScoped ? $event->id : null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ];

        if ($revoked) {
            TeamGrant::factory()->revoked()->create($grantAttributes);
        } else {
            TeamGrant::factory()->create($grantAttributes);
        }

        return $user;
    }
}
