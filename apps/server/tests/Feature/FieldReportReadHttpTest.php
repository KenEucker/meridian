<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
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

/**
 * Event-wide Field Report read for IC review and the incident link picker
 * (FR-005, FR-006; M11.8, M16.20).
 */
class FieldReportReadHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_ic_viewer_lists_this_events_field_reports_newest_first(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $otherEvent = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_viewer', $event);

        $older = $this->fieldReport($event, 'FRA-2027-000100', 'Older observation', '2027-07-04T19:00:00Z');
        $newer = $this->fieldReport($event, 'FRA-2027-000101', 'Newer observation', '2027-07-04T21:00:00Z');
        $this->fieldReport($otherEvent, 'FRA-2027-000999', 'Other event observation', '2027-07-04T22:00:00Z');

        $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/field-reports")
            ->assertOk()
            ->assertJsonPath('event_id', $event->id)
            ->assertJsonCount(2, 'field_reports')
            ->assertJsonPath('field_reports.0.id', $newer->id)
            ->assertJsonPath('field_reports.0.display_number', 'FRA-2027-000101')
            ->assertJsonPath('field_reports.1.id', $older->id)
            ->assertJsonMissing(['title' => 'Other event observation']);
    }

    public function test_report_names_the_incidents_it_is_actively_linked_to(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $actor = $this->userWithEventRole('ic_lead', $event);
        $report = $this->fieldReport($event, 'FRA-2027-000102', 'Gate A observation', '2027-07-04T20:00:00Z');

        $linked = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000042',
            'title' => 'Medical assist near Gate A',
            'status' => Incident::STATUS_ON_SCENE,
            'priority_label' => Incident::PRIORITY_SERIOUS,
        ]);
        $unlinked = Incident::factory()->forEvent($event)->create([
            'incident_number' => 'INC-2027-000043',
            'title' => 'Withdrawn link',
        ]);

        IncidentFieldReport::query()->create([
            'incident_id' => $linked->id,
            'field_report_id' => $report->id,
            'linked_by_user_id' => $actor->id,
            'linked_at' => Carbon::parse('2027-07-04T20:05:00Z'),
        ]);
        IncidentFieldReport::query()->create([
            'incident_id' => $unlinked->id,
            'field_report_id' => $report->id,
            'linked_by_user_id' => $actor->id,
            'linked_at' => Carbon::parse('2027-07-04T20:06:00Z'),
            'unlinked_by_user_id' => $actor->id,
            'unlinked_at' => Carbon::parse('2027-07-04T20:07:00Z'),
        ]);

        $this->actingAsClient($actor)
            ->getJson("/api/events/{$event->id}/field-reports")
            ->assertOk()
            ->assertJsonCount(1, 'field_reports.0.related_incidents')
            ->assertJsonPath('field_reports.0.related_incidents.0.id', $linked->id)
            ->assertJsonPath('field_reports.0.related_incidents.0.incident_number', 'INC-2027-000042')
            ->assertJsonPath('field_reports.0.related_incidents.0.status', Incident::STATUS_ON_SCENE)
            ->assertJsonPath('field_reports.0.related_incidents.0.priority_label', Incident::PRIORITY_SERIOUS)
            ->assertJsonMissing(['incident_number' => 'INC-2027-000043']);
    }

    public function test_author_without_event_visibility_is_refused_their_own_events_list(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $author = $this->userWithEventRole('staff', $event);
        $this->fieldReport($event, 'FRA-2027-000103', 'Own observation', '2027-07-04T20:00:00Z', $author);

        $this->actingAsClient($author)
            ->getJson("/api/events/{$event->id}/field-reports")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'This page requires event-wide Field Report access for the event configured IC department.',
            );
    }

    public function test_department_lead_outside_incident_command_is_refused(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $lead = $this->userWithRole('department_lead', $event, eventScoped: false);
        $this->fieldReport($event, 'FRA-2027-000104', 'Observation', '2027-07-04T20:00:00Z');

        $this->actingAsClient($lead)
            ->getJson("/api/events/{$event->id}/field-reports")
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_refused(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();

        $this->getJson("/api/events/{$event->id}/field-reports")->assertUnauthorized();
    }

    private function fieldReport(
        Event $event,
        string $fraNumber,
        string $title,
        string $receivedAt,
        ?User $author = null,
    ): FieldReport {
        $staff = Staff::factory()->create(['preferred_name' => 'Vera Ranger']);

        return FieldReport::factory()
            ->forEvent($event)
            ->forAuthor($author ?? User::factory()->create(['name' => 'Vera User']), $staff)
            ->receivedByServer()
            ->create([
                'fra_number' => $fraNumber,
                'title' => $title,
                'body' => 'Observed @Blue-Hat near Gate A. #medical',
                'server_received_at' => Carbon::parse($receivedAt),
                'created_at' => Carbon::parse($receivedAt),
            ]);
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
        return $this->userWithRole($roleCode, $event, eventScoped: true);
    }

    private function userWithRole(string $roleCode, Event $event, bool $eventScoped): User
    {
        $department = $eventScoped && in_array($roleCode, ['ic_lead', 'ic_operator', 'ic_viewer'], true)
            ? Department::query()->findOrFail($event->ic_department_id)
            : Department::factory()->for($event->organization)->create();

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
            'event_id' => $eventScoped ? $event->id : null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
