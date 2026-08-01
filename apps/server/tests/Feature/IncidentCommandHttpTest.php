<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentLink;
use App\Models\IncidentTimelineEntry;
use App\Models\IncidentType;
use App\Models\NameReferenceToken;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\NameReferences\NameReferenceIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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
        // The organization configures its incident types (M18.14A); a command
        // chooses among them and no longer creates one by naming it.
        $this->configureTypes($event, 'Medical', 'Safety');

        $this->actingAsClient($actor)
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

    public function test_incident_create_records_opening_then_initial_field_change_timeline_entries(): void
    {
        Carbon::setTestNow('2027-07-04 20:30:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'priority_label' => Incident::PRIORITY_IMPORTANT,
                'initial_field_update_fields' => ['priority_label'],
            ])
            ->assertCreated()
            ->assertJsonPath('priority_label', Incident::PRIORITY_IMPORTANT);

        $incident = Incident::query()->sole();
        $timelineEntries = IncidentTimelineEntry::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $timelineEntries);
        $this->assertSame(IncidentTimelineEntry::TYPE_INCIDENT_OPENED, $timelineEntries[0]->entry_type);
        $this->assertSame('Incident INC-2027-000001 opened.', $timelineEntries[0]->body);
        $this->assertSame(IncidentTimelineEntry::TYPE_FIELD_UPDATED, $timelineEntries[1]->entry_type);
        $this->assertSame('Changed priority: Important', $timelineEntries[1]->body);
        $this->assertSame(Incident::PRIORITY_ROUTINE, $timelineEntries[1]->previous_value['priority_label']);
        $this->assertSame(Incident::PRIORITY_IMPORTANT, $timelineEntries[1]->new_value['priority_label']);
        $this->assertSame($incident->id, $timelineEntries[1]->incident_id);
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

        $this->actingAsClient($actor)
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
        $this->configureTypes($event, 'Medical', 'Logistics');

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'priority_label' => 'Attention',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident priority label is invalid.');

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

    public function test_incident_create_allows_blank_title(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => '   ',
            ])
            ->assertCreated()
            ->assertJsonPath('title', '');

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_timeline_entries', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_incident_update_allows_blank_title_and_rejects_wrong_event_incident_id(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'title' => 'Original title',
        ]);
        $wrongEventIncident = Incident::factory()->forEvent($otherEvent)->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'title' => '   ',
            ])
            ->assertOk()
            ->assertJsonPath('title', '');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $wrongEventIncident->id,
                'title' => 'Wrong event incident',
            ])
            ->assertNotFound();

        $this->assertSame('', $incident->refresh()->title);
        $this->assertDatabaseCount('incident_timeline_entries', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_ic_operator_can_append_incident_note_online(): void
    {
        Carbon::setTestNow('2027-07-04 21:15:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'updated_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

    public function test_ic_operator_can_strike_operational_note_without_deleting_it(): void
    {
        Carbon::setTestNow('2027-07-04 21:45:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'updated_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);
        $entry = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => 'Wrong note with @HiddenName.',
            'created_at' => Carbon::parse('2027-07-04T21:15:00Z'),
        ]);
        app(NameReferenceIndexService::class)->synchronizeIncidentTimelineEntry($entry);

        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_INCIDENT_TIMELINE_ENTRY,
            'source_id' => $entry->id,
            'normalized_token' => 'hiddenname',
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'timeline_entry_id' => $entry->id,
                'reason' => 'Note belonged to a different incident.',
            ])
            ->assertOk()
            ->assertJsonPath('id', $entry->id)
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('entry_type', IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE)
            ->assertJsonPath('body', 'Wrong note with @HiddenName.')
            ->assertJsonPath('stricken_reason', 'Note belonged to a different incident.');

        $entry->refresh();
        $audit = AuditEvent::query()->where('action', 'incident.note_stricken')->sole();

        $this->assertNotNull($entry->stricken_at);
        $this->assertSame('Wrong note with @HiddenName.', $entry->body);
        $this->assertSame('Note belonged to a different incident.', $entry->stricken_reason);
        $this->assertSame($entry->id, $audit->entity_id);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($event->organization_id, $audit->organization_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($event->ic_department_id, $audit->department_id);
        $this->assertSame('Note belonged to a different incident.', $audit->reason);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertNull($audit->before_json['stricken_at']);
        $this->assertNotNull($audit->after_json['stricken_at']);
        $this->assertSame('Wrong note with @HiddenName.', $audit->after_json['body']);
        $this->assertTrue($incident->refresh()->updated_at->equalTo(Carbon::parse('2027-07-04T21:45:00Z')));
        $this->assertDatabaseMissing('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_INCIDENT_TIMELINE_ENTRY,
            'source_id' => $entry->id,
            'normalized_token' => 'hiddenname',
        ]);
    }

    public function test_strike_incident_note_rejects_duplicate_non_note_and_other_incident_entries(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();
        $otherIncident = Incident::factory()->forEvent($event)->create();
        $strickenNote = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'stricken_at' => Carbon::parse('2027-07-04T21:15:00Z'),
            'stricken_reason' => 'Already handled.',
        ]);
        $fieldUpdate = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'entry_type' => IncidentTimelineEntry::TYPE_FIELD_UPDATED,
            'body' => 'Changed title: Gate A.',
        ]);
        $otherIncidentNote = IncidentTimelineEntry::factory()->forIncident($otherIncident)->create([
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'timeline_entry_id' => $strickenNote->id,
                'reason' => 'Already handled.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident note is already stricken.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'timeline_entry_id' => $fieldUpdate->id,
                'reason' => 'Not a note.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only operational notes may be stricken.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'timeline_entry_id' => $otherIncidentNote->id,
                'reason' => 'Wrong incident.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident note must belong to this incident.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'timeline_entry_id' => IncidentTimelineEntry::factory()->forIncident($incident)->create()->id,
                'reason' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident note strike reason is required.');

        $this->assertDatabaseCount('audit_events', 0);
        $this->assertNull($fieldUpdate->refresh()->stricken_at);
        $this->assertNull($otherIncidentNote->refresh()->stricken_at);
    }

    public function test_ic_viewer_and_non_ic_roles_cannot_strike_incident_notes(): void
    {
        $this->assertRoleCannotStrikeIncidentNote('ic_viewer');
        $this->assertRoleCannotStrikeIncidentNote('organizer', eventScoped: false);
        $this->assertRoleCannotStrikeIncidentNote('department_lead', eventScoped: false);
    }

    public function test_wrong_event_or_revoked_ic_grant_cannot_strike_incident_notes(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $wrongEventActor = $this->userWithEventRole('ic_operator', $otherEvent);
        $revokedActor = $this->userWithEventRole('ic_lead', $event, revoked: true);
        $incident = Incident::factory()->forEvent($event)->create();
        $entry = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
        ]);

        foreach ([$wrongEventActor, $revokedActor] as $actor) {
            $this->actingAsClient($actor)
                ->postJson('/api/commands/strike-incident-note', [
                    'event_id' => $event->id,
                    'incident_id' => $incident->id,
                    'timeline_entry_id' => $entry->id,
                    'reason' => 'No authority.',
                ])
                ->assertForbidden()
                ->assertJsonPath('message', 'Only IC operators and IC leads for this event may strike incident notes.');
        }

        $this->assertNull($entry->refresh()->stricken_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_strike_incident_note_requires_authentication(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create();
        $entry = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
        ]);

        $this->postJson('/api/commands/strike-incident-note', [
            'event_id' => $event->id,
            'incident_id' => $incident->id,
            'timeline_entry_id' => $entry->id,
            'reason' => 'Unauthenticated attempt.',
        ])->assertUnauthorized();

        $this->assertNull($entry->refresh()->stricken_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_ic_operator_can_link_and_unlink_same_event_incidents_with_history(): void
    {
        Carbon::setTestNow('2027-07-04 23:15:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000010',
            'title' => 'Gate A medical',
            'updated_at' => Carbon::parse('2027-07-04T22:00:00Z'),
        ]);
        $target = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000011',
            'title' => 'Radio relay',
            'updated_at' => Carbon::parse('2027-07-04T22:05:00Z'),
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $target->id,
            ])
            ->assertCreated()
            ->assertJsonPath('source_incident_id', $incident->id)
            ->assertJsonPath('target_incident_id', $target->id)
            ->assertJsonPath('link_type', IncidentLink::TYPE_RELATED)
            ->assertJsonPath('linked_incidents.0.id', $target->id)
            ->assertJsonPath('linked_incidents.0.incident_number', 'INC-2027-000011');

        $link = IncidentLink::query()->sole();
        $this->assertNull($link->unlinked_at);
        $this->assertSame($actor->id, $link->created_by_user_id);
        $this->assertDatabaseHas('incident_timeline_entries', [
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_INCIDENT_LINKED,
            'body' => 'Linked related incident INC-2027-000011: Radio relay.',
        ]);
        $this->assertDatabaseHas('incident_timeline_entries', [
            'incident_id' => $target->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_INCIDENT_LINKED,
            'body' => 'Linked related incident INC-2027-000010: Gate A medical.',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'incident.linked',
            'entity_id' => $link->id,
            'actor_user_id' => $actor->id,
            'event_id' => $event->id,
            'department_id' => $event->ic_department_id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);
        $this->assertTrue($incident->refresh()->updated_at->equalTo(Carbon::parse('2027-07-04T23:15:00Z')));
        $this->assertTrue($target->refresh()->updated_at->equalTo(Carbon::parse('2027-07-04T23:15:00Z')));

        Carbon::setTestNow('2027-07-04 23:30:00 UTC');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/unlink-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $target->id,
            ])
            ->assertOk()
            ->assertJsonPath('id', $link->id)
            ->assertJsonPath('unlinked_by_user_id', $actor->id)
            ->assertJsonPath('linked_incidents', []);

        $this->assertDatabaseCount('incident_links', 1);
        $this->assertNotNull($link->refresh()->unlinked_at);
        $this->assertSame($actor->id, $link->unlinked_by_user_id);
        $this->assertDatabaseHas('incident_timeline_entries', [
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_INCIDENT_UNLINKED,
            'body' => 'Unlinked related incident INC-2027-000011: Radio relay.',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'incident.unlinked',
            'entity_id' => $link->id,
            'actor_user_id' => $actor->id,
            'event_id' => $event->id,
            'department_id' => $event->ic_department_id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);
    }

    public function test_link_incident_rejects_self_duplicate_reverse_duplicate_and_cross_event_links(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();
        $target = Incident::factory()->forEvent($event)->create();
        $otherEventIncident = Incident::factory()->forEvent($otherEvent)->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $incident->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'An incident cannot be linked to itself.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $target->id,
            ])
            ->assertCreated();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $target->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incidents are already linked.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-incident', [
                'event_id' => $event->id,
                'incident_id' => $target->id,
                'target_incident_id' => $incident->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incidents are already linked.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $otherEventIncident->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Linked incidents must belong to the same event.');

        $this->assertDatabaseCount('incident_links', 1);
    }

    public function test_unlink_incident_rejects_missing_and_cross_event_relationships(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();
        $target = Incident::factory()->forEvent($event)->create();
        $otherEventIncident = Incident::factory()->forEvent($otherEvent)->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/unlink-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $target->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incidents are not currently linked.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/unlink-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $otherEventIncident->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Linked incidents must belong to the same event.');

        $this->assertDatabaseCount('incident_links', 0);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_ic_viewer_and_non_ic_roles_cannot_link_incidents(): void
    {
        $this->assertRoleCannotLinkIncident('ic_viewer');
        $this->assertRoleCannotLinkIncident('organizer', eventScoped: false);
        $this->assertRoleCannotLinkIncident('department_lead', eventScoped: false);
    }

    public function test_wrong_event_or_revoked_ic_grant_cannot_link_incidents(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $wrongEventActor = $this->userWithEventRole('ic_operator', $otherEvent);
        $revokedActor = $this->userWithEventRole('ic_lead', $event, revoked: true);
        $incident = Incident::factory()->forEvent($event)->create();
        $target = Incident::factory()->forEvent($event)->create();

        foreach ([$wrongEventActor, $revokedActor] as $actor) {
            $this->actingAsClient($actor)
                ->postJson('/api/commands/link-incident', [
                    'event_id' => $event->id,
                    'incident_id' => $incident->id,
                    'target_incident_id' => $target->id,
                ])
                ->assertForbidden()
                ->assertJsonPath('message', 'Only IC operators and IC leads for this event may link incidents.');
        }

        $this->assertDatabaseCount('incident_links', 0);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_link_and_unlink_incident_commands_require_authentication(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create();
        $target = Incident::factory()->forEvent($event)->create();

        $payload = [
            'event_id' => $event->id,
            'incident_id' => $incident->id,
            'target_incident_id' => $target->id,
        ];

        $this->postJson('/api/commands/link-incident', $payload)->assertUnauthorized();
        $this->postJson('/api/commands/unlink-incident', $payload)->assertUnauthorized();

        $this->assertDatabaseCount('incident_links', 0);
    }

    public function test_ic_operator_can_link_and_unlink_field_report_with_copied_note_and_stricken_history(): void
    {
        Carbon::setTestNow('2027-07-04 23:45:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000020',
            'updated_at' => Carbon::parse('2027-07-04T22:00:00Z'),
        ]);
        $secondIncident = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000021',
        ]);
        $fieldReportAuthor = Staff::factory()->create([
            'preferred_name' => 'Vera',
            'legal_name' => 'Vera Ranger',
        ]);
        $report = FieldReport::factory()
            ->forEvent($event)
            ->forAuthor(User::factory()->create(['name' => 'Vera User']), $fieldReportAuthor)
            ->receivedByServer()
            ->create([
                'fra_number' => 'FRA-2027-000123',
                'title' => 'Medical assist near Gate A',
                'body' => "Observed a medical assist near Gate A.\nRanger requested follow-up.",
            ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-field-report', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'field_report_id' => $report->id,
            ])
            ->assertCreated()
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('field_report_id', $report->id)
            ->assertJsonPath('linked_by_user_id', $actor->id)
            ->assertJsonPath('unlinked_at', null);

        $link = IncidentFieldReport::query()->where('incident_id', $incident->id)->sole();
        $copiedEntry = IncidentTimelineEntry::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', IncidentTimelineEntry::TYPE_FIELD_REPORT_LINKED)
            ->sole();

        $this->assertSame($actor->id, $link->linked_by_user_id);
        $this->assertNull($link->unlinked_at);
        $this->assertSame(
            "Field Report: Medical assist near Gate A\nAuthor: Vera\nObserved a medical assist near Gate A.\nRanger requested follow-up.",
            $copiedEntry->body,
        );
        $this->assertSame($link->id, $copiedEntry->new_value['incident_field_report_id']);
        $this->assertNull($copiedEntry->stricken_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'incident.field_report_linked',
            'entity_id' => $link->id,
            'actor_user_id' => $actor->id,
            'event_id' => $event->id,
            'department_id' => $event->ic_department_id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);
        $this->assertTrue($incident->refresh()->updated_at->equalTo(Carbon::parse('2027-07-04T23:45:00Z')));
        $this->assertDatabaseHas('field_reports', [
            'id' => $report->id,
            'title' => 'Medical assist near Gate A',
            'body' => "Observed a medical assist near Gate A.\nRanger requested follow-up.",
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-field-report', [
                'event_id' => $event->id,
                'incident_id' => $secondIncident->id,
                'field_report_id' => $report->id,
            ])
            ->assertCreated()
            ->assertJsonPath('incident_id', $secondIncident->id)
            ->assertJsonPath('field_report_id', $report->id);

        $this->assertDatabaseCount('incident_field_reports', 2);

        Carbon::setTestNow('2027-07-05 00:05:00 UTC');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/unlink-field-report', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'field_report_id' => $report->id,
            ])
            ->assertOk()
            ->assertJsonPath('id', $link->id)
            ->assertJsonPath('unlinked_by_user_id', $actor->id)
            ->assertJsonPath('stricken_reason', 'Field Report removed from incident.');

        $link->refresh();
        $copiedEntry->refresh();
        $unlinkEntry = IncidentTimelineEntry::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', IncidentTimelineEntry::TYPE_FIELD_REPORT_UNLINKED)
            ->sole();

        $this->assertNotNull($link->unlinked_at);
        $this->assertSame($actor->id, $link->unlinked_by_user_id);
        $this->assertSame('Field Report removed from incident.', $link->stricken_reason);
        $this->assertNotNull($copiedEntry->stricken_at);
        $this->assertSame('Field Report removed from incident.', $copiedEntry->stricken_reason);
        $this->assertSame('Removed Field Report FRA-2027-000123: Medical assist near Gate A.', $unlinkEntry->body);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'incident.field_report_unlinked',
            'entity_id' => $link->id,
            'actor_user_id' => $actor->id,
            'event_id' => $event->id,
            'department_id' => $event->ic_department_id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);
        $this->assertDatabaseHas('incident_field_reports', [
            'incident_id' => $secondIncident->id,
            'field_report_id' => $report->id,
            'unlinked_at' => null,
        ]);
    }

    public function test_link_field_report_rejects_duplicate_and_cross_event_links(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();
        $report = FieldReport::factory()->forEvent($event)->receivedByServer()->create();
        $otherEventReport = FieldReport::factory()->forEvent($otherEvent)->receivedByServer()->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-field-report', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'field_report_id' => $report->id,
            ])
            ->assertCreated();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-field-report', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'field_report_id' => $report->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Field Report is already linked to this incident.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-field-report', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'field_report_id' => $otherEventReport->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Field Report must belong to the same event as the incident.');

        $this->assertDatabaseCount('incident_field_reports', 1);
    }

    public function test_unlink_field_report_rejects_missing_and_cross_event_relationships(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();
        $report = FieldReport::factory()->forEvent($event)->receivedByServer()->create();
        $otherEventReport = FieldReport::factory()->forEvent($otherEvent)->receivedByServer()->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/unlink-field-report', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'field_report_id' => $report->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Field Report is not currently linked to this incident.');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/unlink-field-report', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'field_report_id' => $otherEventReport->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Field Report must belong to the same event as the incident.');

        $this->assertDatabaseCount('incident_field_reports', 0);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_ic_viewer_and_non_ic_roles_cannot_link_field_reports(): void
    {
        $this->assertRoleCannotLinkFieldReport('ic_viewer');
        $this->assertRoleCannotLinkFieldReport('organizer', eventScoped: false);
        $this->assertRoleCannotLinkFieldReport('department_lead', eventScoped: false);
    }

    public function test_wrong_event_or_revoked_ic_grant_cannot_link_field_reports(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $wrongEventActor = $this->userWithEventRole('ic_operator', $otherEvent);
        $revokedActor = $this->userWithEventRole('ic_lead', $event, revoked: true);
        $incident = Incident::factory()->forEvent($event)->create();
        $report = FieldReport::factory()->forEvent($event)->receivedByServer()->create();

        foreach ([$wrongEventActor, $revokedActor] as $actor) {
            $this->actingAsClient($actor)
                ->postJson('/api/commands/link-field-report', [
                    'event_id' => $event->id,
                    'incident_id' => $incident->id,
                    'field_report_id' => $report->id,
                ])
                ->assertForbidden()
                ->assertJsonPath('message', 'Only IC operators and IC leads for this event may link Field Reports.');
        }

        $this->assertDatabaseCount('incident_field_reports', 0);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_link_and_unlink_field_report_commands_require_authentication(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create();
        $report = FieldReport::factory()->forEvent($event)->receivedByServer()->create();

        $payload = [
            'event_id' => $event->id,
            'incident_id' => $incident->id,
            'field_report_id' => $report->id,
        ];

        $this->postJson('/api/commands/link-field-report', $payload)->assertUnauthorized();
        $this->postJson('/api/commands/unlink-field-report', $payload)->assertUnauthorized();

        $this->assertDatabaseCount('incident_field_reports', 0);
    }

    public function test_ic_operator_can_strike_incident_attachment_without_deleting_it(): void
    {
        Carbon::setTestNow('2027-07-05 01:15:00 UTC');
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'updated_at' => Carbon::parse('2027-07-05T00:45:00Z'),
        ]);
        $attachment = Attachment::factory()->forIncident($incident, $actor)->create([
            'filename' => 'INC-2027-000020_2027-07-05T01-00-00Z_01.webp',
            'storage_path' => 'incidents/INC-2027-000020_2027-07-05T01-00-00Z_01.webp',
            'checksum' => hash('sha256', 'incident-photo'),
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-attachment', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'attachment_id' => $attachment->id,
                'reason' => 'Duplicate image uploaded by mistake.',
            ])
            ->assertOk()
            ->assertJsonPath('id', $attachment->id)
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('filename', 'INC-2027-000020_2027-07-05T01-00-00Z_01.webp')
            ->assertJsonPath('deleted_at', null);

        $attachment->refresh();
        $timelineEntry = IncidentTimelineEntry::query()->sole();
        $audit = AuditEvent::query()->sole();

        $this->assertNotNull($attachment->stricken_at);
        $this->assertNull($attachment->deleted_at);
        $this->assertSame('incidents/INC-2027-000020_2027-07-05T01-00-00Z_01.webp', $attachment->storage_path);
        $this->assertSame(IncidentTimelineEntry::TYPE_ATTACHMENT_STRICKEN, $timelineEntry->entry_type);
        $this->assertSame('Struck incident attachment INC-2027-000020_2027-07-05T01-00-00Z_01.webp.', $timelineEntry->body);
        $this->assertSame('Duplicate image uploaded by mistake.', $timelineEntry->reason);
        $this->assertSame($attachment->id, $timelineEntry->new_value['attachment_id']);
        $this->assertSame('incident.attachment_stricken', $audit->action);
        $this->assertSame($attachment->id, $audit->entity_id);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($event->organization_id, $audit->organization_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($event->ic_department_id, $audit->department_id);
        $this->assertSame('Duplicate image uploaded by mistake.', $audit->reason);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertNull($audit->before_json['stricken_at']);
        $this->assertNotNull($audit->after_json['stricken_at']);
        $this->assertNull($audit->after_json['deleted_at']);
        $this->assertTrue($incident->refresh()->updated_at->equalTo(Carbon::parse('2027-07-05T01:15:00Z')));
    }

    public function test_strike_incident_attachment_rejects_duplicate_non_incident_and_other_incident_attachments(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();
        $otherIncident = Incident::factory()->forEvent($event)->create();
        $attachment = Attachment::factory()->forIncident($incident, $actor)->create([
            'stricken_at' => Carbon::parse('2027-07-05T01:00:00Z'),
        ]);
        $otherIncidentAttachment = Attachment::factory()->forIncident($otherIncident, $actor)->create();
        $fieldReport = FieldReport::factory()->forEvent($event)->receivedByServer()->create();
        $fieldReportPhoto = Attachment::factory()->forFieldReport($fieldReport)->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-attachment', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'attachment_id' => $attachment->id,
                'reason' => 'Already handled.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incident attachment is already stricken.');

        foreach ([$otherIncidentAttachment, $fieldReportPhoto] as $invalidAttachment) {
            $this->actingAsClient($actor)
                ->postJson('/api/commands/strike-incident-attachment', [
                    'event_id' => $event->id,
                    'incident_id' => $incident->id,
                    'attachment_id' => $invalidAttachment->id,
                    'reason' => 'Wrong attachment.',
                ])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Attachment must belong to this incident.');
        }

        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertNull($otherIncidentAttachment->refresh()->stricken_at);
        $this->assertNull($fieldReportPhoto->refresh()->stricken_at);
    }

    public function test_ic_viewer_and_non_ic_roles_cannot_strike_incident_attachments(): void
    {
        $this->assertRoleCannotStrikeAttachment('ic_viewer');
        $this->assertRoleCannotStrikeAttachment('organizer', eventScoped: false);
        $this->assertRoleCannotStrikeAttachment('department_lead', eventScoped: false);
    }

    public function test_wrong_event_or_revoked_ic_grant_cannot_strike_incident_attachments(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $wrongEventActor = $this->userWithEventRole('ic_operator', $otherEvent);
        $revokedActor = $this->userWithEventRole('ic_lead', $event, revoked: true);
        $incident = Incident::factory()->forEvent($event)->create();
        $attachment = Attachment::factory()->forIncident($incident)->create();

        foreach ([$wrongEventActor, $revokedActor] as $actor) {
            $this->actingAsClient($actor)
                ->postJson('/api/commands/strike-incident-attachment', [
                    'event_id' => $event->id,
                    'incident_id' => $incident->id,
                    'attachment_id' => $attachment->id,
                    'reason' => 'No authority.',
                ])
                ->assertForbidden()
                ->assertJsonPath('message', 'Only IC operators and IC leads for this event may strike incident attachments.');
        }

        $this->assertNull($attachment->refresh()->stricken_at);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_strike_incident_attachment_command_requires_authentication(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $incident = Incident::factory()->forEvent($event)->create();
        $attachment = Attachment::factory()->forIncident($incident)->create();

        $this->postJson('/api/commands/strike-incident-attachment', [
            'event_id' => $event->id,
            'incident_id' => $incident->id,
            'attachment_id' => $attachment->id,
            'reason' => 'Unauthenticated attempt.',
        ])->assertUnauthorized();

        $this->assertNull($attachment->refresh()->stricken_at);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function assertRoleCannotCreateIncident(string $roleCode, bool $eventScoped = true): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);

        $this->actingAsClient($actor)
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

        $this->actingAsClient($actor)
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

    private function assertRoleCannotStrikeIncidentNote(string $roleCode, bool $eventScoped = true): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);
        $incident = Incident::factory()->forEvent($event)->create();
        $entry = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-note', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'timeline_entry_id' => $entry->id,
                'reason' => "{$roleCode} strike attempt.",
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may strike incident notes.');

        $this->assertNull($entry->refresh()->stricken_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function assertRoleCannotLinkFieldReport(string $roleCode, bool $eventScoped = true): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);
        $incident = Incident::factory()->forEvent($event)->create();
        $report = FieldReport::factory()->forEvent($event)->receivedByServer()->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-field-report', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'field_report_id' => $report->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may link Field Reports.');

        $this->assertDatabaseCount('incident_field_reports', 0);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function assertRoleCannotStrikeAttachment(string $roleCode, bool $eventScoped = true): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);
        $incident = Incident::factory()->forEvent($event)->create();
        $attachment = Attachment::factory()->forIncident($incident)->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/strike-incident-attachment', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'attachment_id' => $attachment->id,
                'reason' => "{$roleCode} strike attempt.",
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may strike incident attachments.');

        $this->assertNull($attachment->refresh()->stricken_at);
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

        $this->actingAsClient($actor)
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

    private function assertRoleCannotLinkIncident(string $roleCode, bool $eventScoped = true): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithRole($roleCode, $event, eventScoped: $eventScoped);
        $incident = Incident::factory()->forEvent($event)->create();
        $target = Incident::factory()->forEvent($event)->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/link-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'target_incident_id' => $target->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only IC operators and IC leads for this event may link incidents.');

        $this->assertDatabaseCount('incident_links', 0);
        $this->assertDatabaseCount('incident_timeline_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_an_unconfigured_incident_type_name_is_refused_rather_than_created(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $this->configureTypes($event, 'Medical');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->id,
                'title' => 'Novel incident',
                'incident_type_names' => ['Medical', 'Avalanche'],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Avalanche is not a configured incident type for this organization. Incident types are configured by organizers.',
            );

        // The refusal is the point: naming a type must not bring it into
        // existence (M18.14A).
        $this->assertSame(
            0,
            IncidentType::query()
                ->where('organization_id', $event->organization_id)
                ->where('name', 'Avalanche')
                ->count(),
        );
        $this->assertSame(0, Incident::query()->where('title', 'Novel incident')->count());
    }

    public function test_an_archived_type_still_resolves_so_its_incidents_stay_editable(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create();

        $type = IncidentType::factory()->create([
            'organization_id' => $event->organization_id,
            'name' => 'Retired category',
            'archived_at' => Carbon::parse('2027-07-01T00:00:00Z'),
        ]);
        $incident->incidentTypes()->attach($type->id, [
            'id' => (string) Str::uuid(),
            'created_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);

        // The authoring form no longer offers this type, but the incident
        // already carries it and the update command sends the whole set.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-incident', [
                'event_id' => $event->id,
                'incident_id' => $incident->id,
                'title' => 'Still editable',
                'incident_type_names' => ['Retired category'],
            ])
            ->assertOk()
            ->assertJsonPath('incident_type_names.0', 'Retired category');
    }

    private function configureTypes(Event $event, string ...$names): void
    {
        foreach ($names as $name) {
            IncidentType::factory()->create([
                'organization_id' => $event->organization_id,
                'name' => $name,
            ]);
        }
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
