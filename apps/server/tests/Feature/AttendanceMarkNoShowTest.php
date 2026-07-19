<?php

namespace Tests\Feature;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Attendance\AttendanceMarkNoShowException;
use App\Services\Attendance\AttendanceMarkNoShowService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceMarkNoShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_lead_can_mark_staff_no_show_after_shift_start(): void
    {
        [$shift, $staff, $assignment, $shiftLead] = $this->scheduledScenario();
        $noShowAt = Carbon::parse('2026-07-01 08:10:00');
        $receivedAt = Carbon::parse('2026-07-01 08:10:05');

        $result = app(AttendanceMarkNoShowService::class)->markNoShow(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $noShowAt,
            serverReceivedAt: $receivedAt,
        );

        $this->assertTrue($result->createdStateChange);
        $this->assertSame(AttendanceOperation::TYPE_MARK_NO_SHOW, $result->operation->operation_type);
        $this->assertSame(AttendanceRecord::STATE_NO_SHOW, $result->record->current_state);
        $this->assertTrue($result->record->no_show_at->equalTo($noShowAt));
        $this->assertNull($result->record->checked_in_at);
        $this->assertNull($result->record->checked_out_at);
        $this->assertSame((string) $assignment->id, (string) $result->operation->shift_assignment_id);
        $this->assertSame((string) $assignment->id, (string) $result->record->shift_assignment_id);

        $this->assertDatabaseHas('attendance_operations', [
            'id' => $result->operation->id,
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'team_id' => $shift->eligible_team_id,
            'shift_id' => $shift->id,
            'shift_assignment_id' => $assignment->id,
            'staff_id' => $staff->id,
            'operation_type' => AttendanceOperation::TYPE_MARK_NO_SHOW,
            'created_by_user_id' => $shiftLead->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'attendance.marked_no_show')
            ->where('entity_id', $result->operation->id)
            ->firstOrFail();

        $this->assertSame($shiftLead->id, $audit->actor_user_id);
        $this->assertSame($shift->event->organization_id, $audit->organization_id);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);
        $this->assertSame($result->record->id, $audit->after_json['attendance_record_id']);
        $this->assertSame(AttendanceRecord::STATE_NO_SHOW, $audit->after_json['current_state']);
    }

    public function test_department_lead_can_mark_staff_no_show_in_their_department(): void
    {
        [$shift, $staff] = $this->scheduledScenario();
        $departmentLead = $this->departmentLeadUserFor($shift->department);

        $result = app(AttendanceMarkNoShowService::class)->markNoShow(
            shift: $shift,
            staff: $staff,
            actor: $departmentLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: Carbon::parse('2026-07-01 08:20:00'),
        );

        $this->assertSame(AttendanceRecord::STATE_NO_SHOW, $result->record->current_state);
        $this->assertSame($departmentLead->id, $result->operation->created_by_user_id);
    }

    public function test_repeating_same_no_show_operation_uuid_is_idempotent(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $operationUuid = (string) Str::uuid();
        $noShowAt = Carbon::parse('2026-07-01 08:25:00');

        $first = app(AttendanceMarkNoShowService::class)->markNoShow(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: $operationUuid,
            deviceCreatedAt: $noShowAt,
        );
        $second = app(AttendanceMarkNoShowService::class)->markNoShow(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: $operationUuid,
            deviceCreatedAt: $noShowAt,
        );

        $this->assertSame($first->operation->id, $second->operation->id);
        $this->assertSame($first->record->id, $second->record->id);
        $this->assertFalse($second->createdStateChange);
        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'attendance.marked_no_show')->count());
    }

    public function test_duplicate_no_show_keeps_current_state_idempotent(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $firstAt = Carbon::parse('2026-07-01 08:30:00');
        $secondAt = Carbon::parse('2026-07-01 08:35:00');

        $first = app(AttendanceMarkNoShowService::class)->markNoShow(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $firstAt,
        );
        $second = app(AttendanceMarkNoShowService::class)->markNoShow(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $secondAt,
        );

        $this->assertNotSame($first->operation->id, $second->operation->id);
        $this->assertSame($first->record->id, $second->record->id);
        $this->assertFalse($second->createdStateChange);
        $this->assertTrue($second->record->no_show_at->equalTo($secondAt));
        $this->assertDatabaseCount('attendance_operations', 2);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(2, AuditEvent::query()->where('action', 'attendance.marked_no_show')->count());
    }

    public function test_unauthorized_user_cannot_mark_staff_no_show(): void
    {
        [$shift, $staff] = $this->scheduledScenario();
        $otherUser = User::factory()->create();

        try {
            app(AttendanceMarkNoShowService::class)->markNoShow(
                shift: $shift,
                staff: $staff,
                actor: $otherUser,
                operationUuid: (string) Str::uuid(),
                deviceCreatedAt: Carbon::parse('2026-07-01 08:30:00'),
            );

            $this->fail('Unauthorized no-show should have failed.');
        } catch (AttendanceMarkNoShowException $exception) {
            $this->assertSame('You are not authorized to mark staff as no-show for this shift.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_mark_no_show_rejects_cancelled_shift(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $shift->forceFill(['cancelled_at' => now()])->save();

        try {
            app(AttendanceMarkNoShowService::class)->markNoShow(
                shift: $shift->refresh(),
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                deviceCreatedAt: Carbon::parse('2026-07-01 08:30:00'),
            );

            $this->fail('Cancelled shift no-show should have failed.');
        } catch (AttendanceMarkNoShowException $exception) {
            $this->assertSame('Cancelled shifts do not accept no-show attendance operations.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_mark_no_show_rejects_staff_without_active_shift_assignment(): void
    {
        [$shift, $staff, $assignment, $shiftLead] = $this->scheduledScenario();
        $assignment->forceFill(['removed_at' => now()])->save();

        try {
            app(AttendanceMarkNoShowService::class)->markNoShow(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                deviceCreatedAt: Carbon::parse('2026-07-01 08:30:00'),
            );

            $this->fail('Removed assignment no-show should have failed.');
        } catch (AttendanceMarkNoShowException $exception) {
            $this->assertSame(
                'Staff must have an active assignment for this shift before being marked no-show.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_mark_no_show_rejects_attempt_before_shift_start(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();

        try {
            app(AttendanceMarkNoShowService::class)->markNoShow(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                deviceCreatedAt: $shift->starts_at->copy()->subMinute(),
            );

            $this->fail('Pre-start no-show should have failed.');
        } catch (AttendanceMarkNoShowException $exception) {
            $this->assertSame('Staff can only be marked no-show after the shift has started.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_mark_no_show_rejects_when_staff_is_already_checked_in(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();

        AttendanceRecord::factory()->create([
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => Carbon::parse('2026-07-01 08:05:00'),
            'checked_out_at' => null,
            'no_show_at' => null,
            'corrected_at' => null,
        ]);

        try {
            app(AttendanceMarkNoShowService::class)->markNoShow(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                deviceCreatedAt: Carbon::parse('2026-07-01 08:35:00'),
            );

            $this->fail('Checked-in no-show should have failed.');
        } catch (AttendanceMarkNoShowException $exception) {
            $this->assertSame(
                'Staff cannot be marked no-show after check-in or check-out attendance has been recorded.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(
            AttendanceRecord::STATE_CHECKED_IN,
            AttendanceRecord::query()->firstOrFail()->current_state,
        );
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
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        return [$shift, $staff, $assignment, $this->shiftLeadUserFor($department->defaultTeam)];
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
