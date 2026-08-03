<?php

namespace Tests\Feature;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
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
use App\Services\Attendance\AttendanceCheckInException;
use App\Services\Attendance\AttendanceCheckInService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceCheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_tables_have_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('attendance_operations'));
        $this->assertTrue(Schema::hasTable('attendance_records'));

        foreach ([
            'id',
            'operation_uuid',
            'event_id',
            'department_id',
            'team_id',
            'shift_id',
            'shift_assignment_id',
            'staff_id',
            'operation_type',
            'device_created_at',
            'server_received_at',
            'created_by_user_id',
            'origin_device_id',
            'origin_node_id',
            'source_context',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('attendance_operations', $column),
                "attendance_operations.{$column} missing",
            );
        }

        foreach ([
            'id',
            'event_id',
            'department_id',
            'shift_id',
            'shift_assignment_id',
            'staff_id',
            'current_state',
            'checked_in_at',
            'checked_out_at',
            'no_show_at',
            'corrected_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('attendance_records', $column),
                "attendance_records.{$column} missing",
            );
        }
    }

    public function test_shift_lead_can_check_in_scheduled_staff(): void
    {
        [$shift, $staff, $assignment, $shiftLead] = $this->scheduledScenario();
        $checkedInAt = Carbon::parse('2026-07-01 08:03:00');
        $receivedAt = Carbon::parse('2026-07-01 08:03:05');

        $result = app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $checkedInAt,
            serverReceivedAt: $receivedAt,
        );

        $this->assertTrue($result->createdStateChange);
        $this->assertSame(AttendanceOperation::TYPE_CHECK_IN, $result->operation->operation_type);
        $this->assertSame(AttendanceRecord::STATE_CHECKED_IN, $result->record->current_state);
        $this->assertTrue($result->record->checked_in_at->equalTo($checkedInAt));
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
            'operation_type' => AttendanceOperation::TYPE_CHECK_IN,
            'created_by_user_id' => $shiftLead->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'attendance.checked_in')
            ->where('entity_id', $result->operation->id)
            ->firstOrFail();

        $this->assertSame($shiftLead->id, $audit->actor_user_id);
        $this->assertSame($shift->event->organization_id, $audit->organization_id);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);
        $this->assertSame($result->record->id, $audit->after_json['attendance_record_id']);
    }

    public function test_department_lead_can_check_in_scheduled_staff_in_their_department(): void
    {
        [$shift, $staff] = $this->scheduledScenario();
        $departmentLead = $this->departmentLeadUserFor($shift->department);

        $result = app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $departmentLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: Carbon::parse('2026-07-01 08:05:00'),
        );

        $this->assertSame(AttendanceRecord::STATE_CHECKED_IN, $result->record->current_state);
        $this->assertSame($departmentLead->id, $result->operation->created_by_user_id);
    }

    public function test_unauthorized_user_cannot_check_staff_in(): void
    {
        [$shift, $staff] = $this->scheduledScenario();
        $otherUser = User::factory()->create();

        try {
            app(AttendanceCheckInService::class)->checkIn(
                shift: $shift,
                staff: $staff,
                actor: $otherUser,
                operationUuid: (string) Str::uuid(),
            );

            $this->fail('Unauthorized check-in should have failed.');
        } catch (AttendanceCheckInException $exception) {
            $this->assertSame('You are not authorized to check staff in for this shift.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_check_in_rejects_cancelled_shift(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $shift->forceFill(['cancelled_at' => now()])->save();

        try {
            app(AttendanceCheckInService::class)->checkIn(
                shift: $shift->refresh(),
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
            );

            $this->fail('Cancelled shift check-in should have failed.');
        } catch (AttendanceCheckInException $exception) {
            $this->assertSame('Cancelled shifts do not accept attendance check-in.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_check_in_rejects_staff_without_active_shift_assignment(): void
    {
        [$shift, $staff, $assignment, $shiftLead] = $this->scheduledScenario();
        $assignment->forceFill(['removed_at' => now()])->save();

        try {
            app(AttendanceCheckInService::class)->checkIn(
                shift: $shift,
                staff: $staff,
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
            );

            $this->fail('Removed assignment check-in should have failed.');
        } catch (AttendanceCheckInException $exception) {
            $this->assertSame('Staff must have an active assignment for this shift before check-in.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_repeating_same_operation_uuid_is_idempotent(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $operationUuid = (string) Str::uuid();

        $first = app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: $operationUuid,
            deviceCreatedAt: Carbon::parse('2026-07-01 08:07:00'),
        );
        $second = app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: $operationUuid,
            deviceCreatedAt: Carbon::parse('2026-07-01 08:07:00'),
        );

        $this->assertSame($first->operation->id, $second->operation->id);
        $this->assertSame($first->record->id, $second->record->id);
        $this->assertFalse($second->createdStateChange);
        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'attendance.checked_in')->count());
    }

    public function test_duplicate_check_in_keeps_current_state_idempotent(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $firstAt = Carbon::parse('2026-07-01 08:09:00');

        $first = app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $firstAt,
        );
        $second = app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: Carbon::parse('2026-07-01 08:11:00'),
        );

        $this->assertFalse($second->createdStateChange);
        $this->assertSame($first->record->id, $second->record->id);
        $this->assertTrue($second->record->checked_in_at->equalTo($firstAt));
        $this->assertDatabaseCount('attendance_operations', 2);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_operation_uuid_cannot_be_reused_for_different_staff(): void
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();
        $operationUuid = (string) Str::uuid();

        app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: $operationUuid,
        );

        $otherStaff = Staff::factory()->create();
        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($otherStaff, $shift->department);
        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $otherStaff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => $shiftLead->id,
            'removed_at' => null,
        ]);

        try {
            app(AttendanceCheckInService::class)->checkIn(
                shift: $shift,
                staff: $otherStaff,
                actor: $shiftLead,
                operationUuid: $operationUuid,
            );

            $this->fail('Reused operation UUID should have failed for different staff.');
        } catch (AttendanceCheckInException $exception) {
            $this->assertSame('Attendance operation UUID was already used for different check-in data.', $exception->getMessage());
        }

        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
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
        // A designated shift lead (M11.17): lead membership in the team the
        // shift_lead grant is scoped to, which is what TEAM-015 admits.
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => 'lead',
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('shift_lead')->id,
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
            'permission_role_id' => $this->role('department_lead')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
