<?php

namespace Tests\Feature;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Device;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
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
use App\Services\Attendance\HoursCorrectionService;
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

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/check-in-staff', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('current_state', AttendanceRecord::STATE_CHECKED_IN)
            ->assertJsonPath('created_state_change', true);

        $this->actingAsClient($shiftLead)
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

        $this->actingAsClient($shiftLead)
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

        $this->actingAsClient($shiftLead)
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

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/mark-no-show', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('current_state', AttendanceRecord::STATE_NO_SHOW)
            ->assertJsonPath('created_state_change', true);

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/mark-no-show', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('current_state', AttendanceRecord::STATE_NO_SHOW)
            ->assertJsonPath('created_state_change', false);

        $this->assertDatabaseCount('attendance_operations', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'attendance.marked_no_show')->count());
    }

    /**
     * A client that names no origin node gets this node (M16.21).
     *
     * The web client has no way to learn a node id — nothing publishes one — so
     * a check-in issued from the Logistics Window sends the device it was
     * written on and stops there. The node that received the command is the node
     * it originated at, and provenance records that rather than nothing.
     */
    public function test_check_in_defaults_its_origin_node_to_this_install(): void
    {
        [$shift, $staff, , $shiftLead, $device] = $this->scheduledScenario();
        $local = Node::factory()->create(['is_local' => true]);

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/check-in-staff', [
                'operation_uuid' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'device_created_at' => '2026-07-01T08:03:00Z',
                'origin_device_id' => $device->id,
            ])
            ->assertCreated();

        $operation = AttendanceOperation::query()->firstOrFail();
        $this->assertSame((string) $local->id, (string) $operation->origin_node_id);
        $this->assertSame((string) $device->id, (string) $operation->origin_device_id);
    }

    /**
     * The `correct-hours` command (M18.4; SLB-007, SLB-031, SLB-032;
     * HOURS-007).
     *
     * `HoursCorrectionService` has enforced all of this since M10.6 with no
     * route able to reach it, so what is under test here is the transport: the
     * record addressed by id, both actual times required, the recomputed
     * minutes coming back for the desk to show, and the operation UUID making a
     * repeat the same correction rather than a second one.
     */
    public function test_correct_hours_command_edits_the_recorded_times_and_is_idempotent(): void
    {
        [$shift, $staff, , $shiftLead, $device, $node] = $this->scheduledScenario();
        $hours = $this->recordedHours($shift, $staff, $shiftLead, $device, $node);
        $operationUuid = (string) Str::uuid();

        $payload = [
            'operation_uuid' => $operationUuid,
            'hours_worked_id' => $hours->id,
            'actual_started_at' => '2026-07-01T08:15:00Z',
            'actual_ended_at' => '2026-07-01T12:45:00Z',
            'device_created_at' => '2026-07-02T10:00:00Z',
            'origin_device_id' => $device->id,
        ];

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/correct-hours', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('hours_worked_id', (string) $hours->id)
            ->assertJsonPath('minutes_worked', 270)
            ->assertJsonPath('created_correction', true);

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/correct-hours', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_uuid', $operationUuid)
            ->assertJsonPath('created_correction', false);

        $hours->refresh();
        $this->assertSame(270, $hours->minutes_worked);
        $this->assertSame((string) $shiftLead->id, (string) $hours->corrected_by_user_id);

        // One check-out and one correction: a repeat is the same operation, and
        // the correction is appended beside the check-out rather than over it
        // (SLB-032).
        $this->assertSame(
            [AttendanceOperation::TYPE_CHECK_OUT, AttendanceOperation::TYPE_CORRECT],
            AttendanceOperation::query()->orderBy('created_at')->pluck('operation_type')->all(),
        );
        $this->assertSame(1, AuditEvent::query()->where('action', 'hours.corrected')->count());

        $correction = AttendanceOperation::query()
            ->where('operation_type', AttendanceOperation::TYPE_CORRECT)
            ->firstOrFail();
        // Typed at a desk in front of the node rather than replayed from a
        // queue, so it is an API write where its queued siblings are sync ones.
        $this->assertSame(AuditEvent::SOURCE_API, $correction->source_context);
        $this->assertSame((string) $device->id, (string) $correction->origin_device_id);
    }

    /**
     * HOURS-008 over the wire, in the words SLB-031 asks for.
     *
     * The desk shows the node's sentence as it arrives, so the sentence has to
     * be one an operator can act on: it names the moment the grace period
     * closed, in the event's own time zone, rather than stating that a grace
     * period exists.
     */
    public function test_correct_hours_is_refused_once_the_record_is_frozen(): void
    {
        [$shift, $staff, , $shiftLead, $device, $node] = $this->scheduledScenario();
        $hours = $this->recordedHours($shift, $staff, $shiftLead, $device, $node);

        app(HoursCorrectionService::class)->freezeHours(
            $hours,
            $shiftLead,
            Carbon::parse('2026-07-15 00:00:00'),
        );

        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/correct-hours', [
                'operation_uuid' => (string) Str::uuid(),
                'hours_worked_id' => $hours->id,
                'actual_started_at' => '2026-07-01T08:15:00Z',
                'actual_ended_at' => '2026-07-01T12:45:00Z',
                'device_created_at' => '2026-07-20T10:00:00Z',
                'origin_device_id' => $device->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'The correction grace period closed on 14 Jul 2026 17:00 PDT, so these hours are frozen and can no longer be corrected.',
            );

        $this->assertSame(255, $hours->refresh()->minutes_worked);
        $this->assertSame(0, AuditEvent::query()->where('action', 'hours.corrected')->count());
    }

    public function test_correct_hours_is_refused_without_attendance_authority(): void
    {
        [$shift, $staff, , $shiftLead, $device, $node] = $this->scheduledScenario();
        $hours = $this->recordedHours($shift, $staff, $shiftLead, $device, $node);

        $this->actingAsClient(User::factory()->create())
            ->postJson('/api/commands/correct-hours', [
                'operation_uuid' => (string) Str::uuid(),
                'hours_worked_id' => $hours->id,
                'actual_started_at' => '2026-07-01T08:15:00Z',
                'actual_ended_at' => '2026-07-01T12:45:00Z',
                'device_created_at' => '2026-07-02T10:00:00Z',
                'origin_device_id' => $device->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not authorized to correct hours for this shift.');

        $this->assertSame(255, $hours->refresh()->minutes_worked);
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

        $this->postJson('/api/commands/correct-hours', [
            'operation_uuid' => (string) Str::uuid(),
            'hours_worked_id' => (string) Str::uuid(),
            'actual_started_at' => '2026-07-01T08:15:00Z',
            'actual_ended_at' => '2026-07-01T12:45:00Z',
            'device_created_at' => '2026-07-02T10:00:00Z',
            'origin_device_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    /**
     * The hours a correction edits, created the way the desk creates them.
     *
     * Through the check-out command rather than a factory, because a correction
     * addresses a record the attendance path produced and the transport under
     * test is the pair.
     */
    private function recordedHours(
        Shift $shift,
        Staff $staff,
        User $shiftLead,
        Device $device,
        Node $node,
    ): HoursWorked {
        $this->actingAsClient($shiftLead)
            ->postJson('/api/commands/check-out-staff', [
                'operation_uuid' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'actual_started_at' => '2026-07-01T08:00:00Z',
                'actual_ended_at' => '2026-07-01T12:15:00Z',
                'device_created_at' => '2026-07-01T12:15:00Z',
                'origin_device_id' => $device->id,
                'origin_node_id' => $node->id,
            ])
            ->assertCreated();

        return HoursWorked::query()->firstOrFail();
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

        $actor = $this->shiftLeadUserFor($department->defaultTeam);
        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'staff_id' => $staff->id,
            'last_marked_by_user_id' => $actor->id,
        ]);

        return [$shift, $staff, $assignment, $actor, $device, $node];
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

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
