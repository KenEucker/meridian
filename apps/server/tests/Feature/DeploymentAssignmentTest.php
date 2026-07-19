<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Deployment;
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
use App\Services\Deployments\DeploymentAssignmentException;
use App\Services\Deployments\DeploymentAssignmentService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeploymentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_tables_have_documented_current_state_fields(): void
    {
        $this->assertTrue(Schema::hasTable('deployments'));
        $this->assertTrue(Schema::hasTable('current_deployment_assignments'));

        foreach ([
            'id',
            'event_id',
            'department_id',
            'name',
            'description',
            'location_details',
            'map_location_id',
            'created_at',
            'updated_at',
            'archived_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('deployments', $column),
                "deployments.{$column} missing",
            );
        }

        foreach ([
            'id',
            'event_id',
            'department_id',
            'shift_id',
            'staff_id',
            'deployment_id',
            'assigned_by_user_id',
            'assigned_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('current_deployment_assignments', $column),
                "current_deployment_assignments.{$column} missing",
            );
        }
    }

    public function test_shift_lead_can_assign_current_deployment_for_rostered_staff(): void
    {
        [$shift, $staff, , $shiftLead, $deployment] = $this->scheduledScenario();
        $assignedAt = Carbon::parse('2026-07-01 09:15:00');

        $result = app(DeploymentAssignmentService::class)->setCurrentDeployment(
            shift: $shift,
            staff: $staff,
            deployment: $deployment,
            actor: $shiftLead,
            assignedAt: $assignedAt,
        );

        $this->assertTrue($result->createdStateChange);
        $this->assertTrue($result->assignment->assigned_at->equalTo($assignedAt));
        $this->assertSame((string) $deployment->id, (string) $result->assignment->deployment_id);
        $this->assertSame((string) $shiftLead->id, (string) $result->assignment->assigned_by_user_id);

        $this->assertDatabaseHas('current_deployment_assignments', [
            'id' => $result->assignment->id,
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'deployment_id' => $deployment->id,
            'assigned_by_user_id' => $shiftLead->id,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'deployment.current_set')
            ->where('entity_id', $result->assignment->id)
            ->firstOrFail();

        $this->assertSame($shiftLead->id, $audit->actor_user_id);
        $this->assertSame($shift->event->organization_id, $audit->organization_id);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);
        $this->assertNull($audit->before_json);
        $this->assertSame($deployment->id, $audit->after_json['deployment_id']);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
    }

    public function test_shift_lead_moves_staff_by_updating_the_single_current_assignment(): void
    {
        [$shift, $staff, , $shiftLead, $firstDeployment, $secondDeployment] = $this->scheduledScenario();

        $first = app(DeploymentAssignmentService::class)->setCurrentDeployment(
            shift: $shift,
            staff: $staff,
            deployment: $firstDeployment,
            actor: $shiftLead,
            assignedAt: Carbon::parse('2026-07-01 09:15:00'),
        );

        $second = app(DeploymentAssignmentService::class)->setCurrentDeployment(
            shift: $shift,
            staff: $staff,
            deployment: $secondDeployment,
            actor: $shiftLead,
            assignedAt: Carbon::parse('2026-07-01 10:45:00'),
        );

        $this->assertTrue($second->createdStateChange);
        $this->assertSame($first->assignment->id, $second->assignment->id);
        $this->assertSame((string) $secondDeployment->id, (string) $second->assignment->deployment_id);
        $this->assertDatabaseCount('current_deployment_assignments', 1);

        $moveAudit = AuditEvent::query()
            ->where('action', 'deployment.current_set')
            ->whereNotNull('before_json')
            ->firstOrFail();

        $this->assertSame($firstDeployment->id, $moveAudit->before_json['deployment_id']);
        $this->assertSame($secondDeployment->id, $moveAudit->after_json['deployment_id']);

        $repeat = app(DeploymentAssignmentService::class)->setCurrentDeployment(
            shift: $shift,
            staff: $staff,
            deployment: $secondDeployment,
            actor: $shiftLead,
            assignedAt: Carbon::parse('2026-07-01 11:00:00'),
        );

        $this->assertFalse($repeat->createdStateChange);
        $this->assertSame(2, AuditEvent::query()->where('action', 'deployment.current_set')->count());
    }

    public function test_department_lead_can_assign_deployment_in_their_department(): void
    {
        [$shift, $staff, , , $deployment] = $this->scheduledScenario();
        $departmentLead = $this->departmentLeadUserFor($shift->department);

        $result = app(DeploymentAssignmentService::class)->setCurrentDeployment(
            shift: $shift,
            staff: $staff,
            deployment: $deployment,
            actor: $departmentLead,
            assignedAt: Carbon::parse('2026-07-01 09:20:00'),
        );

        $this->assertSame((string) $deployment->id, (string) $result->assignment->deployment_id);
        $this->assertSame($departmentLead->id, $result->assignment->assigned_by_user_id);
    }

    public function test_unauthorized_user_cannot_assign_deployment(): void
    {
        [$shift, $staff, , , $deployment] = $this->scheduledScenario();

        try {
            app(DeploymentAssignmentService::class)->setCurrentDeployment(
                shift: $shift,
                staff: $staff,
                deployment: $deployment,
                actor: User::factory()->create(),
                assignedAt: Carbon::parse('2026-07-01 09:20:00'),
            );

            $this->fail('Unauthorized deployment assignment should have failed.');
        } catch (DeploymentAssignmentException $exception) {
            $this->assertSame('You are not authorized to assign deployments for this shift.', $exception->getMessage());
        }

        $this->assertDatabaseCount('current_deployment_assignments', 0);
        $this->assertSame(0, AuditEvent::query()->where('action', 'deployment.current_set')->count());
    }

    public function test_deployment_assignment_requires_active_shift_assignment(): void
    {
        [$shift, $staff, $assignment, $shiftLead, $deployment] = $this->scheduledScenario();
        $assignment->forceFill(['removed_at' => Carbon::parse('2026-07-01 08:30:00')])->save();

        $this->expectException(DeploymentAssignmentException::class);
        $this->expectExceptionMessage('Staff must have an active assignment for this shift before deployment assignment.');

        app(DeploymentAssignmentService::class)->setCurrentDeployment(
            shift: $shift,
            staff: $staff,
            deployment: $deployment,
            actor: $shiftLead,
            assignedAt: Carbon::parse('2026-07-01 09:20:00'),
        );
    }

    public function test_deployment_assignment_rejects_archived_and_cross_scope_deployments(): void
    {
        [$shift, $staff, , $shiftLead, $deployment] = $this->scheduledScenario();
        $deployment->forceFill(['archived_at' => Carbon::parse('2026-07-01 08:30:00')])->save();

        try {
            app(DeploymentAssignmentService::class)->setCurrentDeployment(
                shift: $shift,
                staff: $staff,
                deployment: $deployment->refresh(),
                actor: $shiftLead,
            );

            $this->fail('Archived deployment assignment should have failed.');
        } catch (DeploymentAssignmentException $exception) {
            $this->assertSame('Archived deployments cannot be assigned.', $exception->getMessage());
        }

        $otherDepartment = Department::factory()
            ->for($shift->event->organization)
            ->create();
        $otherDeployment = Deployment::factory()->create([
            'event_id' => $shift->event_id,
            'department_id' => $otherDepartment->id,
        ]);

        try {
            app(DeploymentAssignmentService::class)->setCurrentDeployment(
                shift: $shift,
                staff: $staff,
                deployment: $otherDeployment,
                actor: $shiftLead,
            );

            $this->fail('Cross-scope deployment assignment should have failed.');
        } catch (DeploymentAssignmentException $exception) {
            $this->assertSame('Deployment must belong to the same event and department as the shift.', $exception->getMessage());
        }

        $this->assertDatabaseCount('current_deployment_assignments', 0);
    }

    public function test_http_command_sets_current_deployment(): void
    {
        [$shift, $staff, , $shiftLead, $deployment] = $this->scheduledScenario();

        $payload = [
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'deployment_id' => $deployment->id,
            'assigned_at' => '2026-07-01T09:15:00Z',
        ];

        $this->actingAs($shiftLead)
            ->postJson('/api/commands/set-current-deployment', $payload)
            ->assertCreated()
            ->assertJsonPath('shift_id', $shift->id)
            ->assertJsonPath('staff_id', $staff->id)
            ->assertJsonPath('deployment_id', $deployment->id)
            ->assertJsonPath('created_state_change', true);

        $this->actingAs($shiftLead)
            ->postJson('/api/commands/set-current-deployment', $payload)
            ->assertCreated()
            ->assertJsonPath('created_state_change', false);

        $this->assertDatabaseCount('current_deployment_assignments', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'deployment.current_set')->count());
    }

    public function test_deployment_command_requires_authentication(): void
    {
        $this->postJson('/api/commands/set-current-deployment', [
            'shift_id' => (string) Str::uuid(),
            'staff_id' => (string) Str::uuid(),
            'deployment_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: ShiftAssignment, 3: User, 4: Deployment, 5: Deployment}
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

        $firstDeployment = Deployment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Gate 1',
            'location_details' => 'North entry checkpoint',
        ]);
        $secondDeployment = Deployment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'name' => 'Perimeter North',
            'location_details' => 'North fence line',
        ]);

        return [
            $shift,
            $staff,
            $assignment,
            $this->shiftLeadUserFor($department->defaultTeam),
            $firstDeployment,
            $secondDeployment,
        ];
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
            'permission_role_id' => $this->role('department_operations')->id,
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
            'permission_role_id' => $this->role('department_operations')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
