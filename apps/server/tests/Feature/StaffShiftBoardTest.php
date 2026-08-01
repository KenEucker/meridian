<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\ShiftSignupException;
use App\Services\Status\StaffStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The staff shift board and the two self-service commands over HTTP (M18.2;
 * SHIFT-011 through SHIFT-015, SHIFT-018; requirements 3.12, 5.5).
 *
 * `ShiftSignupService` and `ShiftRemovalService` have enforced these rules since
 * M7 with no way for an ordinary shift to reach them. What is under test here is
 * the reachability and, above all, that the board's account of a shift is the
 * command's account of it: every reason a row gives for being unavailable is a
 * reason the command refuses with, in the same words.
 */
class StaffShiftBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_board_offers_an_eligible_shift_and_the_command_takes_it(): void
    {
        $scenario = $this->scenario();

        $board = $this->actingAsClient($scenario['user'])
            ->getJson("/api/events/{$scenario['event']->id}/shift-board")
            ->assertOk()
            ->assertJsonPath('shifts.0.id', (string) $scenario['shift']->id)
            ->assertJsonPath('shifts.0.title', 'Gate Swing')
            ->assertJsonPath('shifts.0.department_name', 'Gate')
            ->assertJsonPath('shifts.0.can_sign_up', true)
            ->assertJsonPath('shifts.0.signed_up', false)
            ->assertJsonPath('shifts.0.can_withdraw', false)
            ->assertJsonPath('shifts.0.unavailable_reason', null)
            ->assertJsonPath('shifts.0.unavailable_reason_code', null);

        $this->assertSame(1, count($board->json('shifts')));

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/sign-up-for-shift', ['shift_id' => $scenario['shift']->id])
            ->assertCreated()
            ->assertJsonPath('shift_id', (string) $scenario['shift']->id)
            ->assertJsonPath('staff_id', (string) $scenario['staff']->id)
            ->assertJsonPath('assignment_status', ShiftAssignment::STATUS_SIGNED_UP)
            ->assertJsonPath('warnings', []);

        $this->assertDatabaseHas('shift_assignments', [
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        // Signup is immediate (SHIFT-011), so the next read says so rather than
        // reporting something pending.
        $this->actingAsClient($scenario['user'])
            ->getJson("/api/events/{$scenario['event']->id}/shift-board")
            ->assertOk()
            ->assertJsonPath('shifts.0.signed_up', true)
            ->assertJsonPath('shifts.0.can_sign_up', false)
            ->assertJsonPath('shifts.0.can_withdraw', true)
            ->assertJsonPath('shifts.0.assignment_status', ShiftAssignment::STATUS_SIGNED_UP)
            // Already being on a shift is a state the board renders, not a
            // problem it reports.
            ->assertJsonPath('shifts.0.unavailable_reason', null);
    }

    public function test_a_staff_member_withdraws_before_the_cutoff_and_is_refused_after_it(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/sign-up-for-shift', ['shift_id' => $scenario['shift']->id])
            ->assertCreated();

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/withdraw-from-shift', ['shift_id' => $scenario['shift']->id])
            ->assertOk()
            ->assertJsonPath('shift_id', (string) $scenario['shift']->id);

        $this->assertNotNull(
            ShiftAssignment::query()
                ->where('shift_id', $scenario['shift']->id)
                ->where('staff_id', $scenario['staff']->id)
                ->value('removed_at'),
        );

        // Back on, and now past the cutoff: section 3.11 lets staff remove
        // themselves *before* schedule lock, and the desk keeps the authority
        // after it.
        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/sign-up-for-shift', ['shift_id' => $scenario['shift']->id])
            ->assertCreated();

        $scenario['shift']->forceFill(['schedule_lock_at' => Carbon::now()->subHour()])->save();

        $this->actingAsClient($scenario['user'])
            ->getJson("/api/events/{$scenario['event']->id}/shift-board")
            ->assertOk()
            ->assertJsonPath('shifts.0.signed_up', true)
            ->assertJsonPath('shifts.0.schedule_locked', true)
            ->assertJsonPath('shifts.0.can_withdraw', false);

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/withdraw-from-shift', ['shift_id' => $scenario['shift']->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The schedule is locked and cannot be changed.');
    }

    public function test_withdrawing_from_a_shift_the_staff_member_is_not_on_is_refused(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/withdraw-from-shift', ['shift_id' => $scenario['shift']->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not signed up for this shift.');
    }

    /**
     * SHIFT-018: an unavailable shift says why, using the eligibility reasons in
     * requirements 3.12. One case per reason, each asserting the board and the
     * command agree — the same code and the same sentence.
     */
    #[DataProvider('eligibilityDenials')]
    public function test_each_eligibility_denial_reason_renders_on_the_board_and_refuses_the_command(
        string $denial,
        string $reasonCode,
        string $message,
    ): void {
        $scenario = $this->scenario();

        $this->{$denial}($scenario);

        $this->actingAsClient($scenario['user'])
            ->getJson("/api/events/{$scenario['event']->id}/shift-board")
            ->assertOk()
            ->assertJsonPath('shifts.0.can_sign_up', false)
            ->assertJsonPath('shifts.0.unavailable_reason_code', $reasonCode)
            ->assertJsonPath('shifts.0.unavailable_reason', $message);

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/sign-up-for-shift', ['shift_id' => $scenario['shift']->id])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', $reasonCode)
            ->assertJsonPath('message', $message);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function eligibilityDenials(): array
    {
        return [
            'cancelled shift' => [
                'cancelTheShift',
                ShiftSignupException::REASON_CANCELLED_SHIFT,
                'Cancelled shifts do not accept signup.',
            ],
            'signup window closed' => [
                'closeTheSignupWindow',
                ShiftSignupException::REASON_SIGNUP_CLOSED,
                'Shift signup is not currently open.',
            ],
            'schedule locked' => [
                'lockTheSchedule',
                ShiftSignupException::REASON_SCHEDULE_LOCKED,
                'The schedule is locked and cannot be changed.',
            ],
            'not on the eligible team' => [
                'moveTheShiftToAnotherTeam',
                ShiftSignupException::REASON_NOT_ELIGIBLE_TEAM_MEMBER,
                'Staff must belong to the shift eligible team before signup.',
            ],
            'do not staff' => [
                'markDoNotStaff',
                ShiftSignupException::REASON_DO_NOT_STAFF,
                'Do Not Staff records cannot sign up for shifts.',
            ],
            'department ineligible' => [
                'markDepartmentIneligible',
                ShiftSignupException::REASON_DEPARTMENT_INELIGIBLE,
                'Ineligible department status prevents shift signup.',
            ],
            'required training incomplete' => [
                'requireAnIncompleteTraining',
                ShiftSignupException::REASON_MISSING_REQUIRED_TRAINING,
                'Required training must be complete before shift signup.',
            ],
            'required waiver incomplete' => [
                'requireAnIncompleteWaiver',
                ShiftSignupException::REASON_MISSING_REQUIRED_WAIVER,
                'Required waiver must be complete before shift signup.',
            ],
            'shift full' => [
                'fillTheShift',
                ShiftSignupException::REASON_SHIFT_FULL,
                'This shift is full and cannot accept additional signup.',
            ],
        ];
    }

    public function test_an_overlapping_shift_warns_rather_than_blocking(): void
    {
        $scenario = $this->scenario();
        $overlapping = Shift::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'eligible_team_id' => $scenario['department']->defaultTeam->id,
            'title' => 'Gate Overlap',
            'starts_at' => $scenario['shift']->starts_at->copy()->addHour(),
            'ends_at' => $scenario['shift']->ends_at->copy()->addHour(),
            'signup_opens_at' => null,
            'signup_closes_at' => null,
        ]);

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/sign-up-for-shift', ['shift_id' => $scenario['shift']->id])
            ->assertCreated();

        // SHIFT-014: the board says it overlaps and still offers it.
        $board = $this->actingAsClient($scenario['user'])
            ->getJson("/api/events/{$scenario['event']->id}/shift-board")
            ->assertOk();

        $overlapRow = collect($board->json('shifts'))
            ->firstWhere('id', (string) $overlapping->id);

        $this->assertTrue($overlapRow['can_sign_up']);
        $this->assertNull($overlapRow['unavailable_reason']);
        $this->assertSame('schedule_overlap', $overlapRow['overlap_warnings'][0]['code']);
        $this->assertStringContainsString('Gate Swing', $overlapRow['overlap_warnings'][0]['message']);

        // And the command accepts it, carrying the same advisory.
        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/sign-up-for-shift', ['shift_id' => $overlapping->id])
            ->assertCreated()
            ->assertJsonPath('warnings.0.code', 'schedule_overlap');

        $this->assertDatabaseCount('shift_assignments', 2);
    }

    public function test_the_board_carries_only_shifts_in_departments_the_staff_member_belongs_to(): void
    {
        $scenario = $this->scenario();
        $otherDepartment = Department::factory()
            ->for($scenario['event']->organization)
            ->create(['name' => 'Sanctuary']);
        $otherDepartment->load('defaultTeam');

        Shift::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $otherDepartment->id,
            'eligible_team_id' => $otherDepartment->defaultTeam->id,
            'title' => 'Sanctuary Overnight',
        ]);

        $shifts = $this->actingAsClient($scenario['user'])
            ->getJson("/api/events/{$scenario['event']->id}/shift-board")
            ->assertOk()
            ->json('shifts');

        // Not a permission refusal — a shift in a department somebody has no
        // membership in is not a shift they were denied, it is one that is not
        // theirs to see on this surface.
        $this->assertSame(['Gate Swing'], array_column($shifts, 'title'));
    }

    public function test_a_login_with_no_staff_profile_gets_an_empty_board(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient(User::factory()->create())
            ->getJson("/api/events/{$scenario['event']->id}/shift-board")
            ->assertOk()
            ->assertJsonPath('shifts', []);
    }

    public function test_signing_up_for_another_persons_staff_profile_is_refused(): void
    {
        $scenario = $this->scenario();
        $intruder = User::factory()->create();
        $intruderStaff = Staff::factory()->create();
        $intruder->staffProfiles()->attach($intruderStaff->id);
        app(DepartmentMembershipService::class)
            ->assignStaffWithDefaultTeam($intruderStaff, $scenario['department']);

        $this->actingAsClient($intruder)
            ->postJson('/api/commands/sign-up-for-shift', [
                'shift_id' => $scenario['shift']->id,
                'staff_id' => $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The staff profile must belong to the signing-up user.')
            ->assertJsonPath('reason_code', ShiftSignupException::REASON_STAFF_NOT_LINKED_TO_USER);

        $this->assertDatabaseCount('shift_assignments', 0);
    }

    public function test_the_board_and_the_commands_require_a_credential(): void
    {
        $scenario = $this->scenario();

        $this->getJson("/api/events/{$scenario['event']->id}/shift-board")
            ->assertUnauthorized();

        $this->postJson('/api/commands/sign-up-for-shift', ['shift_id' => $scenario['shift']->id])
            ->assertUnauthorized();

        $this->postJson('/api/commands/withdraw-from-shift', ['shift_id' => $scenario['shift']->id])
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function cancelTheShift(array $scenario): void
    {
        $scenario['shift']->forceFill(['cancelled_at' => Carbon::now()])->save();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function closeTheSignupWindow(array $scenario): void
    {
        $scenario['shift']->forceFill([
            'signup_opens_at' => Carbon::now()->addDay(),
            'signup_closes_at' => Carbon::now()->addWeek(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function lockTheSchedule(array $scenario): void
    {
        $scenario['shift']->forceFill(['schedule_lock_at' => Carbon::now()->subHour()])->save();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function moveTheShiftToAnotherTeam(array $scenario): void
    {
        $team = Team::factory()->for($scenario['department'])->create(['code' => 'NIGHT']);
        $scenario['shift']->forceFill(['eligible_team_id' => $team->id])->save();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function markDoNotStaff(array $scenario): void
    {
        StaffOrganizationStatus::query()->updateOrCreate(
            [
                'organization_id' => $scenario['event']->organization_id,
                'staff_id' => $scenario['staff']->id,
            ],
            [
                'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
                'status_reason' => 'Blocked by organizer.',
                'status_changed_at' => Carbon::now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function markDepartmentIneligible(array $scenario): void
    {
        $membership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $scenario['department']->id)
            ->where('staff_id', $scenario['staff']->id)
            ->firstOrFail();

        app(StaffStatusService::class)->transitionDepartmentStatus(
            $membership,
            DepartmentMembership::STATUS_INELIGIBLE,
            'Not eligible for this department.',
        );
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function requireAnIncompleteTraining(array $scenario): void
    {
        $training = Training::factory()
            ->for($scenario['event']->organization)
            ->create(['name' => 'Radio Basics']);

        app(ShiftRequirementService::class)->addTrainingRequirement($scenario['shift'], $training);
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function requireAnIncompleteWaiver(array $scenario): void
    {
        $waiver = Waiver::factory()->for($scenario['event']->organization)->create([
            'name' => 'General Release',
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['event']->organization_id,
        ]);

        app(ShiftRequirementService::class)->addWaiverRequirement($scenario['shift'], $waiver);
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function fillTheShift(array $scenario): void
    {
        $scenario['shift']->forceFill(['capacity' => 1])->save();

        ShiftAssignment::factory()->create([
            'shift_id' => $scenario['shift']->id,
            'staff_id' => Staff::factory()->create()->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);
    }

    /**
     * One event, one department, one team, one staff member on it, and one shift
     * they are eligible for.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-07-04 12:00:00'));

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Dust Camp 2027']);
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $staff = Staff::factory()->create(['legal_name' => 'Ari Gate', 'preferred_name' => null]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Gate Swing',
            'starts_at' => Carbon::parse('2027-07-05 08:00:00'),
            'ends_at' => Carbon::parse('2027-07-05 14:00:00'),
            'signup_opens_at' => null,
            'signup_closes_at' => null,
            'schedule_lock_at' => null,
        ]);

        return compact('organization', 'event', 'department', 'staff', 'user', 'shift');
    }
}
