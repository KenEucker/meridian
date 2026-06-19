<?php

namespace Tests\Feature;

use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Services\Permissions\EffectiveRole;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectiveRoleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_role_from_active_grant_on_member_team_with_explanation(): void
    {
        $team = Team::factory()->create(['name' => 'Rangers']);
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('shift_lead')->id,
        ]);

        $roles = (new EffectiveRoleResolver)->resolveForStaff($staff);

        $this->assertCount(1, $roles);
        $effective = $roles->first();
        $this->assertInstanceOf(EffectiveRole::class, $effective);
        $this->assertSame('shift_lead', $effective->roleCode);
        $this->assertSame($team->id, $effective->teamId);
        $this->assertSame(
            'You have the Shift Lead role because you are a member of the Rangers team.',
            $effective->reason,
        );
    }

    public function test_revoked_grants_are_excluded(): void
    {
        $team = Team::factory()->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        TeamGrant::factory()->revoked()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('shift_lead')->id,
        ]);

        $this->assertCount(0, (new EffectiveRoleResolver)->resolveForStaff($staff));
    }

    public function test_grants_on_non_member_teams_are_excluded(): void
    {
        $memberTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $memberTeam);

        TeamGrant::factory()->create([
            'team_id' => $otherTeam->id,
            'permission_role_id' => $this->role('shift_lead')->id,
        ]);

        $this->assertCount(0, (new EffectiveRoleResolver)->resolveForStaff($staff));
    }

    public function test_event_scoped_grant_is_only_returned_for_matching_event(): void
    {
        $team = Team::factory()->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => $event->id,
            'permission_role_id' => $this->role('ic_lead')->id,
        ]);

        $this->assertCount(0, (new EffectiveRoleResolver)->resolveForStaff($staff));
        $this->assertCount(0, (new EffectiveRoleResolver)->resolveForStaff($staff, $otherEvent));
        $this->assertCount(1, (new EffectiveRoleResolver)->resolveForStaff($staff, $event));
    }

    public function test_organization_scoped_grants_apply_regardless_of_event(): void
    {
        $team = Team::factory()->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => $this->role('organizer')->id,
        ]);

        $this->assertCount(1, (new EffectiveRoleResolver)->resolveForStaff($staff));
        $this->assertCount(1, (new EffectiveRoleResolver)->resolveForStaff($staff, Event::factory()->create()));
    }

    public function test_staff_without_team_memberships_have_no_effective_roles(): void
    {
        $staff = Staff::factory()->create();

        TeamGrant::factory()->create([
            'permission_role_id' => $this->role('shift_lead')->id,
        ]);

        $this->assertCount(0, (new EffectiveRoleResolver)->resolveForStaff($staff));
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }

    private function addStaffToTeam(Staff $staff, Team $team): void
    {
        $departmentMembership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
    }
}
