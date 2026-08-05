<?php

namespace Tests\Feature;

use App\Domain\Dashboard\DashboardCatalog;
use App\Domain\Dashboard\DashboardWidgetDefinition;
use App\Domain\Dashboard\DashboardWidgetEvaluation;
use App\Domain\Dashboard\DashboardWidgetGroup;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventDepartmentAssignment;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\SharedWorkstation;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Auth\SharedWorkstationLoginCodeService;
use App\Services\Auth\SharedWorkstationSessionKey;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The dashboard read and the widget inventory behind it (M18.28; UI contract
 * 13.1 through 13.6).
 *
 * The tests that matter most here are the two the milestone asks for by name:
 * that organizer widgets carry no incident data without IC authority, and that
 * a quiet widget says the contract's own sentence rather than rendering an
 * empty list.
 */
class DashboardReadHttpTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The inventory (UI contract 13.1 through 13.6)
    |--------------------------------------------------------------------------
    */

    /**
     * Every row of the contract's six tables is catalogued, with the contract's
     * own widget ids.
     */
    public function test_the_catalogue_carries_the_whole_contract_inventory(): void
    {
        $ids = array_map(
            fn (DashboardWidgetDefinition $definition): string => $definition->id,
            DashboardCatalog::definitions(),
        );

        $this->assertSame([
            // 13.1 Staff
            'staff.current_shift',
            'staff.upcoming_shifts',
            'staff.assigned_departments',
            'staff.shift_alerts',
            'staff.document_acknowledgments',
            'staff.briefing',
            'staff.quiet_state',
            // 13.2 Department Lead
            'dept.coverage_issues',
            'dept.shift_readiness',
            'dept.checkin_status',
            'dept.training_readiness',
            'dept.policy_readiness',
            'dept.equipment_returns',
            'dept.event_map',
            // 13.3 Department Operations
            'shift.current_assignments',
            'shift.late_missing',
            'shift.deployment_needs',
            'shift.equipment_status',
            // 13.4 Organizer
            'org.event_readiness',
            'org.cross_dept_coverage',
            'org.application_review',
            'org.policy_readiness',
            'org.planning_tasks',
            'org.operations_window',
            // 13.5 IC
            'ic.active_incidents',
            'ic.serious_incidents',
            'ic.on_scene',
            'ic.monitoring',
            'ic.unresolved_field_reports',
            'ic.briefing',
            // 13.6 Kiosk
            'kiosk.current_tasks',
            'kiosk.staff_checkin',
            'kiosk.equipment_returns',
            'kiosk.node_status',
            'kiosk.switch_user',
            'kiosk.event_map',
        ], $ids);
    }

    /**
     * A widget with no domain behind it names the task that will answer it and
     * is never compiled (widget spec 4).
     */
    public function test_a_deferred_widget_is_catalogued_and_never_compiled(): void
    {
        $deferred = array_values(array_map(
            fn (DashboardWidgetDefinition $definition): string => $definition->id,
            array_filter(
                DashboardCatalog::definitions(),
                fn (DashboardWidgetDefinition $definition): bool => ! $definition->isAnswerable(),
            ),
        ));

        $this->assertSame([
            'staff.briefing',
            'dept.event_map',
            'org.planning_tasks',
            'ic.briefing',
            'kiosk.event_map',
        ], $deferred);

        foreach (DashboardWidgetGroup::cases() as $group) {
            foreach (DashboardCatalog::answerable($group) as $definition) {
                $this->assertTrue($definition->isAnswerable());
                $this->assertSame(DashboardWidgetEvaluation::Node, $definition->evaluation);
            }
        }

        $scenario = $this->scenario();

        $ids = collect($this->dashboardFor($scenario['lead'], $scenario)->json('widgets'))
            ->pluck('id');

        foreach ($deferred as $id) {
            $this->assertFalse($ids->contains($id), "{$id} has no domain and must not be compiled.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Staff widgets (UI contract 13.1)
    |--------------------------------------------------------------------------
    */

    public function test_staff_widgets_report_the_running_shift_and_what_is_outstanding(): void
    {
        $scenario = $this->scenario();

        $widgets = $this->widgetsFor($scenario['scheduled'], $scenario);

        $current = $widgets['staff.current_shift'];
        $this->assertFalse($current['quiet']);
        $this->assertSame('attention', $current['attention']);
        $this->assertSame('Your shift has started and you are not checked in yet.', $current['summary']);
        $this->assertSame('Ranger Dirt Day Shift', $current['items'][0]['label']);
        $this->assertSame('Not checked in', $current['items'][0]['status']);

        $departments = $widgets['staff.assigned_departments'];
        $this->assertFalse($departments['quiet']);
        $this->assertSame('Rangers', $departments['items'][0]['label']);

        // Running, not checked in: a warning about the shift they are standing
        // on, not a reminder about one they have not reached yet.
        $alerts = $widgets['staff.shift_alerts'];
        $this->assertSame('warning', $alerts['attention']);
        $this->assertSame('Not checked in', $alerts['items'][0]['status']);

        $acknowledgments = $widgets['staff.document_acknowledgments'];
        $this->assertFalse($acknowledgments['quiet']);
        $this->assertSame('Fire Safety Policy', $acknowledgments['items'][0]['label']);
        // POL-026 and POL-027: outstanding, and not blocking anything.
        $this->assertSame('attention', $acknowledgments['attention']);

        // The reassurance is not on a screen that also carries a warning.
        $this->assertArrayNotHasKey('staff.quiet_state', $widgets);
    }

    /**
     * Quiet states (widget spec 14): a widget with nothing to report says the
     * contract's own sentence, carries no items, and the group's reassurance
     * appears only when every one of them did.
     */
    public function test_a_staff_member_with_nothing_outstanding_reads_quiet_states(): void
    {
        $scenario = $this->scenario();

        $widgets = $this->widgetsFor($scenario['bystander'], $scenario);

        foreach ([
            'staff.current_shift' => 'No current shift',
            'staff.upcoming_shifts' => 'No upcoming shifts',
            'staff.shift_alerts' => 'No shift alerts',
            'staff.document_acknowledgments' => 'No documents need acknowledgment',
        ] as $id => $sentence) {
            $this->assertTrue($widgets[$id]['quiet'], "{$id} should be quiet.");
            $this->assertSame($sentence, $widgets[$id]['quiet_state']);
            $this->assertNull($widgets[$id]['summary']);
            $this->assertSame([], $widgets[$id]['items']);
            $this->assertSame('routine', $widgets[$id]['attention']);
        }

        // Belonging to a department is a fact rather than a task, so the
        // reassurance stands beside it: "Nothing Needs Action" is about action,
        // not about the group being empty.
        $this->assertFalse($widgets['staff.assigned_departments']['quiet']);
        $this->assertSame('routine', $widgets['staff.assigned_departments']['attention']);
        $this->assertArrayHasKey('staff.quiet_state', $widgets);
    }

    /**
     * The reassurance is earned rather than decorative: present for a reader
     * with nothing above Routine, and absent the moment anything is — which
     * {@see test_staff_widgets_report_the_running_shift_and_what_is_outstanding}
     * asserts for a reader standing on an unchecked-in shift.
     */
    public function test_the_quiet_state_widget_appears_when_nothing_needs_action(): void
    {
        $scenario = $this->scenario();

        // Somebody with a status in the organization and no department, no
        // shifts, and nothing to acknowledge.
        $stranger = User::factory()->create();
        $staff = Staff::factory()->create();
        $stranger->staffProfiles()->attach($staff->id);
        $staff->organizationStatuses()->create([
            'organization_id' => $scenario['organization']->id,
            'status' => 'active',
        ]);

        $widgets = $this->widgetsFor($stranger, $scenario);

        $this->assertTrue($widgets['staff.assigned_departments']['quiet']);
        $this->assertArrayHasKey('staff.quiet_state', $widgets);
        $this->assertSame(
            'Nothing needs your attention right now.',
            $widgets['staff.quiet_state']['summary'],
        );
        $this->assertNull($widgets['staff.quiet_state']['action_label']);
    }

    /*
    |--------------------------------------------------------------------------
    | Department widgets (UI contract 13.2, 13.3)
    |--------------------------------------------------------------------------
    */

    public function test_a_department_lead_reads_coverage_and_a_logistics_holder_reads_the_desk(): void
    {
        $scenario = $this->scenario();

        $lead = $this->widgetsFor($scenario['lead'], $scenario);

        $coverage = $lead['dept.coverage_issues'];
        $this->assertFalse($coverage['quiet']);
        // Capacity 3, two on it, and it is running: a warning rather than a note.
        $this->assertSame('warning', $coverage['attention']);
        $this->assertSame(1, $coverage['metric']['value']);

        // The lead does not run the desk, so the desk's two widgets are absent
        // rather than empty (CLIENT-005).
        $this->assertArrayNotHasKey('dept.checkin_status', $lead);
        $this->assertArrayNotHasKey('dept.equipment_returns', $lead);

        $logistics = $this->widgetsFor($scenario['logistics'], $scenario);

        $this->assertArrayNotHasKey('dept.coverage_issues', $logistics);
        $this->assertFalse($logistics['dept.checkin_status']['quiet']);
        $this->assertSame(1, $logistics['dept.checkin_status']['metric']['value']);
        $this->assertFalse($logistics['dept.equipment_returns']['quiet']);
        $this->assertSame('Radio 12', $logistics['dept.equipment_returns']['items'][0]['label']);
    }

    public function test_department_operations_widgets_are_about_the_shift_in_front_of_the_desk(): void
    {
        $scenario = $this->scenario();

        $widgets = $this->widgetsFor($scenario['logistics'], $scenario);

        $assignments = $widgets['shift.current_assignments'];
        $this->assertFalse($assignments['quiet']);
        $this->assertSame(1, $assignments['metric']['value']);

        $late = $widgets['shift.late_missing'];
        $this->assertFalse($late['quiet']);
        $this->assertSame('Not checked in', $late['items'][0]['status']);

        $equipment = $widgets['shift.equipment_status'];
        $this->assertFalse($equipment['quiet']);
        $this->assertSame('Radio 12', $equipment['items'][0]['label']);

        // Deployments belong to `department_operations`, which the desk is not.
        $this->assertArrayNotHasKey('shift.deployment_needs', $widgets);
    }

    /**
     * A department-scoped group needs a department. Without one the read is
     * still answerable — an organizer's dashboard is event-scoped — and the two
     * department groups are simply absent.
     */
    public function test_the_department_groups_are_absent_without_a_department(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['organizer'])
            ->getJson("/api/events/{$scenario['event']->id}/dashboard")
            ->assertOk();

        $groups = collect($response->json('groups'))->pluck('group');

        $this->assertFalse($groups->contains('department_lead'));
        $this->assertFalse($groups->contains('department_operations'));
        $this->assertTrue($groups->contains('organizer'));
        $this->assertNull($response->json('context.department_id'));
    }

    /*
    |--------------------------------------------------------------------------
    | The organizer IMS exclusion (UI contract 13.4)
    |--------------------------------------------------------------------------
    */

    /**
     * The milestone's named test: organizer widgets surface no incident data
     * without IC authority.
     *
     * The event has open incidents, one of them Critical, and unlinked Field
     * Reports. The organizer's dashboard carries no widget from 13.5, no
     * incident title, no incident number, no priority label, and no count of
     * any of it — and the same event read by an IC operator does.
     */
    public function test_organizer_widgets_surface_no_incident_data_without_ic_authority(): void
    {
        $scenario = $this->scenario();
        $this->givenOpenIncidents($scenario);

        $response = $this->actingAsClient($scenario['organizer'])
            ->getJson("/api/events/{$scenario['event']->id}/dashboard")
            ->assertOk();

        $groups = collect($response->json('groups'))->pluck('group');
        $this->assertTrue($groups->contains('organizer'));
        $this->assertFalse($groups->contains('ic'));

        $widgets = collect($response->json('widgets'));
        $this->assertTrue($widgets->every(
            fn (array $widget): bool => ! str_starts_with((string) $widget['id'], 'ic.'),
        ));

        // Not merely "no IC widget": nothing an incident is made of reaches the
        // payload at all.
        $payload = json_encode($response->json('widgets'), JSON_THROW_ON_ERROR);

        foreach ([
            'Structure fire at Gate 3',
            'Someone is unwell in Camp Aurora',
            'INC-1',
            'Critical',
            'Serious',
            'on_scene',
            'monitoring',
        ] as $disclosure) {
            $this->assertStringNotContainsString($disclosure, $payload);
        }

        // The same event, read by IC standing, does report all of it — so the
        // absence above is the exclusion rather than an empty event.
        $ic = $this->widgetsFor($scenario['icOperator'], $scenario);

        $this->assertSame('critical', $ic['ic.active_incidents']['attention']);
        $this->assertSame(2, $ic['ic.active_incidents']['metric']['value']);
        $this->assertSame(1, $ic['ic.serious_incidents']['metric']['value']);
        $this->assertSame(1, $ic['ic.on_scene']['metric']['value']);
        $this->assertTrue($ic['ic.monitoring']['quiet']);
        $this->assertSame('No monitoring incidents', $ic['ic.monitoring']['quiet_state']);
    }

    /**
     * An organizer who also holds IC standing reads incident data — as an IC
     * user, through 13.5, which is what the contract's "unless" means.
     */
    public function test_an_organizer_who_also_holds_ic_standing_reads_the_ic_group(): void
    {
        $scenario = $this->scenario();
        $this->givenOpenIncidents($scenario);

        $this->grantRole($scenario['organizer'], $scenario['icTeam'], 'ic_viewer');

        $response = $this->actingAsClient($scenario['organizer'])
            ->getJson("/api/events/{$scenario['event']->id}/dashboard")
            ->assertOk();

        $groups = collect($response->json('groups'))->pluck('group');

        $this->assertTrue($groups->contains('organizer'));
        $this->assertTrue($groups->contains('ic'));
    }

    public function test_organizer_widgets_report_readiness_coverage_and_applications(): void
    {
        $scenario = $this->scenario();

        EventApplication::factory()->count(2)->create([
            'event_id' => $scenario['event']->id,
            'organization_id' => $scenario['organization']->id,
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $widgets = $this->widgetsFor($scenario['organizer'], $scenario);

        $this->assertSame(2, $widgets['org.application_review']['metric']['value']);
        $this->assertFalse($widgets['org.cross_dept_coverage']['quiet']);
        $this->assertSame('Rangers', $widgets['org.cross_dept_coverage']['items'][0]['label']);
        $this->assertFalse($widgets['org.event_readiness']['quiet']);

        // The window is open, which is the one row whose quiet state means "not
        // happening" rather than "nothing wrong".
        $this->assertFalse($widgets['org.operations_window']['quiet']);
        $this->assertSame('Open', $widgets['org.operations_window']['items'][0]['status']);
    }

    /**
     * The Field Report widget is gated a second time, on
     * `field_reports.view_event` (FR-005, FR-006).
     */
    public function test_the_field_report_widget_follows_field_report_visibility(): void
    {
        $scenario = $this->scenario();
        $this->givenOpenIncidents($scenario);

        FieldReport::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'title' => 'Radio interference near the gate',
        ]);

        $operator = $this->widgetsFor($scenario['icOperator'], $scenario);
        $this->assertSame(1, $operator['ic.unresolved_field_reports']['metric']['value']);

        // A department lead holds neither capability and reads neither widget.
        $lead = $this->widgetsFor($scenario['lead'], $scenario);
        $this->assertArrayNotHasKey('ic.unresolved_field_reports', $lead);
        $this->assertArrayNotHasKey('ic.active_incidents', $lead);
    }

    /*
    |--------------------------------------------------------------------------
    | Kiosk widgets (UI contract 13.6)
    |--------------------------------------------------------------------------
    */

    /**
     * The workstation opens the group and the person signed in at it still
     * gates each widget (widget spec 9).
     */
    public function test_the_kiosk_group_opens_on_a_trusted_workstation_and_not_on_a_personal_device(): void
    {
        $scenario = $this->scenario();

        // The same user, on their own device, reads no kiosk group.
        $personal = collect($this->dashboardFor($scenario['logistics'], $scenario)->json('groups'))
            ->pluck('group');
        $this->assertFalse($personal->contains('kiosk'));

        $workstation = SharedWorkstation::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'trusted' => true,
        ]);

        $sessionKey = $this->establishWorkstationSession($workstation, $scenario['logistics']);

        $response = $this->withHeader(SharedWorkstationSessionKey::HEADER, $sessionKey)
            ->getJson("/api/events/{$scenario['event']->id}/dashboard")
            ->assertOk();

        $groups = collect($response->json('groups'))->pluck('group');
        $this->assertTrue($groups->contains('kiosk'));

        $widgets = collect($response->json('widgets'))->keyBy('id');

        // The pin supplies the department, so the desk widgets compile without
        // the request naming one.
        $this->assertSame((string) $scenario['department']->id, $response->json('context.department_id'));
        $this->assertFalse($widgets['kiosk.current_tasks']['quiet']);
        $this->assertFalse($widgets['kiosk.staff_checkin']['quiet']);
        $this->assertSame(1, $widgets['kiosk.staff_checkin']['metric']['value']);
        $this->assertFalse($widgets['kiosk.equipment_returns']['quiet']);

        // The two the device answers for itself are catalogued and not compiled.
        $this->assertFalse($widgets->has('kiosk.node_status'));
        $this->assertFalse($widgets->has('kiosk.switch_user'));

        $inventory = collect($response->json('inventory'))->keyBy('id');
        $this->assertSame('device', $inventory['kiosk.node_status']['evaluation']);
        $this->assertSame('device', $inventory['kiosk.switch_user']['evaluation']);
        $this->assertSame('M14.9', $inventory['kiosk.event_map']['deferred_to']);
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    public function test_a_caller_with_no_standing_in_the_event_has_no_dashboard(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient(User::factory()->create())
            ->getJson("/api/events/{$scenario['event']->id}/dashboard")
            ->assertForbidden();
    }

    public function test_a_department_outside_the_events_organization_is_not_found(): void
    {
        $scenario = $this->scenario();
        $elsewhere = Department::factory()->create();

        $this->actingAsClient($scenario['lead'])
            ->getJson("/api/events/{$scenario['event']->id}/dashboard?department_id={$elsewhere->id}")
            ->assertNotFound();
    }

    public function test_the_read_requires_a_credential(): void
    {
        $scenario = $this->scenario();

        $this->getJson("/api/events/{$scenario['event']->id}/dashboard")
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, array<string, mixed>>
     */
    private function widgetsFor(User $user, array $scenario): array
    {
        return collect($this->dashboardFor($user, $scenario)->json('widgets'))
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function dashboardFor(User $user, array $scenario): TestResponse
    {
        return $this->actingAsClient($user)
            ->getJson(
                "/api/events/{$scenario['event']->id}/dashboard"
                ."?department_id={$scenario['department']->id}",
            )
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function givenOpenIncidents(array $scenario): void
    {
        Incident::factory()->create([
            'event_id' => $scenario['event']->id,
            'incident_number' => 'INC-1',
            'title' => 'Structure fire at Gate 3',
            'status' => Incident::STATUS_ON_SCENE,
            'priority_label' => Incident::PRIORITY_CRITICAL,
            'closed_at' => null,
        ]);

        Incident::factory()->create([
            'event_id' => $scenario['event']->id,
            'incident_number' => 'INC-2',
            'title' => 'Someone is unwell in Camp Aurora',
            'status' => Incident::STATUS_OPEN,
            'priority_label' => Incident::PRIORITY_ROUTINE,
            'closed_at' => null,
        ]);
    }

    private function establishWorkstationSession(SharedWorkstation $workstation, User $subject): string
    {
        $code = app(SharedWorkstationLoginCodeService::class)
            ->generateForUser($subject, User::factory()->create(), $workstation)
            ->plaintextCode;

        $this->app['auth']->forgetGuards();

        return (string) $this->postJson(route('api.auth.shared-workstation-session.store'), [
            'shared_workstation_id' => $workstation->getKey(),
            'code' => $code,
        ])->assertStatus(201)->json('session_key');
    }

    /**
     * One event mid-shift, with an organizer, a department lead, a logistics
     * holder, an Incident Command operator, two rostered staff, and somebody
     * with nothing to do.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-07-04 18:00:00'));

        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $incidentCommand = Department::factory()->for($organization)->create(['name' => 'Incident Command']);
        $organizers = Department::factory()->for($organization)->create(['name' => 'Organizers']);

        /*
         * Published before the event exists. Governance edits are refused while
         * any event of the organization is in its active window (technical spec
         * 21.10), and this scenario's event is mid-window on purpose.
         */
        $policy = PolicyDocument::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Fire Safety Policy',
            'state' => PolicyDocument::STATE_PUBLISHED,
        ]);

        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2027',
            'timezone' => 'America/Los_Angeles',
            'ic_department_id' => $incidentCommand->id,
            'active_event_window_starts_at' => Carbon::parse('2027-07-01 00:00:00'),
            'active_event_window_ends_at' => Carbon::parse('2027-07-10 00:00:00'),
        ]);

        foreach ([$department, $incidentCommand, $organizers] as $participating) {
            EventDepartmentAssignment::factory()->create([
                'event_id' => $event->id,
                'department_id' => $participating->id,
            ]);
        }

        $checkedIn = $this->staffNamed('Vera Checked-In', 'vera');
        $scheduled = $this->staffNamed('Sam Scheduled', 'sam');
        $spare = $this->staffNamed('Ari Spare', 'ari');

        foreach ([$checkedIn, $scheduled, $spare] as $member) {
            app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($member, $department);
        }

        $department->load('defaultTeam');
        $team = $department->defaultTeam;

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2027-07-04 16:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 22:00:00'),
            'capacity' => 3,
        ]);

        foreach ([$checkedIn, $scheduled] as $member) {
            ShiftAssignment::factory()->create([
                'shift_id' => $shift->id,
                'staff_id' => $member->id,
                'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
                'assigned_by_user_id' => null,
                'removed_at' => null,
            ]);
        }

        AttendanceRecord::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $checkedIn->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => Carbon::parse('2027-07-04 16:02:00'),
        ]);

        $radio = EquipmentItem::factory()->forDepartment($department)->checkedOut()->create([
            'name' => 'Radio 12',
            'asset_tag' => 'RDO-12',
        ]);

        EquipmentCheckout::factory()->create([
            'equipment_item_id' => $radio->id,
            'event_id' => $event->id,
            'staff_id' => $checkedIn->id,
            'shift_id' => $shift->id,
        ]);

        /*
         * A policy this department's members must acknowledge. Department-scoped
         * so it reaches exactly the people rostered here, which is what lets one
         * of them stand as the reader with nothing outstanding.
         */
        DocumentAcknowledgmentRequirement::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'document_type' => DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            'document_id' => $policy->id,
        ]);

        $bystander = $this->userForStaff($spare);

        DocumentAcknowledgment::factory()->create([
            'user_id' => $bystander->getKey(),
            'staff_id' => $spare->id,
            'document_type' => DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
            'document_id' => $policy->id,
            'scope_type' => DocumentAcknowledgment::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
        ]);

        $incidentCommandTeam = Team::factory()->for($incidentCommand)->create(['name' => 'IC Watch']);
        $organizerTeam = Team::factory()->for($organizers)->create(['name' => 'Event Organizers']);

        /*
         * The lead and the desk are on separate teams, because a grant belongs
         * to a team rather than to a person: putting both roles on the default
         * team would give every member of it both, and this test is about the
         * two reading different halves of 13.2.
         */
        $leadTeam = Team::factory()->for($department)->create(['name' => 'Ranger Command']);
        $logisticsTeam = Team::factory()->for($department)->create(['name' => 'Ranger Desk']);

        return [
            'organization' => $organization,
            'event' => $event,
            'department' => $department,
            'team' => $team,
            'icTeam' => $incidentCommandTeam,
            'shift' => $shift,
            'checkedIn' => $checkedIn,
            'scheduled' => $this->userForStaff($scheduled),
            'bystander' => $bystander,
            'lead' => $this->userWithRole($leadTeam, 'department_lead'),
            'logistics' => $this->userWithRole($logisticsTeam, 'department_logistics'),
            'organizer' => $this->userWithRole($organizerTeam, 'organizer'),
            'icOperator' => $this->userWithRole($incidentCommandTeam, 'ic_operator'),
        ];
    }

    private function staffNamed(string $legalName, string $handle): Staff
    {
        return Staff::factory()->create([
            'legal_name' => $legalName,
            'preferred_name' => null,
            'handle' => $handle,
        ]);
    }

    /** A login speaking for an existing staff record. */
    private function userForStaff(Staff $staff): User
    {
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        return $user;
    }

    private function userWithRole(Team $team, string $roleCode): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create(['status' => DepartmentMembership::STATUS_ACTIVE]);

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        $this->grantRole($user, $team, $roleCode);

        return $user;
    }

    /** Add a role to a user who already belongs to the team carrying it. */
    private function grantRole(User $user, Team $team, string $roleCode): void
    {
        $staff = $user->staffProfiles()->firstOrFail();

        $membership = TeamMembership::query()
            ->where('team_id', $team->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($membership === null) {
            $departmentMembership = DepartmentMembership::factory()
                ->for($team->department)
                ->for($staff)
                ->create(['status' => DepartmentMembership::STATUS_ACTIVE]);

            TeamMembership::factory()->create([
                'team_id' => $team->id,
                'staff_id' => $staff->id,
                'department_membership_id' => $departmentMembership->id,
            ]);
        }

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
