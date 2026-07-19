<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Services\Permissions\TeamGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TeamGrantPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_lead_can_be_granted_to_any_team(): void
    {
        $team = Team::factory()->create();
        $role = $this->role('shift_lead');

        $grant = (new TeamGrantService)->grant($team, $role);

        $this->assertDatabaseHas('team_grants', [
            'id' => $grant->id,
            'team_id' => $team->id,
            'permission_role_id' => $role->id,
            'event_id' => null,
            'revoked_at' => null,
        ]);
    }

    public function test_organizer_grant_succeeds_for_team_in_configured_organizers_department(): void
    {
        $team = $this->teamInOrganizersDepartment();

        $grant = (new TeamGrantService)->grant($team, $this->role('organizer'));

        $this->assertNull($grant->revoked_at);
        $this->assertTrue($grant->team->is($team));
    }

    public function test_organizer_grant_is_rejected_for_team_outside_organizers_department(): void
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create();
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        $otherDepartment = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($otherDepartment)->create();

        $this->expectException(InvalidArgumentException::class);

        (new TeamGrantService)->grant($team, $this->role('lead_organizer'));
    }

    public function test_organizer_grant_is_rejected_when_no_organizers_department_configured(): void
    {
        $team = Team::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new TeamGrantService)->grant($team, $this->role('organizer'));
    }

    public function test_god_mode_cannot_be_granted_through_a_team(): void
    {
        $team = Team::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new TeamGrantService)->grant($team, $this->role('god_mode'));
    }

    public function test_event_scoped_ic_role_requires_an_event(): void
    {
        $team = Team::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new TeamGrantService)->grant($team, $this->role('ic_lead'));
    }

    public function test_event_scoped_ic_roles_can_be_granted_to_teams_in_the_selected_ic_department(): void
    {
        [$event, $team] = $this->eventWithIcTeam();

        foreach (['ic_viewer', 'ic_operator', 'ic_lead'] as $roleCode) {
            $grant = (new TeamGrantService)->grant($team, $this->role($roleCode), $event);

            $this->assertSame($event->id, $grant->event_id);
            $this->assertSame($team->id, $grant->team_id);
            $this->assertSame($roleCode, $grant->permissionRole->code);
        }
    }

    public function test_event_scoped_ic_role_is_rejected_when_event_has_no_ic_department(): void
    {
        $team = Team::factory()->create();
        $event = Event::factory()->create(['ic_department_id' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IC roles require the event to have an Incident Command department.');

        (new TeamGrantService)->grant($team, $this->role('ic_viewer'), $event);
    }

    public function test_event_scoped_ic_role_is_rejected_for_team_outside_selected_ic_department(): void
    {
        [$event] = $this->eventWithIcTeam();
        $otherDepartment = Department::factory()->for($event->organization)->create();
        $otherTeam = Team::factory()->for($otherDepartment)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IC roles can only be granted to teams in the event Incident Command department.');

        (new TeamGrantService)->grant($otherTeam, $this->role('ic_operator'), $event);
    }

    public function test_event_scoped_ic_role_uses_organization_default_when_event_has_no_override(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $organization->forceFill(['default_ic_department_id' => $department->id])->save();
        $event = Event::factory()->for($organization)->create(['ic_department_id' => null]);
        $team = Team::factory()->for($department)->create();

        $grant = (new TeamGrantService)->grant($team, $this->role('ic_lead'), $event);

        $this->assertSame($event->id, $grant->event_id);
    }

    public function test_revoking_a_grant_preserves_the_record_and_excludes_it_from_active_scope(): void
    {
        $grant = TeamGrant::factory()->for($this->role('shift_lead'), 'permissionRole')->create();

        $revoked = (new TeamGrantService)->revoke($grant);

        $this->assertNotNull($revoked->revoked_at);
        $this->assertTrue($revoked->isRevoked());
        $this->assertDatabaseHas('team_grants', ['id' => $grant->id]);
        $this->assertFalse(TeamGrant::query()->active()->whereKey($grant->id)->exists());
    }

    public function test_revoke_is_idempotent(): void
    {
        $grant = TeamGrant::factory()->revoked()->create();
        $originalRevokedAt = $grant->revoked_at;

        $service = new TeamGrantService;
        $service->revoke($grant);

        $this->assertSame($originalRevokedAt->toDateTimeString(), $grant->fresh()->revoked_at->toDateTimeString());
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }

    private function teamInOrganizersDepartment(): Team
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $organization->forceFill(['organizers_department_id' => $department->id])->save();

        return Team::factory()->for($department)->create();
    }

    /**
     * @return array{0: Event, 1: Team}
     */
    private function eventWithIcTeam(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create([
            'ic_department_id' => $department->id,
        ]);
        $team = Team::factory()->for($department)->create();

        return [$event, $team];
    }
}
