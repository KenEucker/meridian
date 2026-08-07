<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\FieldReportAppend;
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
use Tests\TestCase;

/**
 * Field Report and incident repair visibility in God Mode (M18.34; UI contract
 * 12.9; technical spec 22.2, 22.3; FR-004 through FR-007; ORG-015).
 *
 * These two screens are the ones where a console permission deliberately buys
 * less than it does everywhere else in the console. `platform.field-reports`
 * and `platform.incidents` open a screen; what is on it is decided by the same
 * services the product asks — {@see \App\Services\FieldReports\FieldReportVisibilityAccess}
 * and {@see \App\Services\Incidents\IncidentReadAccess} — so console access
 * cannot become event-wide reading of somebody's account of what happened to
 * them.
 *
 * That is the opposite call from `ConsoleAuditTest`, and on purpose: an audit
 * row saying a report was submitted is history about a change, which repair
 * work needs; the report is the thing FR-005 protects.
 */
class ConsoleRepairVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_field_report_screen_requires_its_console_permission(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)->get(route('platform.field-reports'))->assertForbidden();
    }

    public function test_the_incident_screen_requires_its_console_permission(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)->get(route('platform.incidents'))->assertForbidden();
    }

    /**
     * The heart of it. A technician holding every console permission and no
     * staff standing is not an author and holds no `field_reports.view_event`,
     * so the product would show them nothing and neither does this.
     */
    public function test_console_access_alone_shows_no_field_reports(): void
    {
        $event = Event::factory()->create();
        FieldReport::factory()->forEvent($event)->create(['title' => 'Dust storm at the gate']);

        $response = $this->actingAs($this->consoleUser())->get(route('platform.field-reports'));

        $response->assertOk();
        $response->assertDontSee('Dust storm at the gate');
    }

    public function test_console_access_alone_shows_no_incidents(): void
    {
        Incident::factory()->create(['title' => 'Unattended vehicle fire']);

        $response = $this->actingAs($this->consoleUser())->get(route('platform.incidents'));

        $response->assertOk();
        $response->assertDontSee('Unattended vehicle fire');
    }

    /** FR-004: a report's author reaches their own report, here as anywhere. */
    public function test_an_author_reads_their_own_report_on_the_console_screen(): void
    {
        $author = $this->consoleUser();
        FieldReport::factory()->forAuthor($author)->create(['title' => 'Lost child at Centre Camp']);
        FieldReport::factory()->create(['title' => 'A report by somebody else']);

        $response = $this->actingAs($author)->get(route('platform.field-reports'));

        $response->assertOk();
        $response->assertSee('Lost child at Centre Camp');
        $response->assertDontSee('A report by somebody else');
    }

    /**
     * FR-005 and FR-006: Incident Command reads every report of *its* event and
     * of no other. The console applies the same event boundary rather than a
     * node-wide one.
     */
    public function test_incident_command_reads_its_own_events_reports_and_no_others(): void
    {
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();

        FieldReport::factory()->forEvent($event)->create(['title' => 'Medical at the temple']);
        FieldReport::factory()->forEvent($otherEvent)->create(['title' => 'Another event entirely']);

        $viewer = $this->userWithEventRole('ic_viewer', $event, console: true);

        $response = $this->actingAs($viewer)->get(route('platform.field-reports'));

        $response->assertOk();
        $response->assertSee('Medical at the temple');
        $response->assertDontSee('Another event entirely');
    }

    /**
     * A department lead is the case FR-006 names explicitly, and the one a
     * console screen is most likely to get wrong: senior, obviously
     * responsible, and deliberately not admitted.
     */
    public function test_a_department_lead_with_console_access_reads_no_field_reports(): void
    {
        $event = Event::factory()->create();
        FieldReport::factory()->forEvent($event)->create(['title' => 'Radio failure at Gate 3']);

        $lead = $this->userWithRole('department_lead', $event, $event->organization, eventScoped: false, console: true);

        $response = $this->actingAs($lead)->get(route('platform.field-reports'));

        $response->assertOk();
        $response->assertDontSee('Radio failure at Gate 3');
    }

    /**
     * The expectation stated directly: for every reader and every report, the
     * console list agrees with `FieldReportPolicy::view` — the ability the
     * product read goes through — rather than merely resembling it.
     *
     * Written as a comparison instead of as four hand-written expectations so
     * that a future change to the policy shows up here as a disagreement rather
     * than as a test that still passes against the old rule.
     */
    public function test_the_console_list_matches_the_products_own_answer_reader_by_reader(): void
    {
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();

        $reporter = $this->consoleUser();
        $taker = $this->consoleUser();

        $reports = [
            'Report the reader authored' => FieldReport::factory()
                ->forEvent($event)->forAuthor($reporter)->create(['title' => 'Report the reader authored']),
            'Report the reader took down' => FieldReport::factory()
                ->forEvent($event)->create([
                    'title' => 'Report the reader took down',
                    'submitted_by_user_id' => $taker->id,
                ]),
            'Report by a stranger' => FieldReport::factory()
                ->forEvent($event)->create(['title' => 'Report by a stranger']),
            'Report at another event' => FieldReport::factory()
                ->forEvent($otherEvent)->create(['title' => 'Report at another event']),
        ];

        $readers = [
            'a technician with no staff standing' => $this->consoleUser(),
            'the author' => $reporter,
            'the operator who took one down' => $taker,
            'a department lead' => $this->userWithRole(
                'department_lead',
                $event,
                $event->organization,
                eventScoped: false,
                console: true,
            ),
            'Incident Command for the event' => $this->userWithEventRole('ic_viewer', $event, console: true),
        ];

        foreach ($readers as $who => $reader) {
            $response = $this->actingAs($reader)->get(route('platform.field-reports'));
            $response->assertOk();

            foreach ($reports as $title => $report) {
                $productWouldShowIt = $reader->can('view', $report);

                $productWouldShowIt
                    ? $response->assertSee($title)
                    : $response->assertDontSee($title);

                // A reader who is shown nothing at all would pass the loop
                // above vacuously, so state the interesting half out loud.
                if ($who === 'Incident Command for the event') {
                    $this->assertSame(
                        $report->event_id === $event->id,
                        $productWouldShowIt,
                        "Incident Command should read this event's reports and no others.",
                    );
                }
            }
        }
    }

    /** A list filter a typed address walks around is not a rule. */
    public function test_the_field_report_entry_refuses_a_report_the_operator_may_not_read(): void
    {
        $report = FieldReport::factory()->create();

        $this->actingAs($this->consoleUser())
            ->get(route('platform.field-reports.show', $report->id))
            ->assertForbidden();
    }

    public function test_the_incident_entry_refuses_an_incident_the_operator_may_not_read(): void
    {
        $incident = Incident::factory()->create();

        $this->actingAs($this->consoleUser())
            ->get(route('platform.incidents.show', $incident->id))
            ->assertForbidden();
    }

    /** The entry is where the account and its corrections are. */
    public function test_the_field_report_entry_shows_the_body_and_its_appends(): void
    {
        $event = Event::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->create([
            'title' => 'Structure fire near 3:00',
            'body' => 'Smoke reported from the north side of the art piece.',
        ]);
        FieldReportAppend::factory()->create([
            'field_report_id' => $report->id,
            'body' => 'Fire crew stood the call down at 04:12.',
        ]);

        $viewer = $this->userWithEventRole('ic_lead', $event, console: true);

        $response = $this->actingAs($viewer)
            ->get(route('platform.field-reports.show', $report->id));

        $response->assertOk();
        $response->assertSee('Smoke reported from the north side of the art piece.');
        $response->assertSee('Fire crew stood the call down at 04:12.');
    }

    /**
     * FR-012 puts photo access behind its own capability, and technical spec
     * 22.3 rules out console redaction or deletion of an attachment. So the
     * entry says how many there are and nothing else — a filename is content.
     */
    public function test_the_field_report_entry_counts_photos_without_serving_them(): void
    {
        $event = Event::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->create();

        $attachment = Attachment::factory()->forFieldReport($report)->create([
            'filename' => 'EVENT-2027_FRA-2027-000009_2027-07-04T13-22-10Z_01.webp',
        ]);

        $viewer = $this->userWithEventRole('ic_lead', $event, console: true);

        $response = $this->actingAs($viewer)
            ->get(route('platform.field-reports.show', $report->id));

        $response->assertOk();
        $response->assertSee('One photo is attached.');
        $response->assertDontSee($attachment->filename);
        $response->assertDontSee($attachment->storage_path);
    }

    /**
     * `incidents.view` and nothing else. There is no author clause here the way
     * there is for a Field Report: opening an incident grants no standing over
     * it.
     */
    public function test_incident_visibility_follows_the_incidents_view_grant(): void
    {
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();

        Incident::factory()->for($event)->create(['title' => 'Vehicle into a fence line']);
        Incident::factory()->for($otherEvent)->create(['title' => 'Another event entirely']);

        $viewer = $this->userWithEventRole('ic_viewer', $event, console: true);

        $response = $this->actingAs($viewer)->get(route('platform.incidents'));

        $response->assertOk();
        $response->assertSee('Vehicle into a fence line');
        $response->assertDontSee('Another event entirely');
    }

    /**
     * INC-014: a strike annotates history rather than removing it, and a repair
     * screen is exactly where somebody needs to read what was withdrawn.
     */
    public function test_the_incident_entry_shows_the_timeline_including_stricken_entries(): void
    {
        $event = Event::factory()->create();
        $incident = Incident::factory()->for($event)->create(['title' => 'Crowd surge at the gate']);

        IncidentTimelineEntry::factory()->create([
            'incident_id' => $incident->id,
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => 'Two Rangers dispatched to the north gate.',
        ]);
        IncidentTimelineEntry::factory()->create([
            'incident_id' => $incident->id,
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => 'Named the wrong participant.',
            'stricken_at' => now(),
            'stricken_reason' => 'Misidentified.',
        ]);

        $viewer = $this->userWithEventRole('ic_lead', $event, console: true);

        $response = $this->actingAs($viewer)
            ->get(route('platform.incidents.show', $incident->id));

        $response->assertOk();
        $response->assertSee('Two Rangers dispatched to the north gate.');
        $response->assertSee('Named the wrong participant.');
        $response->assertSee('Misidentified.');
    }

    /**
     * A report belongs to an event, a department, and a team, so it offers all
     * three narrowing levels. `ScopeFiltersLayout` shows only the levels a model
     * declares, so all three appearing is the assertion that all three exist.
     */
    public function test_the_field_report_screen_offers_all_three_scope_filters(): void
    {
        $response = $this->actingAs($this->consoleUser())->get(route('platform.field-reports'));

        $response->assertOk();
        $response->assertSee('scope_organization');
        $response->assertSee('scope_department');
        $response->assertSee('scope_team');
    }

    public function test_the_field_report_organization_filter_narrows_the_list(): void
    {
        $mine = Organization::factory()->create();
        $theirs = Organization::factory()->create();

        $mineEvent = Event::factory()->for($mine)->create();
        $theirsEvent = Event::factory()->for($theirs)->create();

        $author = $this->consoleUser();
        FieldReport::factory()->forEvent($mineEvent)->forAuthor($author)->create(['title' => 'Northwood report']);
        FieldReport::factory()->forEvent($theirsEvent)->forAuthor($author)->create(['title' => 'Cascadia report']);

        $response = $this->actingAs($author)
            ->get(route('platform.field-reports', ['scope_organization' => $mine->id]));

        $response->assertOk();
        $response->assertSee('Northwood report');
        $response->assertDontSee('Cascadia report');
    }

    /**
     * An incident carries no department and no team, so those two controls are
     * absent rather than present and inert.
     */
    public function test_the_incident_screen_offers_only_the_organization_filter(): void
    {
        $response = $this->actingAs($this->consoleUser())->get(route('platform.incidents'));

        $response->assertOk();
        $response->assertSee('scope_organization');
        $response->assertDontSee('scope_department');
        $response->assertDontSee('scope_team');
    }

    public function test_the_incident_organization_filter_narrows_the_list(): void
    {
        $mine = Organization::factory()->create();
        $theirs = Organization::factory()->create();

        $mineEvent = Event::factory()->for($mine)->create();
        $theirsEvent = Event::factory()->for($theirs)->create();

        Incident::factory()->for($mineEvent)->create(['title' => 'Northwood incident']);
        Incident::factory()->for($theirsEvent)->create(['title' => 'Cascadia incident']);

        $viewer = $this->userWithEventRole('ic_viewer', $mineEvent, console: true);
        $this->grantEventRole('ic_viewer', $theirsEvent, $viewer);

        $unfiltered = $this->actingAs($viewer)->get(route('platform.incidents'));
        $unfiltered->assertSee('Northwood incident');
        $unfiltered->assertSee('Cascadia incident');

        $response = $this->actingAs($viewer)
            ->get(route('platform.incidents', ['scope_organization' => $mine->id]));

        $response->assertOk();
        $response->assertSee('Northwood incident');
        $response->assertDontSee('Cascadia incident');
    }

    /**
     * Technical spec 22.3: God Mode cannot edit a finalized original body, and
     * there is no console append or redaction workflow in Alpha 1. The model
     * refuses, so no screen could offer one whatever its form said.
     */
    public function test_a_field_report_cannot_be_edited_from_anywhere(): void
    {
        $report = FieldReport::factory()->create();

        $this->expectException(\RuntimeException::class);

        $report->update(['body' => 'Rewritten.']);
    }

    /** A console operator with every console permission and no staff standing. */
    private function consoleUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.field-reports' => true,
                'platform.incidents' => true,
            ],
        ]);
    }

    private function userWithEventRole(string $roleCode, Event $event, bool $console = false): User
    {
        return $this->userWithRole($roleCode, $event, $event->organization, eventScoped: true, console: $console);
    }

    private function userWithRole(
        string $roleCode,
        Event $event,
        Organization $organization,
        bool $eventScoped,
        bool $console = false,
    ): User {
        $user = $console
            ? $this->consoleUser()
            : User::factory()->create();

        $this->grantEventRole($roleCode, $event, $user, $organization, $eventScoped);

        return $user;
    }

    private function grantEventRole(
        string $roleCode,
        Event $event,
        User $user,
        ?Organization $organization = null,
        bool $eventScoped = true,
    ): void {
        $organization ??= $event->organization;

        $department = $eventScoped && in_array($roleCode, ['ic_lead', 'ic_operator', 'ic_viewer'], true)
            ? $this->ensureEventIcDepartment($event, $organization)
            : Department::factory()->for($organization)->create();

        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
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
    }

    private function ensureEventIcDepartment(Event $event, Organization $organization): Department
    {
        if ($event->ic_department_id !== null) {
            return Department::query()->findOrFail($event->ic_department_id);
        }

        $event->loadMissing(['icDepartment', 'organization.defaultIcDepartment']);

        $department = $event->organization?->defaultIcDepartment;

        if ($department !== null) {
            return $department;
        }

        $department = Department::factory()->for($organization)->create();
        $event->forceFill(['ic_department_id' => $department->id])->save();

        return $department;
    }
}
