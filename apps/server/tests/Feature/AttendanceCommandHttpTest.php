<?php

namespace Tests\Feature;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Device;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Node;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceCommandHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_check_in_command_syncs_with_provenance_and_is_idempotent(): void
    {
        [$shift, $staff, , $shiftLead, $device, $node] = $this->scheduledScenario();
        $operationUuid = (string) Str::uuid();

        $payload = [
            'operation_uuid' => $operationUuid,
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'device_created_at' => '2026-07-01T08:03:00Z',
            'origin_device_id' => $device->id,
            'origin_node_id' => $node->id,
        ];

        $this->actingAs($shiftLead)
            ->postJson('/api/commands/check-in-staff', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('current_state', AttendanceRecord::STATE_CHECKED_IN)
            ->assertJsonPath('created_state_change', true);

        $this->actingAs($shiftLead)
            ->postJson('/api/commands/check-in-staff', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('current_state', AttendanceRecord::STATE_CHECKED_IN)
            ->assertJsonPath('created_state_change', false);

        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);

        $operation = AttendanceOperation::query()->firstOrFail();
        $this->assertSame(AuditEvent::SOURCE_SYNC, $operation->source_context);
        $this->assertSame((string) $device->id, (string) $operation->origin_device_id);
        $this->assertSame((string) $node->id, (string) $operation->origin_node_id);

        $audit = AuditEvent::query()
            ->where('action', 'attendance.checked_in')
            ->firstOrFail();
        $this->assertSame(AuditEvent::SOURCE_SYNC, $audit->source_context);
        $this->assertSame((string) $device->id, (string) $audit->actor_device_id);
        $this->assertSame((string) $node->id, (string) $audit->actor_node_id);
    }

    public function test_offline_check_out_command_accepts_supplied_times_without_server_check_in(): void
    {
        [$shift, $staff, , $shiftLead, $device, $node] = $this->scheduledScenario();
        $operationUuid = (string) Str::uuid();

        $this->actingAs($shiftLead)
            ->postJson('/api/commands/check-out-staff', [
                'operation_uuid' => $operationUuid,
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'actual_started_at' => '2026-07-01T08:00:00Z',
                'actual_ended_at' => '2026-07-01T12:15:00Z',
                'device_created_at' => '2026-07-01T12:15:00Z',
                'origin_device_id' => $device->id,
                'origin_node_id' => $node->id,
            ])
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('current_state', AttendanceRecord::STATE_CHECKED_OUT)
            ->assertJsonPath('created_state_change', true)
            ->assertJsonPath('created_hours', true);

        $this->actingAs($shiftLead)
            ->postJson('/api/commands/check-out-staff', [
                'operation_uuid' => $operationUuid,
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'actual_started_at' => '2026-07-01T08:00:00Z',
                'actual_ended_at' => '2026-07-01T12:15:00Z',
                'device_created_at' => '2026-07-01T12:15:00Z',
                'origin_device_id' => $device->id,
                'origin_node_id' => $node->id,
            ])
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('created_state_change', false)
            ->assertJsonPath('created_hours', false);

        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseCount('hours_worked', 1);

        $hours = HoursWorked::query()->firstOrFail();
        $this->assertSame($shift->id, $hours->shift_id);
        $this->assertSame($shift->department_id, $hours->department_id);
        $this->assertSame(255, $hours->minutes_worked);
    }

    public function test_offline_no_show_command_syncs_after_shift_start_and_is_idempotent(): void
    {
        [$shift, $staff, , $shiftLead, $device, $node] = $this->scheduledScenario();
        $operationUuid = (string) Str::uuid();

        $payload = [
            'operation_uuid' => $operationUuid,
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'device_created_at' => '2026-07-01T08:10:00Z',
            'origin_device_id' => $device->id,
            'origin_node_id' => $node->id,
        ];

        $this->actingAs($shiftLead)
            ->postJson('/api/commands/mark-no-show', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('current_state', AttendanceRecord::STATE_NO_SHOW)
            ->assertJsonPath('created_state_change', true);

        $this->actingAs($shiftLead)
            ->postJson('/api/commands/mark-no-show', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('current_state', AttendanceRecord::STATE_NO_SHOW)
            ->assertJsonPath('created_state_change', false);

        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'attendance.marked_no_show')->count());
    }

    public function test_attendance_commands_require_authentication(): void
    {
        $this->postJson('/api/commands/check-in-staff', [
            'operation_uuid' => (string) Str::uuid(),
            'shift_id' => (string) Str::uuid(),
            'staff_id' => (string) Str::uuid(),
            'device_created_at' => '2026-07-01T08:03:00Z',
            'origin_device_id' => (string) Str::uuid(),
            'origin_node_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: ShiftAssignment, 3: User, 4: Device, 5: Node}
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

        $device = Device::factory()->create();
        $node = Node::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
        ]);

        return [$shift, $staff, $assignment, $this->shiftLeadUserFor($department->defaultTeam), $device, $node];
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
            'permission_role_id' => $this->role('shift_lead')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
