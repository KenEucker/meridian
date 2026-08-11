<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Directory\DirectoryContext;
use App\Services\Directory\DirectoryPlacement;
use App\Services\Directory\DirectoryVisibility;
use App\Services\Directory\DirectoryVisibilityResolver;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Directory visibility rule, case by case from requirements 7.29 (M18.71;
 * DIR-017 through DIR-026, DIR-030; technical spec 21E.4, 21E.5).
 *
 * The rule is the security boundary for every Directory task after it, landed
 * and reviewed alone before any surface consumes it. Each test builds the
 * position it is about explicitly rather than leaning on a seeded scenario, so
 * a failure names the boundary that broke.
 *
 * DIR-023 is asserted for Staff Coordinator, an IC role, and a department
 * Operator. The organization-owner and God Mode cases it also names have no
 * product modeling yet — node-scoped direct user roles remain deferred — so
 * there is no position to hold; the day one exists, it must be added here.
 */
class DirectoryVisibilityRuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $organizers;

    private Department $rangers;

    private Department $gate;

    private Department $dpw;

    private Team $organizersTeam;

    private Team $rangerLeads;

    private Team $dirt;

    private Team $rangerOps;

    private Team $gateLeads;

    private Team $gateCrew;

    private Team $dpwCrew;

    private Team $dpwOther;

    /** @var array<string, Staff> */
    private array $staff = [];

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);

        $this->organization = Organization::factory()->create();

        $this->organizers = $this->department('Organizers');
        $this->rangers = $this->department('Rangers');
        $this->gate = $this->department('Gate');
        $this->dpw = $this->department('DPW');

        $this->organization->forceFill([
            'organizers_department_id' => $this->organizers->getKey(),
        ])->save();

        $this->organizersTeam = $this->team($this->organizers, 'Core');
        $this->rangerLeads = $this->team($this->rangers, 'Ranger Leads');
        $this->dirt = $this->team($this->rangers, 'Dirt');
        $this->rangerOps = $this->team($this->rangers, 'Ops');
        $this->gateLeads = $this->team($this->gate, 'Gate Leads');
        $this->gateCrew = $this->team($this->gate, 'Gate Crew');
        $this->dpwCrew = $this->team($this->dpw, 'DPW Crew');
        $this->dpwOther = $this->team($this->dpw, 'DPW Other');

        $this->grant(PermissionCatalog::ROLE_ORGANIZER, $this->organizersTeam);
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->rangerLeads);
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->gateLeads);

        /*
         * The population, one person per boundary:
         *
         * olive   organizer (Organizers/Core)
         * dana    department lead of Rangers (Ranger Leads), also on Dirt
         * tess    designated team lead of Dirt, also ordinary on Gate Crew
         * vera    ordinary member of Dirt
         * ollie   ordinary member of Ranger Ops
         * pia     Rangers member with no team (Prospectives)
         * gabe    department lead of Gate (Gate Leads)
         * gale    designated team lead of Gate Crew
         * greta   ordinary member of Gate Crew
         * dex     ordinary member of DPW Crew
         * dot     ordinary member of DPW Other
         * pete    DPW member with no team (Prospectives)
         */
        $this->person('olive', [[$this->organizersTeam, 'member']]);
        $this->person('dana', [[$this->rangerLeads, 'member'], [$this->dirt, 'member']]);
        $this->person('tess', [[$this->dirt, 'lead'], [$this->gateCrew, 'member']]);
        $this->person('vera', [[$this->dirt, 'member']]);
        $this->person('ollie', [[$this->rangerOps, 'member']]);
        $this->person('pia', [], department: $this->rangers);
        $this->person('gabe', [[$this->gateLeads, 'member']]);
        $this->person('gale', [[$this->gateCrew, 'lead']]);
        $this->person('greta', [[$this->gateCrew, 'member']]);
        $this->person('dex', [[$this->dpwCrew, 'member']]);
        $this->person('dot', [[$this->dpwOther, 'member']]);
        $this->person('pete', [], department: $this->dpw);
    }

    /*
    |--------------------------------------------------------------------------
    | The visibility matrix (DIR-018 through DIR-022)
    |--------------------------------------------------------------------------
    */

    public function test_ordinary_staff_reach_leadership_and_no_ordinary_members(): void
    {
        $visible = $this->visibleTo('vera');

        $this->assertVisible($visible, ['olive', 'dana', 'tess', 'gabe', 'gale']);
        $this->assertAbsent($visible, ['vera', 'ollie', 'pia', 'greta', 'dex', 'dot', 'pete']);
    }

    public function test_a_department_lead_reaches_their_own_department_and_not_anothers(): void
    {
        $visible = $this->visibleTo('dana');

        // Her own department, whole: leads, team members, and the members
        // holding no team (DIR-019).
        $this->assertVisible($visible, ['dana', 'tess', 'vera', 'ollie', 'pia']);

        // Another department's leadership stays baseline-visible; its ordinary
        // members do not appear (DIR-019).
        $this->assertVisible($visible, ['olive', 'gabe', 'gale']);
        $this->assertAbsent($visible, ['greta', 'dex', 'dot', 'pete']);
    }

    public function test_a_team_lead_reaches_their_own_team_and_not_the_surrounding_department(): void
    {
        $visible = $this->visibleTo('tess');

        // Dirt, her team, whole (DIR-020).
        $this->assertVisible($visible, ['tess', 'dana', 'vera']);

        // The surrounding department's other members: absent (DIR-020) — the
        // Ops team member, the Prospectives member, and the ordinary members
        // of the Gate Crew team she belongs to without leading.
        $this->assertAbsent($visible, ['ollie', 'pia', 'greta', 'dex', 'dot', 'pete']);
    }

    public function test_a_department_lead_leading_a_team_elsewhere_gets_team_scope_only_there(): void
    {
        $this->leadTeam('dana', $this->dpwCrew);

        $visible = $this->visibleTo('dana');

        // The led team's members, and nothing else of DPW (DIR-022).
        $this->assertVisible($visible, ['dex']);
        $this->assertAbsent($visible, ['dot', 'pete']);
    }

    public function test_multiple_positions_are_additive(): void
    {
        $this->leadTeam('dana', $this->dpwCrew);

        $visible = $this->visibleTo('dana');

        // The union: baseline leadership, all of Rangers, and DPW Crew — each
        // position within its own scope (DIR-022).
        $this->assertVisible($visible, [
            'olive', 'dana', 'tess', 'vera', 'ollie', 'pia', 'gabe', 'gale', 'dex',
        ]);
        $this->assertAbsent($visible, ['greta', 'dot', 'pete']);
    }

    public function test_an_organizer_reaches_the_whole_population(): void
    {
        $visible = $this->visibleTo('olive');

        $this->assertVisible($visible, [
            'olive', 'dana', 'tess', 'vera', 'ollie', 'pia',
            'gabe', 'gale', 'greta', 'dex', 'dot', 'pete',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | No other role widens anything (DIR-023, DIR-024)
    |--------------------------------------------------------------------------
    */

    public function test_elevated_roles_reach_exactly_ordinary_staff_visibility(): void
    {
        $ordinary = $this->staffIdsVisibleTo('vera');

        foreach ([
            PermissionCatalog::ROLE_STAFF_COORDINATOR,
            PermissionCatalog::ROLE_IC_LEAD,
            PermissionCatalog::ROLE_DEPARTMENT_OPERATOR,
        ] as $roleCode) {
            $team = $this->team($this->rangers, "Holders of {$roleCode}");
            $this->grant($roleCode, $team);
            $key = "holder-{$roleCode}";
            $this->person($key, [[$team, 'member']]);

            $visible = $this->visibleTo($key);

            $this->assertSame(
                $ordinary,
                $this->withoutOwnTeams($visible, $key),
                "A {$roleCode} holder saw more of the Directory than ordinary staff (DIR-023).",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Status (DIR-025, DIR-026)
    |--------------------------------------------------------------------------
    */

    public function test_an_excluded_organization_status_is_absent_even_holding_a_visible_position(): void
    {
        StaffOrganizationStatus::query()
            ->where('organization_id', $this->organization->getKey())
            ->where('staff_id', $this->staff['gabe']->getKey())
            ->update(['status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF]);

        // A department lead, and absent everywhere: from ordinary staff's
        // leadership baseline and from an organizer's whole-population view
        // alike (DIR-026).
        $this->assertAbsent($this->visibleTo('vera'), ['gabe']);
        $this->assertAbsent($this->visibleTo('olive'), ['gabe']);
    }

    public function test_an_excluded_department_status_removes_that_placement_and_not_the_person(): void
    {
        DepartmentMembership::query()
            ->where('department_id', $this->gate->getKey())
            ->where('staff_id', $this->staff['tess']->getKey())
            ->update(['status' => DepartmentMembership::STATUS_INACTIVE]);

        $placements = $this->visibleTo('olive')->placementsFor((string) $this->staff['tess']->getKey());

        // The Gate Crew membership reads Department Inactive and is gone; the
        // Dirt team-lead placement stands (DIR-025: the status applied is the
        // one belonging to the membership being presented).
        $this->assertSame(
            [(string) $this->dirt->getKey()],
            array_values(array_unique(array_map(
                fn (DirectoryPlacement $placement): ?string => $placement->teamId,
                $placements,
            ))),
        );
    }

    public function test_an_excluded_team_lead_is_not_drawn_because_they_are_a_lead(): void
    {
        StaffOrganizationStatus::query()
            ->where('organization_id', $this->organization->getKey())
            ->where('staff_id', $this->staff['tess']->getKey())
            ->update(['status' => StaffOrganizationStatus::STATUS_INACTIVE]);

        $this->assertAbsent($this->visibleTo('vera'), ['tess']);
        $this->assertAbsent($this->visibleTo('olive'), ['tess']);
    }

    public function test_visible_non_active_statuses_are_included(): void
    {
        StaffOrganizationStatus::query()
            ->where('organization_id', $this->organization->getKey())
            ->where('staff_id', $this->staff['dex']->getKey())
            ->update(['status' => StaffOrganizationStatus::STATUS_EMERITUS]);

        DepartmentMembership::query()
            ->where('department_id', $this->dpw->getKey())
            ->where('staff_id', $this->staff['dex']->getKey())
            ->update(['status' => DepartmentMembership::STATUS_RETIRED]);

        $this->assertVisible($this->visibleTo('olive'), ['dex']);
    }

    /*
    |--------------------------------------------------------------------------
    | Locations are constrained too (DIR-030)
    |--------------------------------------------------------------------------
    */

    public function test_the_rule_returns_only_authorized_locations_for_an_authorized_person(): void
    {
        // Tess is authorized for vera as the team lead of Dirt. Her ordinary
        // membership of Gate Crew is a location vera holds no position over,
        // and it is not listed against her (DIR-030).
        $placements = $this->visibleTo('vera')->placementsFor((string) $this->staff['tess']->getKey());

        $this->assertNotEmpty($placements);

        foreach ($placements as $placement) {
            $this->assertSame((string) $this->dirt->getKey(), $placement->teamId);
            $this->assertSame(DirectoryPlacement::KIND_TEAM_LEAD, $placement->kind);
        }
    }

    public function test_a_lead_is_a_team_lead_placement_and_not_also_a_member_of_their_own_team(): void
    {
        $placements = $this->visibleTo('olive')->placementsFor((string) $this->staff['tess']->getKey());

        $dirtKinds = array_map(
            fn (DirectoryPlacement $placement): string => $placement->kind,
            array_values(array_filter(
                $placements,
                fn (DirectoryPlacement $placement): bool => $placement->teamId === (string) $this->dirt->getKey(),
            )),
        );

        // Once, as the lead (DIR-012).
        $this->assertSame([DirectoryPlacement::KIND_TEAM_LEAD], $dirtKinds);
    }

    /*
    |--------------------------------------------------------------------------
    | Context populations (DIR-006 through DIR-008)
    |--------------------------------------------------------------------------
    */

    public function test_event_context_population_follows_the_event_assignment(): void
    {
        $event = Event::factory()->create(['organization_id' => $this->organization->getKey()]);

        foreach ([$this->organizers, $this->rangers] as $department) {
            EventDepartmentAssignment::factory()->create([
                'event_id' => $event->getKey(),
                'department_id' => $department->getKey(),
            ]);
        }

        $visible = $this->resolve('olive', new DirectoryContext($this->organization, $event));

        // Gate and DPW are not working this event: their members are not in
        // its Directory, however visible they are at the organization level
        // (DIR-006). The organization chart keeps the whole population
        // (DIR-007).
        $this->assertVisible($visible, ['olive', 'dana', 'tess', 'vera', 'ollie', 'pia']);
        $this->assertAbsent($visible, ['gabe', 'gale', 'greta', 'dex', 'dot', 'pete']);

        $this->assertVisible($this->visibleTo('olive'), ['gabe', 'greta', 'dex']);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function department(string $name): Department
    {
        return Department::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => $name,
        ]);
    }

    private function team(Department $department, string $name): Team
    {
        return Team::factory()->create([
            'department_id' => $department->getKey(),
            'name' => $name,
        ]);
    }

    private function grant(string $roleCode, Team $team): void
    {
        TeamGrant::factory()->create([
            'team_id' => $team->getKey(),
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()
                ->where('code', $roleCode)
                ->firstOrFail()
                ->getKey(),
        ]);
    }

    /**
     * One staff member with a signed-in user, an active organization status,
     * and the given team memberships — or, with none, a department membership
     * holding no team (the Prospectives case, DIR-013).
     *
     * @param  list<array{0: Team, 1: string}>  $teams
     */
    private function person(string $key, array $teams, ?Department $department = null): void
    {
        $staff = Staff::factory()->create(['handle' => ucfirst($key)]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->getKey());

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'staff_id' => $staff->getKey(),
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        $this->staff[$key] = $staff;
        $this->users[$key] = $user;

        if ($teams === []) {
            if ($department !== null) {
                DepartmentMembership::factory()->create([
                    'department_id' => $department->getKey(),
                    'staff_id' => $staff->getKey(),
                    'status' => DepartmentMembership::STATUS_ACTIVE,
                ]);
            }

            return;
        }

        foreach ($teams as [$team, $role]) {
            $this->joinTeam($staff, $team, $role);
        }
    }

    private function joinTeam(Staff $staff, Team $team, string $role = 'member'): void
    {
        $membership = DepartmentMembership::query()
            ->where('department_id', $team->department_id)
            ->where('staff_id', $staff->getKey())
            ->whereNull('archived_at')
            ->first();

        $membership ??= DepartmentMembership::factory()->create([
            'department_id' => $team->department_id,
            'staff_id' => $staff->getKey(),
            'status' => DepartmentMembership::STATUS_ACTIVE,
        ]);

        TeamMembership::factory()->create([
            'team_id' => $team->getKey(),
            'staff_id' => $staff->getKey(),
            'department_membership_id' => $membership->getKey(),
            'membership_role' => $role,
        ]);
    }

    private function leadTeam(string $key, Team $team): void
    {
        $this->joinTeam($this->staff[$key], $team, 'lead');
    }

    private function resolve(string $key, ?DirectoryContext $context = null): DirectoryVisibility
    {
        return app(DirectoryVisibilityResolver::class)->resolve(
            $this->users[$key],
            $context ?? new DirectoryContext($this->organization),
        );
    }

    private function visibleTo(string $key): DirectoryVisibility
    {
        return $this->resolve($key);
    }

    /**
     * @return list<string>
     */
    private function staffIdsVisibleTo(string $key): array
    {
        $ids = $this->visibleTo($key)->staffIds();
        sort($ids);

        return $ids;
    }

    /**
     * The visible staff ids with the viewer's own scenario people removed —
     * the holder personas each sit on a team of their own, and comparing
     * against vera's set has to compare the shared population only.
     *
     * @return list<string>
     */
    private function withoutOwnTeams(DirectoryVisibility $visibility, string $selfKey): array
    {
        $ids = array_values(array_filter(
            $visibility->staffIds(),
            fn (string $id): bool => $id !== (string) $this->staff[$selfKey]->getKey(),
        ));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertVisible(DirectoryVisibility $visibility, array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertTrue(
                $visibility->isAuthorized((string) $this->staff[$key]->getKey()),
                "Expected {$key} to be visible and they were not.",
            );
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertAbsent(DirectoryVisibility $visibility, array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertFalse(
                $visibility->isAuthorized((string) $this->staff[$key]->getKey()),
                "Expected {$key} to be absent and they were visible.",
            );
        }
    }
}
