<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Credits\CreditCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `department.credits` — credit review for one department at one event
 * (M18.30; UI contract 12.4; CREDIT-004, CREDIT-005; REPORT-006, REPORT-007).
 */
class DepartmentCreditReviewHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 17:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_department_lead_reads_their_own_ledger_with_the_basis_behind_every_number(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['rangersLead'])
            ->getJson($this->path($scenario, $scenario['rangers']))
            ->assertOk();

        $response->assertJsonPath('context.department_label', 'Rangers');
        $response->assertJsonPath('access.organization_wide', false);

        // Vera worked 450 minutes at the organization default rate of 1.000.
        $vera = collect($response->json('entries'))
            ->firstWhere('staff_name', 'Vera');

        $this->assertSame('7.50', $vera['hours']);
        $this->assertSame('1.000', $vera['credit_multiplier']);
        $this->assertSame('7.50', $vera['credits']);
        $this->assertSame('Standard Credit', $vera['credit_policy_name']);
        $this->assertSame('organization', $vera['policy_source']);
        $this->assertNotNull($vera['frozen_at']);

        // Vera 7.5 plus Alma's corrected 10 hours.
        $response->assertJsonPath('totals.credits', '17.50');
        $response->assertJsonPath('totals.staff_count', 2);

        $staff = collect($response->json('staff'))->pluck('staff_name')->all();
        $this->assertSame(['Alma', 'Vera'], $staff);
    }

    /**
     * A re-rated policy does not restate a finished event (CREDIT-004): the
     * entry carries the rate the work was credited at, and this surface reads
     * that rather than the live policy row.
     */
    public function test_re_rating_a_policy_does_not_move_a_frozen_entry(): void
    {
        $scenario = $this->scenario();

        $scenario['defaultPolicy']->forceFill([
            'name' => 'Standard Credit (2027)',
            'credit_multiplier' => '2.000',
        ])->save();

        $response = $this->actingAsClient($scenario['rangersLead'])
            ->getJson($this->path($scenario, $scenario['rangers']))
            ->assertOk();

        $vera = collect($response->json('entries'))->firstWhere('staff_name', 'Vera');

        $this->assertSame('1.000', $vera['credit_multiplier']);
        $this->assertSame('Standard Credit', $vera['credit_policy_name']);
        $response->assertJsonPath('totals.credits', '17.50');
    }

    /**
     * Hours worked and not credited are reported rather than left to look like
     * a department that earned less than it did.
     */
    public function test_uncredited_hours_are_reported_beside_the_totals(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['rangersLead'])
            ->getJson($this->path($scenario, $scenario['rangers']))
            ->assertOk();

        // Uma's hours froze after the calculation run.
        $response->assertJsonPath('outstanding.uncredited_hours_count', 1);
        $response->assertJsonPath('outstanding.open_hours_count', 0);
        $response->assertJsonPath('outstanding.grace_closed', true);
    }

    public function test_a_lead_cannot_read_another_departments_credits(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['rangersLead'])
            ->getJson($this->path($scenario, $scenario['gate']))
            ->assertForbidden();
    }

    public function test_an_organizer_reads_any_department_of_their_own_events(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['organizer'])
            ->getJson($this->path($scenario, $scenario['gate']))
            ->assertOk();

        $response->assertJsonPath('access.organization_wide', true);

        // Rita's 180 minutes at the shift's own 1.500 rate (SHIFT-010).
        $rita = collect($response->json('entries'))->firstWhere('staff_name', 'Rita');
        $this->assertSame('3.00', $rita['hours']);
        $this->assertSame('1.500', $rita['credit_multiplier']);
        $this->assertSame('4.50', $rita['credits']);
        $this->assertSame('shift', $rita['policy_source']);
    }

    public function test_a_staff_member_holding_no_export_capability_is_refused(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['plainStaffUser'])
            ->getJson($this->path($scenario, $scenario['rangers']))
            ->assertForbidden();
    }

    public function test_a_department_of_another_organization_is_not_found(): void
    {
        $scenario = $this->scenario();

        $foreign = Department::factory()
            ->for(Organization::factory()->create())
            ->create(['name' => 'Elsewhere']);

        $this->actingAsClient($scenario['organizer'])
            ->getJson($this->path($scenario, $foreign))
            ->assertNotFound();
    }

    /**
     * The surface reads and writes nothing: no command lives on it, and the
     * calculation the numbers come from stays with the organizers ORG-010 puts
     * it with.
     */
    public function test_the_read_writes_no_record(): void
    {
        $scenario = $this->scenario();

        $before = AuditEvent::query()->count();

        $this->actingAsClient($scenario['rangersLead'])
            ->getJson($this->path($scenario, $scenario['rangers']))
            ->assertOk();

        $this->assertSame($before, AuditEvent::query()->count());
    }

    private function path(array $scenario, Department $department): string
    {
        return sprintf(
            '/api/events/%s/departments/%s/credits',
            $scenario['event']->id,
            $department->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'starts_at' => Carbon::parse('2026-06-17 09:00:00 UTC'),
            'ends_at' => Carbon::parse('2026-06-20 18:00:00 UTC'),
        ]);

        [$rangers, $rangersTeam] = $this->department($organization, 'Rangers', 'RANGERS', 'Dirt');
        [$gate, $gateTeam] = $this->department($organization, 'Gate', 'GATE', 'Greeters');
        [$organizerDepartment] = $this->department($organization, 'Organizers', 'ORG', 'Leads');

        $organizer = $this->userWithRole($organizerDepartment, 'organizer');
        $rangersLead = $this->userWithRole($rangers, 'department_lead');

        $vera = $this->staff($organization, 'Vera Staff', 'Vera', 'vera', $rangers);
        $alma = $this->staff($organization, 'Alma Assigned', 'Alma', 'alma', $rangers);
        $uma = $this->staff($organization, 'Uma Uncredited', 'Uma', 'uma', $rangers);
        $rita = $this->staff($organization, 'Rita Roster', 'Rita', 'rita', $gate);

        $plainStaff = $this->staff($organization, 'Perry Plain', 'Perry', 'perry', $rangers);
        $plainStaffUser = User::factory()->create();
        $plainStaffUser->staffProfiles()->attach($plainStaff->id);

        $openingShift = $this->shift($event, $gate, $gateTeam, 'Gate Opening', '2026-06-18 19:00:00');
        $dayShift = $this->shift($event, $rangers, $rangersTeam, 'Rangers Dirt Day', '2026-06-19 19:00:00');

        $defaultPolicy = CreditPolicy::factory()
            ->for($organization)
            ->multiplier('1.000')
            ->create(['name' => 'Standard Credit']);

        $organization->forceFill(['default_credit_policy_id' => $defaultPolicy->id])->save();

        $gatePolicy = CreditPolicy::factory()
            ->for($organization)
            ->multiplier('1.500')
            ->create(['name' => 'Overnight Gate', 'shift_id' => $openingShift->id]);

        $openingShift->forceFill(['credit_policy_id' => $gatePolicy->id])->save();

        $this->hours($openingShift, $rita, minutes: 180, frozenAt: '2026-06-28 00:00:00');
        $this->hours(
            $dayShift,
            $alma,
            minutes: 600,
            frozenAt: '2026-06-28 00:00:00',
            correctedAt: '2026-06-20 18:00:00',
        );
        $this->hours($dayShift, $vera, minutes: 450, frozenAt: '2026-06-28 00:00:00');

        app(CreditCalculationService::class)->calculateForEvent($event, $organizer);

        // Frozen after the run, so it is owed rather than earned.
        $this->hours($dayShift, $uma, minutes: 300, frozenAt: '2026-07-16 00:00:00');

        return [
            'organization' => $organization->refresh(),
            'event' => $event,
            'rangers' => $rangers,
            'gate' => $gate,
            'defaultPolicy' => $defaultPolicy,
            'organizer' => $organizer,
            'rangersLead' => $rangersLead,
            'plainStaffUser' => $plainStaffUser,
        ];
    }

    /**
     * @return array{0: Department, 1: Team}
     */
    private function department(Organization $organization, string $name, string $code, string $teamName): array
    {
        $department = Department::factory()->for($organization)->create(['name' => $name, 'code' => $code]);
        $team = Team::factory()->for($department)->create(['name' => $teamName, 'is_default' => true]);

        return [$department, $team];
    }

    private function staff(
        Organization $organization,
        string $legalName,
        string $preferredName,
        string $handle,
        Department $department,
    ): Staff {
        $staff = Staff::factory()->create([
            'legal_name' => $legalName,
            'preferred_name' => $preferredName,
            'handle' => $handle,
        ]);

        StaffOrganizationStatus::query()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
            'status_reason' => 'Test setup.',
            'status_changed_at' => now(),
        ]);

        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $this->defaultTeam($department)->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        return $staff;
    }

    /**
     * The grant gets its own team, since a team grant reaches everybody on the
     * team it is attached to.
     */
    private function userWithRole(Department $department, string $roleCode): User
    {
        $team = Team::factory()->for($department)->create([
            'name' => $department->name.' Leads',
            'is_default' => false,
        ]);
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }

    private function defaultTeam(Department $department): Team
    {
        return Team::query()
            ->where('department_id', $department->id)
            ->where('is_default', true)
            ->firstOrFail();
    }

    private function shift(Event $event, Department $department, Team $team, string $title, string $startsAt): Shift
    {
        $start = Carbon::parse($startsAt, 'UTC');

        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(8),
            'capacity' => null,
        ]);
    }

    private function hours(
        Shift $shift,
        Staff $staff,
        int $minutes,
        string $frozenAt,
        ?string $correctedAt = null,
    ): HoursWorked {
        $startedAt = $shift->starts_at->copy();
        $endedAt = $startedAt->copy()->addMinutes($minutes);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
        ]);

        $record = AttendanceRecord::factory()->create([
            'shift_id' => $shift->id,
            'shift_assignment_id' => $assignment->id,
            'staff_id' => $staff->id,
            'current_state' => $correctedAt === null
                ? AttendanceRecord::STATE_CHECKED_OUT
                : AttendanceRecord::STATE_CORRECTED,
            'checked_in_at' => $startedAt,
            'checked_out_at' => $endedAt,
            'corrected_at' => $correctedAt === null ? null : Carbon::parse($correctedAt, 'UTC'),
        ]);

        return HoursWorked::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'attendance_record_id' => $record->id,
            'actual_started_at' => $startedAt,
            'actual_ended_at' => $endedAt,
            'minutes_worked' => $minutes,
            'server_corrected_at' => $correctedAt === null ? null : Carbon::parse($correctedAt, 'UTC'),
            'frozen_at' => Carbon::parse($frozenAt, 'UTC'),
        ]);
    }
}
