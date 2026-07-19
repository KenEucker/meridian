<?php

namespace Tests\Feature;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
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
use App\Services\Attendance\AttendanceCheckInService;
use App\Services\Attendance\AttendanceCheckOutException;
use App\Services\Attendance\AttendanceCheckOutService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceCheckOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_hours_worked_table_has_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('hours_worked'));

        foreach ([
            'id',
            'event_id',
            'department_id',
            'shift_id',
            'staff_id',
            'attendance_record_id',
            'actual_started_at',
            'actual_ended_at',
            'minutes_worked',
            'status',
            'corrected_by_user_id',
            'server_corrected_at',
            'frozen_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('hours_worked', $column),
                "hours_worked.{$column} missing",
            );
        }
    }

    public function test_shift_lead_can_check_out_checked_in_staff_and_create_hours(): void
    {
        [$shift, $staff, $assignment, $shiftLead] = $this->scheduledScenario();
        $checkedInAt = Carbon::parse('2026-07-01 08:03:00');
        $actualStartedAt = Carbon::parse('2026-07-01 08:00:00');
        $actualEndedAt = Carbon::parse('2026-07-01 12:07:00');
        $receivedAt = Carbon::parse('2026-07-01 12:07:05');

        app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $checkedInAt,
        );

        $result = app(AttendanceCheckOutService::class)->checkOut(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: $actualStartedAt,
            actualEndedAt: $actualEndedAt,
            deviceCreatedAt: $actualEndedAt,
            serverReceivedAt: $receivedAt,
        );

        $this->assertTrue($result->createdStateChange);
        $this->assertTrue($result->createdHours);
        $this->assertSame(AttendanceOperation::TYPE_CHECK_OUT, $result->operation->operation_type);
        $this->assertSame(AttendanceRecord::STATE_CHECKED_OUT, $result->record->current_state);
        $this->assertTrue($result->record->checked_in_at->equalTo($actualStartedAt));
        $this->assertTrue($result->record->checked_out_at->equalTo($actualEndedAt));
        $this->assertSame($result->record->id, $result->hoursWorked->attendance_record_id);
        $this->assertSame(247, $result->hoursWorked->minutes_worked);
        $this->assertSame(HoursWorked::STATUS_RECORDED, $result->hoursWorked->status);

        $this->assertDatabaseHas('attendance_operations', [
            'id' => $result->operation->id,
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'team_id' => $shift->eligible_team_id,
            'shift_id' => $shift->id,
            'shift_assignment_id' => $assignment->id,
            'staff_id' => $staff->id,
            'operation_type' => AttendanceOperation::TYPE_CHECK_OUT,
            'created_by_user_id' => $shiftLead->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);

        $this->assertDatabaseHas('hours_worked', [
            'id' => $result->hoursWorked->id,
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'attendance_record_id' => $result->record->id,
            'minutes_worked' => 247,
            'status' => HoursWorked::STATUS_RECORDED,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'attendance.checked_out')
            ->where('entity_id', $result->operation->id)
            ->firstOrFail();

        $this->assertSame($shiftLead->id, $audit->actor_user_id);
        $this->assertSame($shift->event->organization_id, $audit->organization_id);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);
        $this->assertSame($result->record->id, $audit->after_json['attendance_record_id']);
        $this->assertSame($result->hoursWorked->id, $audit->after_json['hours_worked_id']);
        $this->assertSame(247, $audit->after_json['minutes_worked']);
    }

    public function test_department_lead_can_check_out_staff_in_their_department(): void
    {
        [$shift, $staff] = $this->scheduledScenario();
        $departmentLead = $this->departmentLeadUserFor($shift->department);
        $checkedInAt = Carbon::parse('2026-07-01 09:00:00');
        $checkedOutAt = Carbon::parse('2026-07-01 11:00:00');

        app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $departmentLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $checkedInAt,
        );

        $result = app(AttendanceCheckOutService::class)->checkOut(
            shift: $shift,
            staff: $staff,
            actor: $departmentLead,
            operationUuid: (string) Str::uuid(),
            actualEndedAt: $checkedOutAt,
        );

        $this->assertSame(AttendanceRecord::STATE_CHECKED_OUT, $result->record->current_state);
        $this->assertSame($departmentLead->id, $result->operation->created_by_user_id);
        $this->assertSame(120, $result->hoursWorked->minutes_worked);
    }

    public function test_check_out_can_record_supplied_actual_start_without_prior_check_in(): void
    {
        [$shift, $staff, $assignment, $shiftLead] = $this->scheduledScenario();
        $actualStartedAt = Carbon::parse('2026-07-01 10:15:00');
        $actualEndedAt = Carbon::parse('2026-07-01 11:45:00');

        $result = app(AttendanceCheckOutService::class)->checkOut(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: $actualStartedAt,
            actualEndedAt: $actualEndedAt,
        );

        $this->assertSame($assignment->id, $result->record->shift_assignment_id);
        $this->assertSame(AttendanceRecord::STATE_CHECKED_OUT, $result->record->current_state);
        $this->assertTrue($result->record->checked_in_at->equalTo($actualStartedAt));
        $this->assertTrue($result->record->checked_out_at->equalTo($actualEndedAt));
        $this->assertSame(90, $result->hoursWorked->minutes_worked);
        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseCount('hours_worked', 1);
    }

    public function test_repeating_same_check_out_operation_uuid_is_idempotent(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $operationUuid = (string) Str::uuid();
        $checkedInAt = Carbon::parse('2026-07-01 08:00:00');
        $checkedOutAt = Carbon::parse('2026-07-01 12:00:00');

        app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $checkedInAt,
        );

        $first = app(AttendanceCheckOutService::class)->checkOut(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: $operationUuid,
            actualEndedAt: $checkedOutAt,
        );
        $second = app(AttendanceCheckOutService::class)->checkOut(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: $operationUuid,
            actualEndedAt: $checkedOutAt,
        );

        $this->assertSame($first->operation->id, $second->operation->id);
        $this->assertSame($first->record->id, $second->record->id);
        $this->assertSame($first->hoursWorked->id, $second->hoursWorked->id);
        $this->assertFalse($second->createdStateChange);
        $this->assertFalse($second->createdHours);
        $this->assertDatabaseCount('attendance_operations', 2);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseCount('hours_worked', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'attendance.checked_out')->count());
    }

    public function test_unauthorized_user_cannot_check_staff_out(): void
    {
        [$shift, $staff] = $this->scheduledScenario();
        $otherUser = User::factory()->create();

        try {
            app(AttendanceCheckOutService::class)->checkOut(
                shift: $shift,
                staff: $staff,
                actor: $otherUser,
                operationUuid: (string) Str::uuid(),
                actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
                actualEndedAt: Carbon::parse('2026-07-01 09:00:00'),
            );

            $this->fail('Unauthorized check-out should have failed.');
        } catch (AttendanceCheckOutException $exception) {
            $this->assertSame('You are not authorized to check staff out for this shift.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('hours_worked', 0);
    }

    public function test_check_out_rejects_cancelled_shift(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $shift->forceFill(['cancelled_at' => now()])->save();

        try {
            app(AttendanceCheckOutService::class)->checkOut(
                shift: $shift->refresh(),
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
                actualEndedAt: Carbon::parse('2026-07-01 09:00:00'),
            );

            $this->fail('Cancelled shift check-out should have failed.');
        } catch (AttendanceCheckOutException $exception) {
            $this->assertSame('Cancelled shifts do not accept attendance check-out.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('hours_worked', 0);
    }

    public function test_check_out_rejects_staff_without_active_shift_assignment(): void
    {
        [$shift, $staff, $assignment, $shiftLead] = $this->scheduledScenario();
        $assignment->forceFill(['removed_at' => now()])->save();

        try {
            app(AttendanceCheckOutService::class)->checkOut(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
                actualEndedAt: Carbon::parse('2026-07-01 09:00:00'),
            );

            $this->fail('Removed assignment check-out should have failed.');
        } catch (AttendanceCheckOutException $exception) {
            $this->assertSame('Staff must have an active assignment for this shift before check-out.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('hours_worked', 0);
    }

    public function test_check_out_requires_existing_or_supplied_actual_start_time(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();

        try {
            app(AttendanceCheckOutService::class)->checkOut(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                actualEndedAt: Carbon::parse('2026-07-01 09:00:00'),
            );

            $this->fail('Check-out without an actual start should have failed.');
        } catch (AttendanceCheckOutException $exception) {
            $this->assertSame('Check-out requires a prior check-in time or supplied actual start time.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('hours_worked', 0);
    }

    public function test_check_out_rejects_invalid_actual_time_range(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();

        app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: Carbon::parse('2026-07-01 08:00:00'),
        );

        try {
            app(AttendanceCheckOutService::class)->checkOut(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                actualStartedAt: Carbon::parse('2026-07-01 10:00:00'),
                actualEndedAt: Carbon::parse('2026-07-01 09:59:00'),
            );

            $this->fail('Invalid actual time range should have failed.');
        } catch (AttendanceCheckOutException $exception) {
            $this->assertSame('Actual end time must be after actual start time.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseCount('hours_worked', 0);
        $this->assertSame(
            AttendanceRecord::STATE_CHECKED_IN,
            AttendanceRecord::query()->firstOrFail()->current_state,
        );
    }

    public function test_check_out_rejects_new_operation_after_staff_is_checked_out(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();

        app(AttendanceCheckOutService::class)->checkOut(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
            actualEndedAt: Carbon::parse('2026-07-01 10:00:00'),
        );

        try {
            app(AttendanceCheckOutService::class)->checkOut(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
                actualEndedAt: Carbon::parse('2026-07-01 10:00:00'),
            );

            $this->fail('Second check-out operation should have failed.');
        } catch (AttendanceCheckOutException $exception) {
            $this->assertSame('Staff is already checked out for this shift.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseCount('hours_worked', 1);
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: ShiftAssignment, 3: User}
     */
    private function scheduledScenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $staff = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Ranger Dirt Day Shift',
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $actor = $this->shiftLeadUserFor($department->defaultTeam);
        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'staff_id' => $staff->id,
            'last_marked_by_user_id' => $actor->id,
        ]);

        return [$shift, $staff, $assignment, $actor];
    }

    private function shiftLeadUserFor(Team $team): User
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
            'permission_role_id' => $this->role('department_logistics')->id,
        ]);

        return $user;
    }

    private function departmentLeadUserFor(Department $department): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $team = Team::factory()->for($department)->create(['name' => $department->name.' Leads']);
        $departmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('department_logistics')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
