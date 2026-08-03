<?php

namespace Tests\Feature;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
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
use App\Services\Attendance\AttendanceCheckOutService;
use App\Services\Attendance\HoursCorrectionException;
use App\Services\Attendance\HoursCorrectionService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class HoursCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_lead_can_correct_hours_before_freeze_and_audit_before_after(): void
    {
        [$hours, $shiftLead] = $this->checkedOutHoursScenario();
        $correctedStart = Carbon::parse('2026-07-01 08:15:00');
        $correctedEnd = Carbon::parse('2026-07-01 12:45:00');
        $correctedAt = Carbon::parse('2026-07-02 10:00:00');

        $result = app(HoursCorrectionService::class)->correctHours(
            hoursWorked: $hours,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: $correctedStart,
            actualEndedAt: $correctedEnd,
            deviceCreatedAt: $correctedAt,
            serverReceivedAt: $correctedAt,
        );

        $this->assertTrue($result->createdCorrection);
        $this->assertSame(AttendanceOperation::TYPE_CORRECT, $result->operation->operation_type);
        $this->assertSame($hours->id, $result->hoursWorked->id);
        $this->assertSame(270, $result->hoursWorked->minutes_worked);
        $this->assertSame($shiftLead->id, $result->hoursWorked->corrected_by_user_id);
        $this->assertTrue($result->hoursWorked->server_corrected_at->equalTo($correctedAt));
        $this->assertNull($result->hoursWorked->frozen_at);
        $this->assertSame(AttendanceRecord::STATE_CHECKED_OUT, $result->record->current_state);
        $this->assertTrue($result->record->checked_in_at->equalTo($correctedStart));
        $this->assertTrue($result->record->checked_out_at->equalTo($correctedEnd));
        $this->assertTrue($result->record->corrected_at->equalTo($correctedAt));

        $this->assertDatabaseHas('attendance_operations', [
            'id' => $result->operation->id,
            'operation_type' => AttendanceOperation::TYPE_CORRECT,
            'event_id' => $hours->event_id,
            'department_id' => $hours->department_id,
            'shift_id' => $hours->shift_id,
            'staff_id' => $hours->staff_id,
            'created_by_user_id' => $shiftLead->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'hours.corrected')
            ->where('entity_id', $hours->id)
            ->firstOrFail();

        $this->assertSame($shiftLead->id, $audit->actor_user_id);
        $this->assertSame($hours->event->organization_id, $audit->organization_id);
        $this->assertSame($hours->event_id, $audit->event_id);
        $this->assertSame($hours->department_id, $audit->department_id);
        $this->assertSame(240, $audit->before_json['minutes_worked']);
        $this->assertSame(270, $audit->after_json['minutes_worked']);
        $this->assertSame($result->operation->operation_uuid, $audit->after_json['operation_uuid']);
    }

    public function test_department_lead_can_correct_hours_for_their_department(): void
    {
        [$hours] = $this->checkedOutHoursScenario();
        $departmentLead = $this->departmentLeadUserFor($hours->department);

        $result = app(HoursCorrectionService::class)->correctHours(
            hoursWorked: $hours,
            actor: $departmentLead,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
            actualEndedAt: Carbon::parse('2026-07-01 11:00:00'),
        );

        $this->assertSame(180, $result->hoursWorked->minutes_worked);
        $this->assertSame($departmentLead->id, $result->hoursWorked->corrected_by_user_id);
    }

    public function test_correction_rejects_invalid_actual_time_range(): void
    {
        [$hours, $shiftLead] = $this->checkedOutHoursScenario();

        $this->expectException(HoursCorrectionException::class);
        $this->expectExceptionMessage('Actual end time must be after actual start time.');

        app(HoursCorrectionService::class)->correctHours(
            hoursWorked: $hours,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: Carbon::parse('2026-07-01 12:00:00'),
            actualEndedAt: Carbon::parse('2026-07-01 11:59:00'),
        );
    }

    public function test_unauthorized_user_cannot_correct_hours(): void
    {
        [$hours] = $this->checkedOutHoursScenario();
        $otherUser = User::factory()->create();

        $this->expectException(HoursCorrectionException::class);
        $this->expectExceptionMessage('You are not authorized to correct hours for this shift.');

        app(HoursCorrectionService::class)->correctHours(
            hoursWorked: $hours,
            actor: $otherUser,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
            actualEndedAt: Carbon::parse('2026-07-01 11:00:00'),
        );
    }

    public function test_freeze_blocks_later_hours_correction(): void
    {
        [$hours, $shiftLead] = $this->checkedOutHoursScenario();
        $frozenAt = Carbon::parse('2026-07-08 00:00:00');

        $frozen = app(HoursCorrectionService::class)->freezeHours($hours, $shiftLead, $frozenAt);

        $this->assertTrue($frozen->frozen_at->equalTo($frozenAt));

        $freezeAudit = AuditEvent::query()
            ->where('action', 'hours.frozen')
            ->where('entity_id', $hours->id)
            ->firstOrFail();

        $this->assertNull($freezeAudit->before_json['frozen_at']);
        $this->assertSame($frozenAt->toIso8601String(), $freezeAudit->after_json['frozen_at']);

        try {
            app(HoursCorrectionService::class)->correctHours(
                hoursWorked: $hours->refresh(),
                actor: $shiftLead,
                operationUuid: (string) Str::uuid(),
                actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
                actualEndedAt: Carbon::parse('2026-07-01 11:00:00'),
            );

            $this->fail('Frozen hours should reject correction.');
        } catch (HoursCorrectionException $exception) {
            // SLB-031 asks the refusal to state that the grace period has
            // closed. It names the date it closed on, in the event's own zone,
            // because "there is a grace period" is a rule and a date is
            // something a desk can act on.
            $this->assertSame(
                'The correction grace period closed on 7 Jul 2026 17:00 PDT, so these hours are frozen and can no longer be corrected.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(240, $hours->refresh()->minutes_worked);
        $this->assertSame(1, AuditEvent::query()->where('action', 'hours.frozen')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'hours.corrected')->count());
    }

    /**
     * A correction adds to the history rather than replacing it (SLB-032).
     *
     * The prior actual times survive in the audit entry and in the attendance
     * operation the check-out wrote, both of which are still there after the
     * correction has changed the record. That is what makes a corrected total
     * reviewable: somebody reading it later can see what it was as well as what
     * it became, and who moved it.
     */
    public function test_a_correction_leaves_the_prior_values_in_history(): void
    {
        [$hours, $shiftLead] = $this->checkedOutHoursScenario();
        $originalStart = $hours->actual_started_at->copy();
        $originalEnd = $hours->actual_ended_at->copy();

        app(HoursCorrectionService::class)->correctHours(
            hoursWorked: $hours,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: Carbon::parse('2026-07-01 08:15:00'),
            actualEndedAt: Carbon::parse('2026-07-01 12:45:00'),
        );

        $audit = AuditEvent::query()
            ->where('action', 'hours.corrected')
            ->where('entity_id', $hours->id)
            ->firstOrFail();

        $this->assertSame($originalStart->toIso8601String(), $audit->before_json['actual_started_at']);
        $this->assertSame($originalEnd->toIso8601String(), $audit->before_json['actual_ended_at']);
        $this->assertSame(240, $audit->before_json['minutes_worked']);
        $this->assertNull($audit->before_json['operation_uuid']);

        $this->assertSame(
            Carbon::parse('2026-07-01 08:15:00')->toIso8601String(),
            $audit->after_json['actual_started_at'],
        );
        $this->assertSame(
            Carbon::parse('2026-07-01 12:45:00')->toIso8601String(),
            $audit->after_json['actual_ended_at'],
        );

        // The check-out operation is still there beside the correction: nothing
        // in this path rewrites or removes an operation already accepted.
        $this->assertSame(
            [AttendanceOperation::TYPE_CHECK_OUT, AttendanceOperation::TYPE_CORRECT],
            AttendanceOperation::query()
                ->where('shift_id', $hours->shift_id)
                ->where('staff_id', $hours->staff_id)
                ->orderBy('created_at')
                ->pluck('operation_type')
                ->all(),
        );
    }

    public function test_repeating_same_correction_operation_uuid_is_idempotent(): void
    {
        [$hours, $shiftLead] = $this->checkedOutHoursScenario();
        $operationUuid = (string) Str::uuid();
        $correctedStart = Carbon::parse('2026-07-01 08:00:00');
        $correctedEnd = Carbon::parse('2026-07-01 11:15:00');

        $first = app(HoursCorrectionService::class)->correctHours(
            hoursWorked: $hours,
            actor: $shiftLead,
            operationUuid: $operationUuid,
            actualStartedAt: $correctedStart,
            actualEndedAt: $correctedEnd,
        );
        $second = app(HoursCorrectionService::class)->correctHours(
            hoursWorked: $hours->refresh(),
            actor: $shiftLead,
            operationUuid: $operationUuid,
            actualStartedAt: $correctedStart,
            actualEndedAt: $correctedEnd,
        );

        $this->assertSame($first->operation->id, $second->operation->id);
        $this->assertSame($first->hoursWorked->id, $second->hoursWorked->id);
        $this->assertFalse($second->createdCorrection);
        $this->assertDatabaseCount('attendance_operations', 2);
        $this->assertSame(1, AuditEvent::query()->where('action', 'hours.corrected')->count());
    }

    /**
     * @return array{0: HoursWorked, 1: User}
     */
    private function checkedOutHoursScenario(): array
    {
        [$shift, $staff, , $shiftLead] = $this->scheduledScenario();

        $result = app(AttendanceCheckOutService::class)->checkOut(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: Carbon::parse('2026-07-01 08:00:00'),
            actualEndedAt: Carbon::parse('2026-07-01 12:00:00'),
        );

        return [$result->hoursWorked->load(['event', 'department']), $shiftLead];
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
