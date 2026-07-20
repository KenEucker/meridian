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
                'priority_label' => Incident::PRIORITY_SERIOUS,
                'started_at' => '2027-07-04T20:15:00Z',
                'location_name' => ' Gate A ',
                'location_address' => '',
                'location_details' => 'North side.',
            ])
            ->assertCreated()
            ->assertJsonPath('incident_number', 'INC-2027-000001')
            ->assertJsonPath('status', Incident::STATUS_OPEN)
            ->assertJsonPath('priority_label', Incident::PRIORITY_SERIOUS)
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
        $this->assertSame(Incident::PRIORITY_SERIOUS, $audit->after_json['priority_label']);
        $this->assertSame($incident->id, $timelineEntry->incident_id);
        $this->assertSame($actor->id, $timelineEntry->actor_user_id);
        $this->assertSame(IncidentTimelineEntry::TYPE_INCIDENT_OPENED, $timelineEntry->entry_type);
        $this->assertSame('Incident INC-2027-000001 opened.', $timelineEntry->body);
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

    public function test_ic_lead_can_create_incident_with_priority_types_and_responders(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $responder = Staff::factory()->create([
            'preferred_name' => 'Vera',
            'handle' => 'vera-ranger',
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => 'Responder dispatch',
                'priority_label' => Incident::PRIORITY_CRITICAL,
                'incident_type_names' => ['Medical', 'Safety', 'Medical'],
                'responder_staff_ids' => [$responder->id],
            ])
            ->assertCreated()
            ->assertJsonPath('priority_label', Incident::PRIORITY_CRITICAL)
            ->assertJsonPath('incident_type_names.0', 'Medical')
            ->assertJsonPath('incident_type_names.1', 'Safety')
            ->assertJsonCount(2, 'incident_type_names')
            ->assertJsonPath('responders.0.staff_id', $responder->id)
            ->assertJsonPath('responders.0.display_name', 'Vera')
            ->assertJsonPath('responders.0.relationship_label', 'Responder');

        $incident = Incident::query()->with(['incidentTypes', 'incidentStaff.staff'])->sole();

        $this->assertSame(Incident::PRIORITY_CRITICAL, $incident->priority_label);
        $this->assertSame(['Medical', 'Safety'], $incident->incidentTypes->pluck('name')->all());
        $this->assertSame($responder->id, $incident->incidentStaff->sole()->staff_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'incident.created',
            'entity_id' => $incident->id,
        ]);
    }

    public function test_ic_operator_can_autosave_edit_closed_incident_fields_online(): void
    {
        Carbon::setTestNow('2027-07-04 22:45:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'status' => Incident::STATUS_CLOSED,
            'closed_at' => Carbon::parse('2027-07-04T21:00:00Z'),
            'started_at' => Carbon::parse('2027-07-04T20:00:00Z'),
            'title' => 'Original closed title',
            'location_name' => 'Old location',
            'updated_at' => Carbon::parse('2027-07-04T21:00:00Z'),
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'title' => '  Updated closed title  ',
                'priority_label' => Incident::PRIORITY_IMPORTANT,
                'location_name' => ' Ranger HQ ',
                'location_address' => '',
                'location_details' => 'Updated details.',
            ])
            ->assertOk()
            ->assertJsonPath('id', $incident->id)
            ->assertJsonPath('status', Incident::STATUS_CLOSED)
            ->assertJsonPath('priority_label', Incident::PRIORITY_IMPORTANT)
            ->assertJsonPath('title', 'Updated closed title')
            ->assertJsonPath('location_name', 'Ranger HQ')
            ->assertJsonPath('location_address', null)
            ->assertJsonPath('location_details', 'Updated details.');

        $incident->refresh();
        $entry = IncidentTimelineEntry::query()->sole();
        $audit = AuditEvent::query()->where('action', 'incident.updated')->sole();

        $this->assertSame(Incident::STATUS_CLOSED, $incident->status);
        $this->assertTrue($incident->closed_at->equalTo(Carbon::parse('2027-07-04T21:00:00Z')));
        $this->assertTrue($incident->started_at->equalTo(Carbon::parse('2027-07-04T20:00:00Z')));
        $this->assertTrue($incident->updated_at->equalTo(Carbon::parse('2027-07-04T22:45:00Z')));
        $this->assertSame(IncidentTimelineEntry::TYPE_FIELD_UPDATED, $entry->entry_type);
        $this->assertSame('Original closed title', $entry->previous_value['title']);
        $this->assertSame('Updated closed title', $entry->new_value['title']);
        $this->assertStringContainsString(
            'Changed title: Updated closed title',
            $entry->body,
        );
        $this->assertStringContainsString('Changed location name: Ranger HQ', $entry->body);
        $this->assertStringContainsString('Changed priority: Important', $entry->body);
        $this->assertStringNotContainsString('Updated at', $entry->body);
        $this->assertStringNotContainsString('field changed from', $entry->body);
        $this->assertStringNotContainsString('Changed started', $entry->body);
        $this->assertSame($incident->id, $audit->entity_id);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($event->organization_id, $audit->organization_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($event->ic_department_id, $audit->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertSame('Original closed title', $audit->before_json['title']);
        $this->assertSame('Updated closed title', $audit->after_json['title']);
        $this->assertSame(Incident::PRIORITY_ROUTINE, $audit->before_json['priority_label']);
        $this->assertSame(Incident::PRIORITY_IMPORTANT, $audit->after_json['priority_label']);
    }

    public function test_ic_operator_can_autosave_types_and_responders_with_history(): void
    {
        Carbon::setTestNow('2027-07-04 22:45:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $firstResponder = Staff::factory()->create(['preferred_name' => 'Vera']);
        $secondResponder = Staff::factory()->create(['preferred_name' => 'Omar']);
        $incident = Incident::factory()->forEvent($event)->create([
            'priority_label' => Incident::PRIORITY_ROUTINE,
            'updated_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'priority_label' => Incident::PRIORITY_SERIOUS,
                'incident_type_names' => ['Medical', 'Logistics'],
                'responder_staff_ids' => [$firstResponder->id, $secondResponder->id],
            ])
            ->assertOk()
            ->assertJsonPath('priority_label', Incident::PRIORITY_SERIOUS)
            ->assertJsonPath('incident_type_names.0', 'Medical')
            ->assertJsonPath('incident_type_names.1', 'Logistics')
            ->assertJsonPath('responders.0.display_name', 'Vera')
            ->assertJsonPath('responders.1.display_name', 'Omar');

        $incident->refresh()->load(['incidentTypes', 'incidentStaff.staff']);
        $entry = IncidentTimelineEntry::query()->sole();
        $audit = AuditEvent::query()->where('action', 'incident.updated')->sole();

        $this->assertSame(Incident::PRIORITY_SERIOUS, $incident->priority_label);
        $this->assertSame(['Medical', 'Logistics'], $incident->incidentTypes->pluck('name')->all());
        $this->assertSame([$firstResponder->id, $secondResponder->id], $incident->incidentStaff->pluck('staff_id')->all());
        $this->assertSame(IncidentTimelineEntry::TYPE_FIELD_UPDATED, $entry->entry_type);
        $this->assertStringContainsString('Changed priority: Serious', $entry->body);
        $this->assertStringContainsString('Changed incident types: Medical, Logistics', $entry->body);
        $this->assertStringContainsString('Changed responders: Vera, Omar', $entry->body);
        $this->assertSame([], $audit->before_json['incident_type_names']);
        $this->assertSame(['Medical', 'Logistics'], $audit->after_json['incident_type_names']);
        $this->assertSame('Vera', $audit->after_json['responders'][0]['display_name']);
    }

    public function test_incident_autosave_rejects_invalid_priority_and_responder_ids(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'priority_label' => Incident::PRIORITY_ROUTINE,
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'priority_label' => 'Attention',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident priority label is invalid.');

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'responder_staff_ids' => ['11111111-1111-4111-8111-111111111111'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident responder staff IDs are invalid.');

        $this->assertSame(Incident::PRIORITY_ROUTINE, $incident->refresh()->priority_label);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_ic_lead_can_autosave_status_change_and_reopen_without_retroactive_started_at_change(): void
    {
        Carbon::setTestNow('2027-07-04 23:00:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'status' => Incident::STATUS_CLOSED,
            'closed_at' => Carbon::parse('2027-07-04T22:00:00Z'),
            'started_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'status' => Incident::STATUS_ON_HOLD,
            ])
            ->assertOk()
            ->assertJsonPath('status', Incident::STATUS_ON_HOLD)
            ->assertJsonPath('closed_at', null);

        $incident->refresh();

        $this->assertSame(Incident::STATUS_ON_HOLD, $incident->status);
        $this->assertNull($incident->closed_at);
        $this->assertTrue($incident->started_at->equalTo(Carbon::parse('2027-07-04T20:00:00Z')));
        $this->assertDatabaseHas('incident_timeline_entries', [
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_FIELD_UPDATED,
        ]);
    }

    public function test_autosave_noop_writes_no_timeline_or_audit_entry(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'title' => 'Original title',
            'status' => Incident::STATUS_OPEN,
            'location_name' => null,
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'title' => 'Original title',
                'status' => Incident::STATUS_OPEN,
                'location_name' => '',
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Original title');

        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_ic_viewer_cannot_create_incident(): void
    {
        $this->assertRoleCannotCreateIncident('ic_viewer');
    }

    public function test_ic_viewer_cannot_autosave_incident_edit(): void
    {
        $this->assertRoleCannotUpdateIncident('ic_viewer');
    }

    public function test_organizer_cannot_autosave_incident_edit_without_ic_role(): void
    {
        $this->assertRoleCannotUpdateIncident('organizer', eventScoped: false);
    }

    public function test_department_lead_outside_ic_cannot_autosave_incident_edit(): void
    {
        $this->assertRoleCannotUpdateIncident('department_lead', eventScoped: false);
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

    public function test_wrong_event_ic_grant_cannot_autosave_incident_edit(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $otherEvent);
        $incident = Incident::factory()->forEvent($event)->create([
            'title' => 'Original title',
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'title' => 'Wrong event attempt',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may edit incidents.');

        $this->assertSame('Original title', $incident->refresh()->title);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revoked_ic_grant_cannot_autosave_incident_edit(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event, revoked: true);
        $incident = Incident::factory()->forEvent($event)->create([
            'title' => 'Original title',
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'title' => 'Revoked attempt',
            ])
            ->assertForbidden();

        $this->assertSame('Original title', $incident->refresh()->title);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
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

    public function test_incident_update_command_requires_authentication(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create();

        $this->postJson('/api/commands/update-incident', [
            'event_id' => $event->id,
            'incident_id' => $incident->id,
            'title' => 'Unauthenticated attempt',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('incident_timeline_entries', 0);
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

    public function test_incident_update_rejects_blank_title_and_wrong_event_incident_id(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'title' => 'Original title',
        ]);
        $wrongEventIncident = Incident::factory()->forEvent($otherEvent)->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'title' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident title is required.');

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $wrongEventIncident->id,
                'title' => 'Wrong event incident',
            ])
            ->assertNotFound();

        $this->assertSame('Original title', $incident->refresh()->title);
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

    private function assertRoleCannotUpdateIncident(string $roleCode, bool $eventScoped = true): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);
        $incident = Incident::factory()->forEvent($event)->create([
            'title' => 'Original title',
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'title' => "{$roleCode} edit attempt",
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may edit incidents.');

        $this->assertSame('Original title', $incident->refresh()->title);
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
