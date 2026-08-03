<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorized attendance manager resolution (M18.13; TEAM-015; SLB-007,
 * SLB-029; HOURS-007).
 *
 * "Authorized attendance manager" is one population — `department_logistics`
 * holders together with department leads and shift leads for the department —
 * and check-in, check-out, mark-no-show, and hours correction all answer to
 * it. Each case here asserts the four operations agree before asserting what
 * the shared answer is, so a divergence fails on its own terms rather than as
 * a side effect of one operation's test.
 */
class AttendanceManagerResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_logistics_holders_are_attendance_managers(): void
    {
        [$shift, $department] = $this->departmentWithShift();
        $user = $this->memberWithGrant($department, 'department_logistics');

        $this->assertAgreedAuthority(true, $user, $shift);
    }

    public function test_department_leads_are_attendance_managers(): void
    {
        [$shift, $department] = $this->departmentWithShift();
        $user = $this->memberWithGrant($department, 'department_lead');

        $this->assertAgreedAuthority(true, $user, $shift);
    }

    public function test_designated_shift_leads_are_attendance_managers(): void
    {
        [$shift, $department] = $this->departmentWithShift();
        $user = $this->memberWithGrant($department, 'shift_lead', membershipRole: 'lead');

        $this->assertAgreedAuthority(true, $user, $shift);
    }

    public function test_a_plain_member_of_a_shift_lead_team_is_not_an_attendance_manager(): void
    {
        [$shift, $department] = $this->departmentWithShift();

        // The team carries the shift_lead grant, but this membership is not
        // designated lead, so the role never resolves (M11.17).
        $user = $this->memberWithGrant($department, 'shift_lead', membershipRole: 'member');

        $this->assertAgreedAuthority(false, $user, $shift);
    }

    public function test_an_attendance_manager_of_another_department_is_refused(): void
    {
        [$shift, $department, $organization] = $this->departmentWithShift();
        $otherDepartment = Department::factory()->for($organization)->create();
        $user = $this->memberWithGrant($otherDepartment, 'department_logistics');

        $this->assertAgreedAuthority(false, $user, $shift);
    }

    public function test_ordinary_department_staff_are_not_attendance_managers(): void
    {
        [$shift, $department] = $this->departmentWithShift();
        $user = $this->memberWithGrant($department, null);

        $this->assertAgreedAuthority(false, $user, $shift);
    }

    /**
     * The four operations agree on who is authorized, and the shared answer
     * matches the resolution TEAM-015 specifies.
     */
    private function assertAgreedAuthority(bool $expected, User $user, Shift $shift): void
    {
        $access = app(AttendanceCheckInAccess::class);

        $answers = [
            'check-in' => $access->canCheckInForShift($user, $shift),
            'check-out' => $access->canCheckOutForShift($user, $shift),
            'mark-no-show' => $access->canMarkNoShowForShift($user, $shift),
            'hours-correction' => $access->canCorrectHoursForShift($user, $shift),
        ];

        $this->assertCount(
            1,
            array_unique($answers),
            'The four attendance operations must agree on who is authorized: '.json_encode($answers),
        );

        foreach ($answers as $operation => $answer) {
            $this->assertSame($expected, $answer, "Unexpected {$operation} authority.");
        }
    }

    /**
     * @return array{0: Shift, 1: Department, 2: Organization}
     */
    private function departmentWithShift(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
        ]);

        return [$shift, $department, $organization];
    }

    /**
     * A user who is an active member of a team in the department, optionally
     * with a role granted to that team.
     */
    private function memberWithGrant(
        Department $department,
        ?string $roleCode,
        string $membershipRole = 'member',
    ): User {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $team = Team::factory()->for($department)->create();
        $departmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => $membershipRole,
        ]);

        if ($roleCode !== null) {
            TeamGrant::factory()->create([
                'team_id' => $team->id,
                'permission_role_id' => PermissionRole::query()
                    ->where('code', $roleCode)
                    ->firstOrFail()
                    ->id,
            ]);
        }

        return $user;
    }
}
