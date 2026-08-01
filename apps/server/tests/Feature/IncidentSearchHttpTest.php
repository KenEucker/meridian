<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
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
use App\Services\NameReferences\NameReferenceIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Explicit IMS incident list search, filters, and sorting (M11.19).
 *
 * IMS surface specification section 9; UI implementation contract sections 12.7
 * and 15.1; technical spec 19.9 and 19.10.
 */
class IncidentSearchHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_text_search_covers_the_incident_record_and_its_history(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $byNumber = $this->incident($event, ['incident_number' => 'INC-2027-000501', 'title' => 'Numbered record']);
        $byTitle = $this->incident($event, ['title' => 'Medical assist near the gate']);
        $byLocation = $this->incident($event, ['title' => 'Located record', 'location_name' => 'Gate A']);
        $byType = $this->incident($event, ['title' => 'Typed record']);
        $byResponder = $this->incident($event, ['title' => 'Responder record']);
        $byNote = $this->incident($event, ['title' => 'Noted record']);
        $byFieldReport = $this->incident($event, ['title' => 'Attached record']);
        $unmatched = $this->incident($event, ['title' => 'Unrelated record']);

        $this->attachType($byType, $event, 'Medical');
        $this->attachResponder($byResponder, 'Medical Lead');
        $this->note($byNote, 'Ranger reported a medical assist.');
        $this->attachFieldReport($byFieldReport, $event, $viewer, body: 'Observed a medical handoff.');

        $response = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=medical&state=all")
            ->assertOk();

        $ids = $this->incidentIds($response->json('incidents'));

        $this->assertEqualsCanonicalizing(
            [$byTitle->id, $byType->id, $byResponder->id, $byNote->id, $byFieldReport->id],
            $ids,
        );
        $this->assertNotContains($unmatched->id, $ids);
        $this->assertNotContains($byNumber->id, $ids);
        $this->assertNotContains($byLocation->id, $ids);

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=INC-2027-000501")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $byNumber->id)
            ->assertJsonCount(1, 'incidents');

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=gate+a")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $byLocation->id)
            ->assertJsonCount(1, 'incidents');
    }

    public function test_search_is_case_insensitive_and_treats_wildcards_as_literal_text(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $literal = $this->incident($event, ['title' => 'Shared_Name handoff']);
        $wildcard = $this->incident($event, ['title' => 'SharedXName handoff']);

        $response = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=shared_name")
            ->assertOk();

        $ids = $this->incidentIds($response->json('incidents'));

        $this->assertSame([$literal->id], $ids);
        $this->assertNotContains($wildcard->id, $ids);
    }

    public function test_search_ignores_stricken_notes_and_unlinked_field_reports(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $strickenNote = $this->incident($event, ['title' => 'Stricken note record']);
        $unlinkedReport = $this->incident($event, ['title' => 'Unlinked report record']);

        $this->note($strickenNote, 'Retracted radio relay detail.', stricken: true);
        $this->attachFieldReport(
            $unlinkedReport,
            $event,
            $viewer,
            body: 'Retracted radio relay detail.',
            unlinked: true,
        );

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=retracted")
            ->assertOk()
            ->assertJsonCount(0, 'incidents');
    }

    public function test_name_reference_chip_navigation_still_resolves_through_list_search(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $incident = $this->incident($event, ['title' => 'Chip record']);
        $other = $this->incident($event, ['title' => 'Other record']);

        $report = FieldReport::factory()->forEvent($event)->receivedByServer()->create([
            'title' => 'Gate observation',
            'body' => 'Handoff to @Blue-Hat near the north road.',
        ]);
        app(NameReferenceIndexService::class)->synchronizeFieldReport($report);

        IncidentFieldReport::query()->create([
            'incident_id' => $incident->id,
            'field_report_id' => $report->id,
            'linked_by_user_id' => $viewer->id,
            'linked_at' => Carbon::parse('2027-07-04T20:30:00Z'),
        ]);

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=Blue-Hat")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $incident->id)
            ->assertJsonCount(1, 'incidents')
            ->assertJsonMissing(['id' => $other->id]);
    }

    public function test_list_excludes_closed_incidents_by_default_and_can_include_them(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $open = $this->incident($event, ['title' => 'Open record']);
        $monitoring = $this->incident($event, [
            'title' => 'Monitoring record',
            'status' => Incident::STATUS_MONITORING,
        ]);
        $closed = $this->incident($event, [
            'title' => 'Closed record',
            'status' => Incident::STATUS_CLOSED,
            'closed_at' => Carbon::parse('2027-07-04T22:00:00Z'),
        ]);

        $default = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents")
            ->assertOk()
            ->assertJsonPath('filters.state', 'active');

        $this->assertEqualsCanonicalizing(
            [$open->id, $monitoring->id],
            $this->incidentIds($default->json('incidents')),
        );

        $all = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?state=all")
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$open->id, $monitoring->id, $closed->id],
            $this->incidentIds($all->json('incidents')),
        );

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?state=closed")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $closed->id)
            ->assertJsonCount(1, 'incidents');
    }

    public function test_priority_type_responder_and_started_window_filters_narrow_the_list(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $critical = $this->incident($event, [
            'title' => 'Critical record',
            'priority_label' => Incident::PRIORITY_CRITICAL,
            'started_at' => Carbon::parse('2027-07-04T10:00:00Z'),
        ]);
        $routine = $this->incident($event, [
            'title' => 'Routine record',
            'started_at' => Carbon::parse('2027-07-06T10:00:00Z'),
        ]);

        $this->attachType($critical, $event, 'Medical');
        $this->attachType($routine, $event, 'Radio');
        $responder = $this->attachResponder($critical, 'Vera');
        $this->attachResponder($routine, 'Omar');

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?priority=Critical")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $critical->id)
            ->assertJsonCount(1, 'incidents');

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?type=medical")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $critical->id)
            ->assertJsonCount(1, 'incidents');

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?responder={$responder->id}")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $critical->id)
            ->assertJsonCount(1, 'incidents');

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?started_from=2027-07-05")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $routine->id)
            ->assertJsonCount(1, 'incidents');

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?started_to=2027-07-04")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $critical->id)
            ->assertJsonCount(1, 'incidents');
    }

    public function test_filters_combine_instead_of_replacing_each_other(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $match = $this->incident($event, [
            'title' => 'Medical assist at the gate',
            'priority_label' => Incident::PRIORITY_SERIOUS,
        ]);
        $wrongPriority = $this->incident($event, ['title' => 'Medical assist at the depot']);
        $wrongState = $this->incident($event, [
            'title' => 'Medical assist closed out',
            'priority_label' => Incident::PRIORITY_SERIOUS,
            'status' => Incident::STATUS_CLOSED,
            'closed_at' => Carbon::parse('2027-07-04T22:00:00Z'),
        ]);

        $response = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=medical&priority=Serious")
            ->assertOk();

        $ids = $this->incidentIds($response->json('incidents'));

        $this->assertSame([$match->id], $ids);
        $this->assertNotContains($wrongPriority->id, $ids);
        $this->assertNotContains($wrongState->id, $ids);
    }

    public function test_state_and_priority_sorts_follow_operational_order(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $onHold = $this->incident($event, [
            'title' => 'On hold record',
            'status' => Incident::STATUS_ON_HOLD,
            'priority_label' => Incident::PRIORITY_IMPORTANT,
        ]);
        $open = $this->incident($event, [
            'title' => 'Open record',
            'status' => Incident::STATUS_OPEN,
            'priority_label' => Incident::PRIORITY_ROUTINE,
        ]);
        $onScene = $this->incident($event, [
            'title' => 'On scene record',
            'status' => Incident::STATUS_ON_SCENE,
            'priority_label' => Incident::PRIORITY_CRITICAL,
        ]);

        $byState = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?sort=state&direction=asc")
            ->assertOk();

        $this->assertSame(
            [$open->id, $onScene->id, $onHold->id],
            $this->incidentIds($byState->json('incidents')),
        );

        $byPriority = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?sort=priority&direction=asc")
            ->assertOk();

        $this->assertSame(
            [$onScene->id, $onHold->id, $open->id],
            $this->incidentIds($byPriority->json('incidents')),
        );

        $descending = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?sort=priority&direction=desc")
            ->assertOk();

        $this->assertSame(
            [$open->id, $onHold->id, $onScene->id],
            $this->incidentIds($descending->json('incidents')),
        );
    }

    public function test_incident_number_and_location_sorts_are_available_to_list_headings(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $first = $this->incident($event, [
            'incident_number' => 'INC-2027-000601',
            'title' => 'First record',
            'location_name' => 'Anchor camp',
        ]);
        $second = $this->incident($event, [
            'incident_number' => 'INC-2027-000602',
            'title' => 'Second record',
            'location_name' => 'Zephyr camp',
        ]);

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?sort=incident&direction=asc")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $first->id)
            ->assertJsonPath('incidents.1.id', $second->id);

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?sort=location&direction=desc")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $second->id)
            ->assertJsonPath('incidents.1.id', $first->id);
    }

    public function test_list_returns_event_filter_options_for_permitted_users(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $incident = $this->incident($event, ['title' => 'Option record']);
        $otherIncident = $this->incident($otherEvent, ['title' => 'Other event record']);

        $this->attachType($incident, $event, 'Medical');
        $this->attachType($otherIncident, $otherEvent, 'Weather');
        $responder = $this->attachResponder($incident, 'Vera');
        $this->attachResponder($otherIncident, 'Wanda');

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents")
            ->assertOk()
            ->assertJsonPath('filter_options.types', ['Medical'])
            ->assertJsonPath('filter_options.responders.0.staff_id', $responder->id)
            ->assertJsonPath('filter_options.responders.0.display_name', 'Vera')
            ->assertJsonCount(1, 'filter_options.responders')
            ->assertJsonPath('filter_options.priorities.0', 'all')
            ->assertJsonPath('filters.sort', 'updated')
            ->assertJsonPath('filters.direction', 'desc');
    }

    public function test_list_returns_what_an_authoring_form_may_assign(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $lead = $this->userWithEventRole('ic_lead', $event);
        $incident = $this->incident($event, ['title' => 'Assignable record']);

        $this->attachType($incident, $event, 'Medical');
        IncidentType::factory()->create([
            'organization_id' => $event->organization_id,
            'name' => 'Weather',
        ]);
        IncidentType::factory()->create([
            'organization_id' => $otherEvent->organization_id,
            'name' => 'Other organization type',
        ]);

        $responder = Staff::factory()->create(['preferred_name' => 'Vera']);
        DepartmentMembership::factory()
            ->for(Department::query()->findOrFail($event->ic_department_id))
            ->for($responder)
            ->create();
        $outsider = Staff::factory()->create(['preferred_name' => 'Wanda']);
        DepartmentMembership::factory()
            ->for(Department::factory()->for($event->organization)->create())
            ->for($outsider)
            ->create();

        $response = $this->actingAsClient($lead)
            ->getJson("/api/events/{$event->id}/incidents")
            ->assertOk()
            ->assertJsonPath('assignable.statuses', Incident::statuses())
            ->assertJsonPath('assignable.priorities', Incident::priorityLabels())
            // In use for this event, and known to the organization but not yet
            // used here. The other organization's type is neither.
            ->assertJsonPath('assignable.types', ['Medical', 'Weather']);

        $responderIds = array_column($response->json('assignable.responders'), 'staff_id');

        $this->assertContains($responder->id, $responderIds);
        $this->assertNotContains($outsider->id, $responderIds);
    }

    public function test_invalid_filter_values_are_refused_without_falling_back(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $this->incident($event, ['title' => 'Present record']);

        foreach ([
            'state=archived',
            'priority=Urgent',
            'sort=responder',
            'direction=sideways',
            'started_from=not-a-date',
            'started_from=2027-07-06&started_to=2027-07-04T00:00:00Z',
        ] as $query) {
            $response = $this->actingAsClient($viewer)
                ->getJson("/api/events/{$event->id}/incidents?{$query}")
                ->assertStatus(422);

            $this->assertIsString($response->json('message'));
            $this->assertNull($response->json('incidents'));
        }
    }

    public function test_list_pages_results_while_keeping_the_requested_order(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        foreach (range(1, 5) as $offset) {
            $this->incident($event, [
                'incident_number' => sprintf('INC-2027-%06d', 700 + $offset),
                'title' => sprintf('Paged record %d', $offset),
            ]);
        }

        $firstPage = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?sort=incident&direction=asc&per_page=2")
            ->assertOk()
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.total_pages', 3)
            ->assertJsonPath('pagination.has_more', true)
            ->assertJsonCount(2, 'incidents');

        $this->assertSame(
            ['INC-2027-000701', 'INC-2027-000702'],
            $this->incidentNumbers($firstPage->json('incidents')),
        );

        $lastPage = $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?sort=incident&direction=asc&per_page=2&page=3")
            ->assertOk()
            ->assertJsonPath('pagination.page', 3)
            ->assertJsonPath('pagination.has_more', false)
            ->assertJsonCount(1, 'incidents');

        $this->assertSame(
            ['INC-2027-000705'],
            $this->incidentNumbers($lastPage->json('incidents')),
        );

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?per_page=2&page=9")
            ->assertOk()
            ->assertJsonPath('pagination.page', 9)
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonCount(0, 'incidents');
    }

    public function test_pagination_counts_the_filtered_result_not_the_whole_event(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);

        $this->incident($event, ['title' => 'Medical assist one', 'priority_label' => Incident::PRIORITY_CRITICAL]);
        $this->incident($event, ['title' => 'Medical assist two', 'priority_label' => Incident::PRIORITY_CRITICAL]);
        $this->incident($event, ['title' => 'Unrelated record']);
        $this->incident($event, [
            'title' => 'Medical assist closed',
            'priority_label' => Incident::PRIORITY_CRITICAL,
            'status' => Incident::STATUS_CLOSED,
            'closed_at' => Carbon::parse('2027-07-04T22:00:00Z'),
        ]);

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=medical&priority=Critical&per_page=1")
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.total_pages', 2)
            ->assertJsonCount(1, 'incidents');
    }

    public function test_invalid_pagination_values_are_refused(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $this->incident($event, ['title' => 'Present record']);

        foreach (['page=0', 'page=-1', 'page=two', 'per_page=0', 'per_page=101', 'per_page=many'] as $query) {
            $this->actingAsClient($viewer)
                ->getJson("/api/events/{$event->id}/incidents?{$query}")
                ->assertStatus(422)
                ->assertJsonMissingPath('incidents');
        }
    }

    public function test_search_and_filters_never_cross_events_or_ic_permission(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $viewer = $this->userWithEventRole('ic_viewer', $event);
        $wrongEventViewer = $this->userWithEventRole('ic_viewer', $otherEvent);
        $organizer = $this->userWithRole('organizer', $event, eventScoped: false);
        $revoked = $this->userWithRole('ic_operator', $event, eventScoped: true, revoked: true);

        $mine = $this->incident($event, ['title' => 'Shared search term record']);
        $this->incident($otherEvent, ['title' => 'Shared search term record']);

        $this->getJson("/api/events/{$event->id}/incidents?search=shared+search+term")
            ->assertUnauthorized();

        $this->actingAsClient($viewer)
            ->getJson("/api/events/{$event->id}/incidents?search=shared+search+term")
            ->assertOk()
            ->assertJsonPath('incidents.0.id', $mine->id)
            ->assertJsonCount(1, 'incidents');

        foreach ([$wrongEventViewer, $organizer, $revoked] as $actor) {
            $this->actingAsClient($actor)
                ->getJson("/api/events/{$event->id}/incidents?search=shared+search+term&state=all")
                ->assertForbidden()
                ->assertJsonPath(
                    'message',
                    'This page requires Incident Command access for the event configured IC department.',
                );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function incident(Event $event, array $attributes = []): Incident
    {
        return Incident::factory()->forEvent($event)->create(array_merge([
            'status' => Incident::STATUS_OPEN,
            'priority_label' => Incident::PRIORITY_ROUTINE,
            'started_at' => Carbon::parse('2027-07-04T20:00:00Z'),
            'location_name' => null,
            'location_address' => null,
            'location_details' => null,
        ], $attributes));
    }

    private function attachType(Incident $incident, Event $event, string $name): IncidentType
    {
        $type = IncidentType::query()
            ->where('organization_id', $event->organization_id)
            ->where('name', $name)
            ->first()
            ?? IncidentType::factory()->create([
                'organization_id' => $event->organization_id,
                'name' => $name,
            ]);

        $incident->incidentTypes()->attach($type->id, [
            'id' => (string) Str::uuid(),
            'created_at' => Carbon::parse('2027-07-04T20:05:00Z'),
        ]);

        return $type;
    }

    private function attachResponder(Incident $incident, string $preferredName): Staff
    {
        $staff = Staff::factory()->create(['preferred_name' => $preferredName]);

        IncidentStaff::factory()->create([
            'incident_id' => $incident->id,
            'staff_id' => $staff->id,
            'created_at' => Carbon::parse('2027-07-04T20:06:00Z'),
        ]);

        return $staff;
    }

    private function note(Incident $incident, string $body, bool $stricken = false): IncidentTimelineEntry
    {
        return IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => $body,
            'created_at' => Carbon::parse('2027-07-04T20:10:00Z'),
            'stricken_at' => $stricken ? Carbon::parse('2027-07-04T20:11:00Z') : null,
            'stricken_reason' => $stricken ? 'Recorded on the wrong incident.' : null,
        ]);
    }

    private function attachFieldReport(
        Incident $incident,
        Event $event,
        User $actor,
        string $body,
        bool $unlinked = false,
    ): FieldReport {
        $report = FieldReport::factory()->forEvent($event)->receivedByServer()->create([
            'title' => 'Field observation',
            'body' => $body,
        ]);

        IncidentFieldReport::query()->create([
            'incident_id' => $incident->id,
            'field_report_id' => $report->id,
            'linked_by_user_id' => $actor->id,
            'linked_at' => Carbon::parse('2027-07-04T20:15:00Z'),
            'unlinked_by_user_id' => $unlinked ? $actor->id : null,
            'unlinked_at' => $unlinked ? Carbon::parse('2027-07-04T20:16:00Z') : null,
            'stricken_reason' => $unlinked ? 'Field Report removed from incident.' : null,
        ]);

        return $report;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $incidents
     * @return list<string>
     */
    private function incidentIds(?array $incidents): array
    {
        return array_values(array_map(
            static fn (array $incident): string => (string) $incident['id'],
            $incidents ?? [],
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $incidents
     * @return list<string>
     */
    private function incidentNumbers(?array $incidents): array
    {
        return array_values(array_map(
            static fn (array $incident): string => (string) $incident['incident_number'],
            $incidents ?? [],
        ));
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
