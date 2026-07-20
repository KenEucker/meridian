<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\FieldReportAppend;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\NameReferenceToken;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Incidents\IncidentTimelineService;
use App\Services\NameReferences\NameReferenceIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NameReferenceIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_note_indexes_name_references_without_changing_timeline_body(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $incident = Incident::factory()->forEvent($event)->create();

        $entry = app(IncidentTimelineService::class)->appendNote(
            incident: $incident,
            actor: $actor,
            body: '  Saw @Blue-Hat near @Gate_A with @blue-hat again.  ',
        );

        $this->assertSame(
            'Saw @Blue-Hat near @Gate_A with @blue-hat again.',
            $entry->fresh()->body,
        );
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_INCIDENT_TIMELINE_ENTRY,
            'source_id' => $entry->id,
            'incident_id' => $incident->id,
            'field_report_id' => null,
            'token' => 'Blue-Hat',
            'normalized_token' => 'blue-hat',
        ]);
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_INCIDENT_TIMELINE_ENTRY,
            'source_id' => $entry->id,
            'incident_id' => $incident->id,
            'normalized_token' => 'gate_a',
        ]);
    }

    public function test_rebuild_regenerates_incident_note_tokens_from_source_text(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $incident = Incident::factory()->forEvent($event)->create();
        $entry = app(IncidentTimelineService::class)->appendNote(
            incident: $incident,
            actor: $actor,
            body: 'Follow up with @Rebuild_Target.',
        );

        NameReferenceToken::query()->delete();
        $this->assertDatabaseCount('name_reference_tokens', 0);

        $count = app(NameReferenceIndexService::class)->rebuild();

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('name_reference_tokens', [
            'source_type' => NameReferenceToken::SOURCE_TYPE_INCIDENT_TIMELINE_ENTRY,
            'source_id' => $entry->id,
            'incident_id' => $incident->id,
            'normalized_token' => 'rebuild_target',
        ]);
    }

    public function test_incident_detail_payload_includes_name_reference_chips_for_ic_users(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'updated_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);

        app(IncidentTimelineService::class)->appendNote(
            incident: $incident,
            actor: $actor,
            body: 'Responder checked @Blue-Hat and @Gate_A.',
        );

        $this->actingAs($viewer)
            ->getJson("/api/events/{$event->id}/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('incident.name_reference_chips.0.token', 'Blue-Hat')
            ->assertJsonPath('incident.name_reference_chips.0.normalized_token', 'blue-hat')
            ->assertJsonPath('incident.name_reference_chips.1.token', 'Gate_A')
            ->assertJsonPath('incident.name_reference_chips.1.normalized_token', 'gate_a');
    }

    public function test_incident_chips_include_active_attached_field_report_tokens(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $incident = Incident::factory()->forEvent($event)->create();
        $report = FieldReport::factory()
            ->forEvent($event)
            ->receivedByServer()
            ->create([
                'body' => 'Report body saw @AttachedBody.',
            ]);
        $append = FieldReportAppend::factory()
            ->forReport($report)
            ->create([
                'body' => 'Append saw @AttachedAppend.',
            ]);
        $unlinkedReport = FieldReport::factory()
            ->forEvent($event)
            ->receivedByServer()
            ->create([
                'body' => 'Unlinked report saw @HiddenReport.',
            ]);

        app(NameReferenceIndexService::class)->synchronizeFieldReport($report);
        app(NameReferenceIndexService::class)->synchronizeAppend($append);
        app(NameReferenceIndexService::class)->synchronizeFieldReport($unlinkedReport);

        IncidentFieldReport::query()->create([
            'incident_id' => $incident->id,
            'field_report_id' => $report->id,
            'linked_by_user_id' => $actor->id,
            'linked_at' => Carbon::parse('2027-07-04T20:30:00Z'),
        ]);
        IncidentFieldReport::query()->create([
            'incident_id' => $incident->id,
            'field_report_id' => $unlinkedReport->id,
            'linked_by_user_id' => $actor->id,
            'linked_at' => Carbon::parse('2027-07-04T20:35:00Z'),
            'unlinked_by_user_id' => $actor->id,
            'unlinked_at' => Carbon::parse('2027-07-04T20:40:00Z'),
            'stricken_reason' => 'Wrong incident.',
        ]);

        $response = $this->actingAs($viewer)
            ->getJson("/api/events/{$event->id}/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonMissing(['token' => 'HiddenReport']);

        $this->assertEqualsCanonicalizing(
            ['AttachedBody', 'AttachedAppend'],
            collect($response->json('incident.name_reference_chips'))->pluck('token')->all(),
        );
    }

    public function test_incident_name_reference_search_is_permission_and_event_filtered(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $wrongEventViewer = $this->userWithEventRole('ic_viewer', $otherEvent);
        $matching = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000101',
            'title' => 'Matching incident',
            'updated_at' => Carbon::parse('2027-07-04T21:00:00Z'),
        ]);
        $unmatched = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000102',
            'title' => 'Unmatched incident',
            'updated_at' => Carbon::parse('2027-07-04T22:00:00Z'),
        ]);
        $otherEventIncident = Incident::factory()->forEvent($otherEvent)->create([
            'title' => 'Other event incident',
        ]);

        app(IncidentTimelineService::class)->appendNote(
            incident: $matching,
            actor: $actor,
            body: 'Search token @Shared_Name.',
        );
        app(IncidentTimelineService::class)->appendNote(
            incident: $otherEventIncident,
            actor: $wrongEventViewer,
            body: 'Other event token @Shared_Name.',
        );

        $this->actingAs($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=@Shared_Name")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $matching->id)
            ->assertJsonCount(1, 'incidents')
            ->assertJsonMissing(['id' => $unmatched->id])
            ->assertJsonMissing(['title' => 'Other event incident']);

        $this->actingAs($wrongEventViewer)
            ->getJson("/api/events/{$event->id}/incidents?search=Shared_Name")
            ->assertForbidden();
    }

    public function test_incident_search_finds_active_attached_field_report_tokens(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_operator', $event);
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $incident = Incident::factory()->forEvent($event)->create([
            'title' => 'Attached report incident',
        ]);
        $report = FieldReport::factory()
            ->forEvent($event)
            ->receivedByServer()
            ->create([
                'body' => 'Report references @AttachedSearch.',
            ]);

        app(NameReferenceIndexService::class)->synchronizeFieldReport($report);

        IncidentFieldReport::query()->create([
            'incident_id' => $incident->id,
            'field_report_id' => $report->id,
            'linked_by_user_id' => $actor->id,
            'linked_at' => Carbon::parse('2027-07-04T20:30:00Z'),
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=AttachedSearch")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $incident->id)
            ->assertJsonCount(1, 'incidents');
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

    private function userWithEventRole(string $roleCode, Event $event): User
    {
        $department = Department::query()->findOrFail($event->ic_department_id);
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

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => $event->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
