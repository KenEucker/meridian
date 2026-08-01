<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
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
 * Presence and unscheduled shift addition over HTTP (M16.21; SLB-008, SLB-015
 * through SLB-018; technical spec 20.3, 20.5).
 *
 * The domain services behind these have been enforcing their rules since M10.4
 * and M10.6 with nothing able to reach them. These tests are about the transport
 * and about the refusals arriving in the words the service wrote them in.
 */
class DepartmentOperationsCommandHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_logistics_marks_a_department_member_on_site_and_back_off(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-on-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('current_state', EventDepartmentPresence::STATE_ON_SITE)
            ->assertJsonPath('created_state_change', true);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-off-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('current_state', EventDepartmentPresence::STATE_OFF_SITE);

        $this->assertDatabaseCount('event_department_presences', 1);
    }

    public function test_going_off_site_is_refused_in_the_services_own_words_while_checked_in(): void
    {
        $scenario = $this->scenario();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        AttendanceRecord::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => Carbon::now(),
        ]);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/mark-staff-off-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Staff must be checked out from department shifts before being marked off-site.',
            );
    }

    public function test_presence_is_refused_without_the_logistics_role(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient(User::factory()->create())
            ->postJson('/api/commands/mark-staff-on-site', [
                'event_id' => $scenario['event']->id,
                'department_id' => $scenario['department']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not authorized to manage department presence.');
    }

    public function test_an_on_site_staff_member_is_added_to_a_running_shift(): void
    {
        $scenario = $this->scenario();

        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('shift_id', (string) $scenario['shift']->id)
            ->assertJsonPath('assignment_status', ShiftAssignment::STATUS_ASSIGNED)
            ->assertJsonPath('warnings', []);

        $this->assertDatabaseHas('shift_assignments', [
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'removed_at' => null,
        ]);
    }

    public function test_adding_an_off_site_staff_member_to_a_shift_is_refused(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['logistics'])
            ->postJson('/api/commands/add-staff-to-shift', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Staff must be marked on-site with this department before unscheduled shift addition.',
            );

        $this->assertDatabaseCount('shift_assignments', 0);
    }

    public function test_the_commands_require_a_credential(): void
    {
        $scenario = $this->scenario();

        $this->postJson('/api/commands/mark-staff-on-site', [
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'staff_id' => $scenario['staff']->id,
        ])->assertUnauthorized();

        $this->postJson('/api/commands/add-staff-to-shift', [
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
        ])->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-07-04 18:00:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $staff = Staff::factory()->create(['legal_name' => 'Ari Ranger', 'preferred_name' => null]);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2027-07-04 16:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 22:00:00'),
        ]);

        return [
            'event' => $event,
            'department' => $department,
            'shift' => $shift,
            'staff' => $staff,
            'logistics' => $this->logisticsUserFor($department->defaultTeam),
        ];
    }

    private function logisticsUserFor(Team $team): User
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
            'permission_role_id' => PermissionRole::query()
                ->where('code', 'department_logistics')
                ->firstOrFail()
                ->id,
        ]);

        return $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
