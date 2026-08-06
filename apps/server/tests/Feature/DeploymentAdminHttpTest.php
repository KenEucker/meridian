<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Deployment;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `department.deployments` — maintaining a department's deployment options
 * (M18.30; SLB-009, SLB-010).
 */
class DeploymentAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_read_carries_archived_options_and_how_many_staff_are_at_each(): void
    {
        $scenario = $this->scenario();

        $gate = Deployment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'name' => 'Gate 1',
        ]);

        Deployment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'name' => 'Retired Post',
            'archived_at' => now(),
        ]);

        CurrentDeploymentAssignment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'deployment_id' => $gate->id,
        ]);

        $response = $this->actingAsClient($scenario['operations'])
            ->getJson($this->path($scenario))
            ->assertOk();

        $rows = collect($response->json('deployments'));
        $this->assertSame(['Gate 1', 'Retired Post'], $rows->pluck('name')->all());
        $this->assertSame(1, $rows->firstWhere('name', 'Gate 1')['assigned_staff_count']);
        $this->assertNull($rows->firstWhere('name', 'Gate 1')['archived_at']);
        $this->assertNotNull($rows->firstWhere('name', 'Retired Post')['archived_at']);
    }

    public function test_department_operations_creates_a_deployment_and_it_is_audited(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAsClient($scenario['operations'])
            ->postJson('/api/commands/create-deployment', [
                'event_id' => (string) $scenario['event']->id,
                'department_id' => (string) $scenario['department']->id,
                'name' => '  Gate 1  ',
                'description' => 'Main entrance',
                'location_details' => '',
            ])
            ->assertCreated();

        $deployment = Deployment::query()->findOrFail($response->json('id'));

        $this->assertSame('Gate 1', $deployment->name);
        $this->assertSame('Main entrance', $deployment->description);
        // An emptied field reads as "none written" rather than as a blank one.
        $this->assertNull($deployment->location_details);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'deployment.created',
            'entity_id' => (string) $deployment->id,
            'event_id' => (string) $scenario['event']->id,
            'department_id' => (string) $scenario['department']->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);
    }

    public function test_a_department_lead_may_also_maintain_the_list(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['lead'])
            ->postJson('/api/commands/create-deployment', [
                'event_id' => (string) $scenario['event']->id,
                'department_id' => (string) $scenario['department']->id,
                'name' => 'Gate 2',
            ])
            ->assertCreated();
    }

    public function test_a_second_deployment_of_the_same_name_is_refused_whatever_its_casing(): void
    {
        $scenario = $this->scenario();

        Deployment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'name' => 'Gate 1',
        ]);

        $this->actingAsClient($scenario['operations'])
            ->postJson('/api/commands/create-deployment', [
                'event_id' => (string) $scenario['event']->id,
                'department_id' => (string) $scenario['department']->id,
                'name' => 'gate 1',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This department already has a deployment named gate 1 at this event.',
            );

        // Another department at the same event names its own gate freely.
        $this->actingAsClient($scenario['otherOperations'])
            ->postJson('/api/commands/create-deployment', [
                'event_id' => (string) $scenario['event']->id,
                'department_id' => (string) $scenario['otherDepartment']->id,
                'name' => 'Gate 1',
            ])
            ->assertCreated();
    }

    /**
     * Archiving a deployment somebody is standing at would leave the Operations
     * Center naming a place it can no longer offer, so it is refused with the
     * count the operator has to act on.
     */
    public function test_a_deployment_holding_staff_cannot_be_archived_until_they_are_moved(): void
    {
        $scenario = $this->scenario();

        $gate = Deployment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'name' => 'Gate 1',
        ]);

        $assignment = CurrentDeploymentAssignment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'deployment_id' => $gate->id,
        ]);

        $this->actingAsClient($scenario['operations'])
            ->postJson('/api/commands/archive-deployment', [
                'deployment_id' => (string) $gate->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'One staff member is currently deployed here. Move them to another deployment before archiving this one.',
            );

        $this->assertNull($gate->refresh()->archived_at);

        $assignment->delete();

        $this->actingAsClient($scenario['operations'])
            ->postJson('/api/commands/archive-deployment', [
                'deployment_id' => (string) $gate->id,
            ])
            ->assertOk();

        $this->assertNotNull($gate->refresh()->archived_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'deployment.archived',
            'entity_id' => (string) $gate->id,
        ]);
    }

    public function test_an_archived_deployment_is_restored_and_stays_on_the_assignments_that_carry_it(): void
    {
        $scenario = $this->scenario();

        $gate = Deployment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'name' => 'Gate 1',
            'archived_at' => now(),
        ]);

        $this->actingAsClient($scenario['operations'])
            ->postJson('/api/commands/restore-deployment', [
                'deployment_id' => (string) $gate->id,
            ])
            ->assertOk()
            ->assertJsonPath('archived_at', null);

        $this->assertNull($gate->refresh()->archived_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'deployment.restored',
            'entity_id' => (string) $gate->id,
        ]);
    }

    public function test_renaming_moves_every_reference_at_once(): void
    {
        $scenario = $this->scenario();

        $gate = Deployment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'name' => 'Gate 1',
        ]);

        CurrentDeploymentAssignment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'shift_id' => $scenario['shift']->id,
            'staff_id' => $scenario['staff']->id,
            'deployment_id' => $gate->id,
        ]);

        $this->actingAsClient($scenario['operations'])
            ->postJson('/api/commands/update-deployment', [
                'deployment_id' => (string) $gate->id,
                'name' => 'North Gate',
                'description' => null,
                'location_details' => 'Past the sign',
            ])
            ->assertOk();

        $gate->refresh();
        $this->assertSame('North Gate', $gate->name);
        $this->assertSame('Past the sign', $gate->location_details);

        // Nothing carries a copy of the label, so the assignment now names the
        // new one without being touched.
        $response = $this->actingAsClient($scenario['operations'])
            ->getJson($this->path($scenario))
            ->assertOk();

        $this->assertSame(
            1,
            collect($response->json('deployments'))->firstWhere('name', 'North Gate')['assigned_staff_count'],
        );
    }

    public function test_a_lead_of_another_department_cannot_maintain_this_one(): void
    {
        $scenario = $this->scenario();

        $gate = Deployment::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'name' => 'Gate 1',
        ]);

        // The read.
        $this->actingAsClient($scenario['otherOperations'])
            ->getJson($this->path($scenario))
            ->assertForbidden();

        // And the writes, which resolve the department from the deployment
        // itself rather than from anything the client sent.
        $this->actingAsClient($scenario['otherOperations'])
            ->postJson('/api/commands/update-deployment', [
                'deployment_id' => (string) $gate->id,
                'name' => 'Their Gate',
            ])
            ->assertForbidden();

        $this->assertSame('Gate 1', $gate->refresh()->name);
    }

    public function test_a_department_member_with_no_operations_or_admin_standing_is_refused(): void
    {
        $scenario = $this->scenario();

        $member = User::factory()->create();
        $member->staffProfiles()->attach($scenario['staff']->id);

        $this->actingAsClient($member)
            ->getJson($this->path($scenario))
            ->assertForbidden();

        $this->actingAsClient($member)
            ->postJson('/api/commands/create-deployment', [
                'event_id' => (string) $scenario['event']->id,
                'department_id' => (string) $scenario['department']->id,
                'name' => 'Gate 9',
            ])
            ->assertForbidden();
    }

    private function path(array $scenario): string
    {
        return sprintf(
            '/api/events/%s/departments/%s/deployments',
            $scenario['event']->id,
            $scenario['department']->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Emberfall 2027']);
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $otherDepartment = Department::factory()->for($organization)->create(['name' => 'Medical']);

        $team = Team::factory()->for($department)->create(['name' => 'Gate Team']);
        $otherTeam = Team::factory()->for($otherDepartment)->create(['name' => 'Aid Team']);

        $staff = Staff::factory()->create(['legal_name' => 'Gwen Gate', 'handle' => 'gwen']);
        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Ranger Day Shift',
        ]);

        return [
            'organization' => $organization,
            'event' => $event,
            'department' => $department,
            'otherDepartment' => $otherDepartment,
            'shift' => $shift,
            'staff' => $staff,
            'operations' => $this->userWithRole(
                Team::factory()->for($department)->create(['name' => 'Ops Desk']),
                'department_operations',
            ),
            'lead' => $this->userWithRole(
                Team::factory()->for($department)->create(['name' => 'Leadership']),
                'department_lead',
            ),
            'otherOperations' => $this->userWithRole($otherTeam, 'department_operations'),
        ];
    }

    private function userWithRole(Team $team, string $roleCode): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
