<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Deployment;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The four department operations reads (M16.21; SLB-001 through SLB-022).
 */
class DepartmentOperationsReadHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_answers_with_the_running_shift_and_its_exceptions(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['logistics'])
            ->getJson($this->path($scenario, 'overview'))
            ->assertOk();

        $response->assertJsonPath('context.event_id', (string) $scenario['event']->id);
        $response->assertJsonPath('context.department_label', 'Rangers');
        $response->assertJsonPath('access.can_manage_attendance', true);
        $response->assertJsonPath('selected_shift_id', (string) $scenario['shift']->id);
        $response->assertJsonPath('on_site_count', 3);

        $assignments = collect($response->json('assignments'));
        $this->assertCount(2, $assignments);
        $this->assertSame(
            AttendanceRecord::STATE_CHECKED_IN,
            $assignments->firstWhere('staff_id', (string) $scenario['checkedIn']->id)['attendance_state'],
        );
        $this->assertSame(
            AttendanceRecord::STATE_SCHEDULED,
            $assignments->firstWhere('staff_id', (string) $scenario['scheduled']->id)['attendance_state'],
        );
        $this->assertSame(
            (string) $scenario['deployment']->id,
            $assignments->firstWhere('staff_id', (string) $scenario['checkedIn']->id)['current_deployment_id'],
        );

        // Capacity 3 against 2 assigned, one of them still not checked in after
        // the shift started, and one radio still out (SLB-002).
        $exceptions = collect($response->json('exceptions'))->pluck('id');
        $this->assertTrue($exceptions->contains('coverage'));
        $this->assertTrue($exceptions->contains('awaiting-check-in'));
        $this->assertTrue($exceptions->contains('equipment-out'));

        $this->assertSame('Radio 12', $response->json('equipment_out.0.item_name'));
        $this->assertSame('Gate 1', $response->json('deployments.0.name'));
    }

    public function test_logistics_indexes_the_department_and_states_why_someone_cannot_leave(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['logistics'])
            ->getJson($this->path($scenario, 'logistics'))
            ->assertOk();

        $staffIds = collect($response->json('searchable_staff'))->pluck('staff_id');
        $this->assertTrue($staffIds->contains((string) $scenario['checkedIn']->id));
        $this->assertTrue($staffIds->contains((string) $scenario['scheduled']->id));

        $equipment = collect($response->json('searchable_equipment'));
        $this->assertSame(
            'Vera Checked-In',
            $equipment->firstWhere('name', 'Radio 12')['holder_name'],
        );
        $this->assertNull($equipment->firstWhere('name', 'Radio 13')['holder_name']);

        $checkedIn = $response->json('staff_workspaces.'.(string) $scenario['checkedIn']->id);
        $this->assertFalse($checkedIn['can_go_off_site']);
        $this->assertSame(
            'Staff must be checked out from department shifts before being marked off-site.',
            $checkedIn['off_site_blocked_reason'],
        );

        $card = collect($checkedIn['shift_cards'])->firstWhere('shift_id', (string) $scenario['shift']->id);
        $this->assertTrue($card['can_check_out']);
        $this->assertFalse($card['can_check_in']);
        $this->assertFalse($card['can_mark_no_show']);

        $scheduled = $response->json('staff_workspaces.'.(string) $scenario['scheduled']->id);
        $scheduledCard = collect($scheduled['shift_cards'])->firstWhere('shift_id', (string) $scenario['shift']->id);
        $this->assertTrue($scheduledCard['can_check_in']);
        $this->assertTrue($scheduledCard['can_mark_no_show']);
        $this->assertFalse($scheduledCard['can_check_out']);

        // On-site, no assignment, shift started, eligible team member: the one
        // case SLB-008 exists for.
        $unscheduled = $response->json('staff_workspaces.'.(string) $scenario['unassigned']->id);
        $this->assertTrue(
            collect($unscheduled['shift_cards'])
                ->firstWhere('shift_id', (string) $scenario['shift']->id)['can_add_to_shift'],
        );

        // Off-site, so nothing is offered and the card is not there to offer it.
        $offSite = $response->json('staff_workspaces.'.(string) $scenario['offSite']->id);
        $this->assertSame([], $offSite['shift_cards']);
        $this->assertTrue($offSite['can_go_off_site']);
    }

    public function test_operations_lists_deployment_options_and_staff_on_shift(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['operations'])
            ->getJson($this->path($scenario, 'operations'))
            ->assertOk();

        $response->assertJsonPath('access.can_assign_deployments', true);
        $response->assertJsonPath('deployments.0.name', 'Gate 1');

        $rows = collect($response->json('rows'));
        $this->assertCount(2, $rows);
        $this->assertSame(
            'Gate 1',
            $rows->firstWhere('staff_id', (string) $scenario['checkedIn']->id)['current_deployment_name'],
        );
        $this->assertNull(
            $rows->firstWhere('staff_id', (string) $scenario['scheduled']->id)['current_deployment_id'],
        );
    }

    public function test_planning_answers_with_identity_free_aggregates_and_narrows_by_team(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['planning'])
            ->getJson($this->path($scenario, 'planning'))
            ->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertCount(2, $rows);

        $row = $rows->firstWhere('shift_id', (string) $scenario['shift']->id);
        $this->assertSame(2, $row['signed_up_or_assigned_count']);
        $this->assertSame(1, $row['checked_in_count']);
        $this->assertSame(0, $row['no_show_count']);
        // Capacity 3 across a six-hour window.
        $this->assertEqualsWithDelta(18, $row['planned_hours'], 0.01);
        $this->assertEqualsWithDelta(2, $row['actual_hours'], 0.01);
        $this->assertEqualsWithDelta(-16, $row['variance_hours'], 0.01);
        $this->assertSame('Under target', $row['status_label']);

        // SLB-019: no identity reaches an aggregate row.
        foreach (['staff_id', 'display_name', 'handle', 'assignment_id', 'signup_id'] as $field) {
            $this->assertStringNotContainsString(
                '"'.$field.'"',
                json_encode($response->json('rows'), JSON_THROW_ON_ERROR),
            );
        }

        $this->actingAsClient($scenario['planning'])
            ->getJson($this->path($scenario, 'planning').'?team_id='.$scenario['otherTeam']->id)
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.title', 'Ranger Command Overnight');
    }

    public function test_a_caller_with_no_department_standing_is_refused(): void
    {
        $scenario = $this->scenario();
        $outsider = User::factory()->create();

        foreach (['overview', 'logistics', 'operations', 'planning'] as $surface) {
            $this->actingAsClient($outsider)
                ->getJson($this->path($scenario, $surface))
                ->assertForbidden();
        }
    }

    public function test_a_department_outside_the_events_organization_is_not_found(): void
    {
        $scenario = $this->scenario();
        $elsewhere = Department::factory()->create();

        $this->actingAsClient($scenario['logistics'])
            ->getJson("/api/events/{$scenario['event']->id}/departments/{$elsewhere->id}/logistics")
            ->assertNotFound();
    }

    public function test_the_reads_require_a_credential(): void
    {
        $scenario = $this->scenario();

        $this->getJson($this->path($scenario, 'overview'))->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function path(array $scenario, string $surface): string
    {
        return "/api/events/{$scenario['event']->id}/departments/{$scenario['department']->id}/{$surface}";
    }

    /**
     * A department mid-shift.
     *
     * One six-hour shift running now with capacity for three and two people on
     * it — one checked in holding a radio and deployed to Gate 1, one still
     * scheduled — plus an on-site department member who is not assigned to it,
     * an off-site member who is, and a completed shift on another team for the
     * Planning Table to aggregate separately.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-07-04 18:00:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create([
            'name' => 'Idaho Decompression 2027',
            'timezone' => 'America/Los_Angeles',
        ]);
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);

        $checkedIn = $this->staffNamed('Vera Checked-In', 'vera');
        $scheduled = $this->staffNamed('Sam Scheduled', 'sam');
        $unassigned = $this->staffNamed('Ari Unassigned', 'ari');
        $offSite = $this->staffNamed('Uma Off-Site', 'uma');

        foreach ([$checkedIn, $scheduled, $unassigned, $offSite] as $member) {
            app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($member, $department);
        }

        $department->load('defaultTeam');
        $team = $department->defaultTeam;
        $otherTeam = Team::factory()->for($department)->create(['name' => 'Command']);

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2027-07-04 16:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 22:00:00'),
            'capacity' => 3,
        ]);

        $overnight = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $otherTeam->id,
            'title' => 'Ranger Command Overnight',
            'starts_at' => Carbon::parse('2027-07-04 04:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 10:00:00'),
            'capacity' => null,
        ]);

        foreach ([$checkedIn, $scheduled, $offSite] as $member) {
            ShiftAssignment::factory()->create([
                'shift_id' => $shift->id,
                'staff_id' => $member->id,
                'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
                'assigned_by_user_id' => null,
                'removed_at' => null,
            ]);
        }

        // Off-site, so the desk offers this one nothing; removing the assignment
        // would remove the reason the card is empty rather than test it.
        ShiftAssignment::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $offSite->id)
            ->update(['removed_at' => Carbon::now()]);

        $record = AttendanceRecord::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $checkedIn->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => Carbon::parse('2027-07-04 16:02:00'),
        ]);

        HoursWorked::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $checkedIn->id,
            'attendance_record_id' => $record->id,
            'minutes_worked' => 120,
        ]);

        foreach ([$checkedIn, $scheduled, $unassigned] as $member) {
            EventDepartmentPresence::factory()->onSite()->create([
                'event_id' => $event->id,
                'department_id' => $department->id,
                'staff_id' => $member->id,
            ]);
        }

        EventDepartmentPresence::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'staff_id' => $offSite->id,
            'current_state' => EventDepartmentPresence::STATE_OFF_SITE,
        ]);

        $radio = EquipmentItem::factory()->forDepartment($department)->checkedOut()->create([
            'name' => 'Radio 12',
            'asset_tag' => 'RDO-12',
        ]);
        EquipmentItem::factory()->forDepartment($department)->create([
            'name' => 'Radio 13',
            'asset_tag' => 'RDO-13',
        ]);

        EquipmentCheckout::factory()->create([
            'equipment_item_id' => $radio->id,
            'event_id' => $event->id,
            'staff_id' => $checkedIn->id,
            'shift_id' => $shift->id,
        ]);

        $deployment = Deployment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Gate 1',
        ]);

        CurrentDeploymentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $checkedIn->id,
            'deployment_id' => $deployment->id,
        ]);

        return [
            'event' => $event,
            'department' => $department,
            'team' => $team,
            'otherTeam' => $otherTeam,
            'shift' => $shift,
            'overnight' => $overnight,
            'deployment' => $deployment,
            'checkedIn' => $checkedIn,
            'scheduled' => $scheduled,
            'unassigned' => $unassigned,
            'offSite' => $offSite,
            'logistics' => $this->userWithRole($team, 'department_logistics'),
            'operations' => $this->userWithRole($team, 'department_operations'),
            'planning' => $this->userWithRole($team, 'department_planning'),
        ];
    }

    /**
     * A staff member who goes by their legal name, so an assertion about a
     * display name is an assertion about the name it was given.
     */
    private function staffNamed(string $legalName, string $handle): Staff
    {
        return Staff::factory()->create([
            'legal_name' => $legalName,
            'preferred_name' => null,
            'handle' => $handle,
        ]);
    }

    private function userWithRole(Team $team, string $roleCode): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $departmentMembership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
