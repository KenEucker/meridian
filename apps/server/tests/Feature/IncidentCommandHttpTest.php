<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentTimelineEntry;
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

class IncidentCommandHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ic_lead_can_create_incident_online(): void
    {
        Carbon::setTestNow('2027-07-04 20:30:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => '  Medical assist at Gate A  ',
                'started_at' => '2027-07-04T20:15:00Z',
                'location_name' => ' Gate A ',
                'location_address' => '',
                'location_details' => 'North side.',
            ])
            ->assertCreated()
            ->assertJsonPath('incident_number', 'INC-2027-000001')
            ->assertJsonPath('status', Incident::STATUS_OPEN)
            ->assertJsonPath('title', 'Medical assist at Gate A')
            ->assertJsonPath('location_name', 'Gate A')
            ->assertJsonPath('location_address', null)
            ->assertJsonPath('created_by_user_id', $actor->id);

        $incident = Incident::query()->sole();
        $audit = AuditEvent::query()->sole();
        $timelineEntry = IncidentTimelineEntry::query()->sole();

        $this->assertSame('incident.created', $audit->action);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($event->organization_id, $audit->organization_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($event->ic_department_id, $audit->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertSame($incident->id, $audit->entity_id);
        $this->assertSame('INC-2027-000001', $audit->after_json['incident_number']);
        $this->assertSame($incident->id, $timelineEntry->incident_id);
        $this->assertSame($actor->id, $timelineEntry->actor_user_id);
        $this->assertSame(IncidentTimelineEntry::TYPE_INCIDENT_OPENED, $timelineEntry->entry_type);
        $this->assertNull($timelineEntry->body);
    }

    public function test_ic_operator_can_create_incident_online(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => 'Operations assist',
            ])
            ->assertCreated()
            ->assertJsonPath('created_by_user_id', $actor->id);

        $this->assertDatabaseHas('incidents', [
            'event_id' => $event->id,
            'title' => 'Operations assist',
        ]);
    }

    public function test_ic_viewer_cannot_create_incident(): void
    {
        $this->assertRoleCannotCreateIncident('ic_viewer');
    }

    public function test_organizer_cannot_create_incident_without_ic_role(): void
    {
        $this->assertRoleCannotCreateIncident('organizer', eventScoped: false);
    }

    public function test_department_lead_outside_ic_cannot_create_incident(): void
    {
        $this->assertRoleCannotCreateIncident('department_lead', eventScoped: false);
    }

    public function test_wrong_event_ic_grant_cannot_create_incident(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $otherEvent);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => 'Wrong event attempt',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may create incidents.');

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revoked_ic_grant_cannot_create_incident(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event, revoked: true);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => 'Revoked attempt',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_incident_create_command_requires_authentication(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();

        $this->postJson('/api/commands/create-incident', [
            'event_id' => $event->id,
            'title' => 'Unauthenticated attempt',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_incident_create_validation_failure_creates_no_record(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The title field is required.');

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_ic_operator_can_append_incident_note_online(): void
    {
        Carbon::setTestNow('2027-07-04 21:15:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'updated_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/append-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'body' => '  Ranger arrived and is monitoring.  ',
            ])
            ->assertCreated()
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('actor_user_id', $actor->id)
            ->assertJsonPath('actor_name', $actor->name)
            ->assertJsonPath('entry_type', IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE)
            ->assertJsonPath('body', 'Ranger arrived and is monitoring.');

        $entry = IncidentTimelineEntry::query()->sole();
        $audit = AuditEvent::query()->where('action', 'incident.note_appended')->sole();

        $this->assertSame($incident->id, $entry->incident_id);
        $this->assertSame($actor->id, $entry->actor_user_id);
        $this->assertSame('Ranger arrived and is monitoring.', $entry->body);
        $this->assertSame($entry->id, $audit->entity_id);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($event->organization_id, $audit->organization_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($event->ic_department_id, $audit->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertSame($incident->id, $audit->after_json['incident_id']);
        $this->assertTrue($incident->refresh()->updated_at->equalTo(Carbon::parse('2027-07-04T21:15:00Z')));
    }

    public function test_ic_lead_can_append_incident_note_online(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/append-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'body' => 'Lead note.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('incident_timeline_entries', [
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => 'Lead note.',
        ]);
    }

    public function test_ic_viewer_cannot_append_incident_note(): void
    {
        $this->assertRoleCannotAppendIncidentNote('ic_viewer');
    }

    public function test_organizer_cannot_append_incident_note_without_ic_role(): void
    {
        $this->assertRoleCannotAppendIncidentNote('organizer', eventScoped: false);
    }

    public function test_department_lead_outside_ic_cannot_append_incident_note(): void
    {
        $this->assertRoleCannotAppendIncidentNote('department_lead', eventScoped: false);
    }

    public function test_wrong_event_ic_grant_cannot_append_incident_note(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $otherEvent);
        $incident = Incident::factory()->forEvent($event)->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/append-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'body' => 'Wrong event attempt.',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may add incident notes.');

        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revoked_ic_grant_cannot_append_incident_note(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event, revoked: true);
        $incident = Incident::factory()->forEvent($event)->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/append-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'body' => 'Revoked attempt.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_append_incident_note_rejects_wrong_event_incident_id(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($otherEvent)->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/append-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'body' => 'Wrong event incident.',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_append_incident_note_rejects_blank_body(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/append-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'body' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident note body is required.');

        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_append_incident_note_requires_authentication(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create();

        $this->postJson('/api/commands/append-incident-note', [
            'event_id' => $event->id,
            'incident_id' => $incident->id,
            'body' => 'Unauthenticated attempt.',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('incident_timeline_entries', 0);
    }

    private function assertRoleCannotCreateIncident(string $roleCode, bool $eventScoped = true): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => "{$roleCode} attempt",
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may create incidents.');

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function assertRoleCannotAppendIncidentNote(string $roleCode, bool $eventScoped = true): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);
        $incident = Incident::factory()->forEvent($event)->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/append-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'body' => "{$roleCode} note attempt.",
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may add incident notes.');

        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
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
