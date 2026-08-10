<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentScenarioSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\Support\DevelopmentScenarioCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevelopmentScenarioSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_scenario_seeds_organization_event_departments_teams_and_personas(): void
    {
        $this->seedScenario();

        $organization = Organization::query()
            ->where('slug', DevelopmentScenarioCatalog::ORGANIZATION_SLUG)
            ->firstOrFail();

        $this->assertSame(DevelopmentScenarioCatalog::ORGANIZATION_NAME, $organization->name);
        $this->assertNotNull($organization->organizers_department_id);
        $this->assertNotNull($organization->default_ic_department_id);
        $this->assertNotSame($organization->organizers_department_id, $organization->default_ic_department_id);

        $organizersDepartment = Department::query()->findOrFail($organization->organizers_department_id);
        $icDepartment = Department::query()->findOrFail($organization->default_ic_department_id);
        $this->assertSame('ORGANIZERS', $organizersDepartment->code);
        $this->assertSame('RANGERS', $icDepartment->code);

        $event = Event::query()
            ->where('slug', DevelopmentScenarioCatalog::EVENT_SLUG)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        $this->assertSame(DevelopmentScenarioCatalog::EVENT_NAME, $event->name);
        $this->assertSame($organization->default_ic_department_id, $event->ic_department_id);

        // The `*_LEADS` and `IC_COMMAND` teams are where authority lives. A
        // team grant applies to every member of the team, so parking the
        // department roles on Dirt made every Dirt member a department lead —
        // including the persona this catalog calls regular staff.
        $this->assertSame(
            [
                'COMMAND', 'DIRT', 'DPW_LEADS', 'GATE_LEADS', 'IC_COMMAND', 'IC_OPERATOR',
                'IC_VIEWER', 'LOGISTICS', 'OPERATOR', 'RANGER_LEADS', 'RANGER_SHIFT_LEADS',
            ],
            Team::query()
                ->whereHas('department', fn ($query) => $query->where('organization_id', $organization->id))
                ->where('is_default', false)
                ->orderBy('code')
                ->pluck('code')
                ->all(),
        );

        $this->assertSame(4, Department::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(4, EventDepartmentAssignment::query()
            ->active()
            ->where('event_id', $event->id)
            ->count());

        // Twelve personas carry authority, one per documented role. The rest
        // are the bodies the operational scenario needs in order to have
        // somebody standing in each of the desk's states at the same time, plus
        // the one who works three departments at once.
        $this->assertCount(19, User::query()->where('email', 'like', '%@northwood-collective.test')->get());
        $this->assertCount(19, Staff::query()->where('email', 'like', '%@northwood-collective.test')->get());
    }

    /**
     * The persona department switching is exercised with (M18.69).
     *
     * Three active memberships in three departments, all ordinary. The last
     * assertion is the one that matters beyond the count: if Milo picked up a
     * role from a team he was added to, the scenario has stopped documenting
     * one holder per role and the permission-boundary tests above are resting
     * on something that is no longer true.
     */
    public function test_the_multi_department_persona_is_an_ordinary_member_of_three_departments(): void
    {
        $this->seedScenario();

        $organization = Organization::query()
            ->where('slug', DevelopmentScenarioCatalog::ORGANIZATION_SLUG)
            ->firstOrFail();

        $milo = Staff::query()
            ->where('email', 'milo.multidept@northwood-collective.test')
            ->firstOrFail();

        $departmentCodes = $milo->departmentMemberships()
            ->whereNull('archived_at')
            ->whereHas('department', fn ($query) => $query->where('organization_id', $organization->id))
            ->with('department')
            ->get()
            ->map(fn ($membership): string => (string) $membership->department?->code)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['DPW', 'GATE', 'RANGERS'], $departmentCodes);

        $this->assertSame(
            [],
            (new EffectiveRoleResolver)->resolveForStaff($milo)->pluck('roleCode')->all(),
            'Milo Multidept switches departments and must hold no roles in any of them.',
        );
    }

    public function test_seeded_personas_have_expected_statuses_memberships_and_permission_grants(): void
    {
        $this->seedScenario();

        $organization = Organization::query()
            ->where('slug', DevelopmentScenarioCatalog::ORGANIZATION_SLUG)
            ->firstOrFail();
        $event = Event::query()
            ->where('slug', DevelopmentScenarioCatalog::EVENT_SLUG)
            ->firstOrFail();

        $this->assertOrganizationStatus('debbie.dns@northwood-collective.test', StaffOrganizationStatus::STATUS_DO_NOT_STAFF);
        $this->assertOrganizationStatus('pat.prospective@northwood-collective.test', StaffOrganizationStatus::STATUS_PROSPECTIVE);

        $ira = Staff::query()->where('email', 'ira.ineligible@northwood-collective.test')->firstOrFail();
        $iraMembership = $ira->departmentMemberships()
            ->whereHas('department', fn ($query) => $query->where('organization_id', $organization->id))
            ->firstOrFail();
        $this->assertSame('ineligible', $iraMembership->status);

        $shiftLeadTeam = Team::query()
            ->where('code', 'RANGER_SHIFT_LEADS')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('code', 'RANGERS'))
            ->firstOrFail();

        $this->assertTrue(
            TeamGrant::query()
                ->active()
                ->where('team_id', $shiftLeadTeam->id)
                ->whereHas('permissionRole', fn ($query) => $query->where('code', 'shift_lead'))
                ->exists(),
        );

        foreach (['department_logistics', 'department_operations', 'department_administration', 'department_planning'] as $roleCode) {
            $this->assertTrue(
                TeamGrant::query()
                    ->active()
                    ->where('team_id', $shiftLeadTeam->id)
                    ->whereHas('permissionRole', fn ($query) => $query->where('code', $roleCode))
                    ->exists(),
                "Ranger Shift Leads should have {$roleCode}.",
            );
        }

        $commandTeam = Team::query()
            ->where('code', 'IC_COMMAND')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('code', 'RANGERS'))
            ->firstOrFail();

        $dirtTeam = Team::query()
            ->where('code', 'DIRT')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('code', 'RANGERS'))
            ->firstOrFail();

        /*
         * The crew team carries no grants — with one exception the mechanism
         * makes safe. Tess Teamlead's shift_lead grant hangs on DIRT itself
         * (M18.16), because a shift lead's led team *is* the grant-bearing
         * team; shift_lead is also the one role that elevates only
         * memberships designated `membership_role = 'lead'` (M11.17), so it
         * is the only role a crew team may carry without making the
         * ordinary-staff personas something other than ordinary. Vera's
         * floor assertion below is what proves that holds.
         */
        $dirtGrantRoles = TeamGrant::query()
            ->active()
            ->where('team_id', $dirtTeam->id)
            ->with('permissionRole')
            ->get()
            ->map(fn (TeamGrant $grant): string => (string) $grant->permissionRole?->code)
            ->unique()
            ->values()
            ->all();
        $this->assertSame(
            ['shift_lead'],
            $dirtGrantRoles,
            'The crew team carries no grants beyond the lead-designated shift_lead, or the ordinary-staff personas are not ordinary.',
        );

        $vera = Staff::query()->where('email', 'vera.staff@northwood-collective.test')->firstOrFail();
        $this->assertSame(
            [],
            (new EffectiveRoleResolver)->resolveForStaff($vera)->pluck('roleCode')->all(),
            'Vera Staff is the permission floor and must hold no roles at all.',
        );

        // Tess is the reason for the exception: a designated lead of the crew
        // team resolving exactly shift_lead and nothing wider.
        $tess = Staff::query()->where('email', 'tess.teamlead@northwood-collective.test')->firstOrFail();
        $tessRoles = (new EffectiveRoleResolver)->resolveForStaff($tess);
        $this->assertSame(['shift_lead'], $tessRoles->pluck('roleCode')->unique()->values()->all());
        $this->assertSame([(string) $dirtTeam->id], $tessRoles->pluck('teamId')->unique()->values()->all());

        $defaultTeam = Team::query()
            ->where('code', 'DEFAULT')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('code', 'ORGANIZERS'))
            ->firstOrFail();

        $this->assertTrue(
            TeamGrant::query()
                ->active()
                ->where('team_id', $defaultTeam->id)
                ->whereNull('event_id')
                ->whereHas('permissionRole', fn ($query) => $query->where('code', 'organizer'))
                ->exists(),
        );

        $this->assertTrue(
            TeamGrant::query()
                ->active()
                ->where('team_id', $commandTeam->id)
                ->where('event_id', $event->id)
                ->whereHas('permissionRole', fn ($query) => $query->where('code', 'ic_lead'))
                ->exists(),
        );

        $operatorTeam = Team::query()
            ->where('code', 'IC_OPERATOR')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('code', 'RANGERS'))
            ->firstOrFail();

        $this->assertTrue(
            TeamGrant::query()
                ->active()
                ->where('team_id', $operatorTeam->id)
                ->where('event_id', $event->id)
                ->whereHas('permissionRole', fn ($query) => $query->where('code', 'ic_operator'))
                ->exists(),
        );

        $sam = Staff::query()->where('email', 'sam.shiftlead@northwood-collective.test')->firstOrFail();
        $roles = (new EffectiveRoleResolver)->resolveForStaff($sam);
        $this->assertContains('shift_lead', $roles->pluck('roleCode')->all());
        $this->assertContains('department_logistics', $roles->pluck('roleCode')->all());
        $this->assertContains('department_operations', $roles->pluck('roleCode')->all());

        $ingrid = Staff::query()->where('email', 'ingrid.iclead@northwood-collective.test')->firstOrFail();
        $icRoles = (new EffectiveRoleResolver)->resolveForStaff($ingrid, $event);
        $this->assertSame('ic_lead', $icRoles->sole()->roleCode);

        $omar = Staff::query()->where('email', 'omar.icoperator@northwood-collective.test')->firstOrFail();
        $this->assertSame(
            'ic_operator',
            (new EffectiveRoleResolver)->resolveForStaff($omar, $event)->sole()->roleCode,
        );
    }

    public function test_seeded_users_can_authenticate_with_the_documented_development_password(): void
    {
        $this->seedScenario();

        $vera = User::query()->where('email', 'vera.staff@northwood-collective.test')->firstOrFail();

        $this->assertTrue(Hash::check(DevelopmentScenarioCatalog::DEFAULT_PASSWORD, $vera->password));
    }

    /**
     * The whole seeder chain runs, model events and all.
     *
     * It used to run with `WithoutModelEvents`, and this test asserted it
     * survived that. It no longer does and must not: the operational scenario
     * drives the domain services, and several of them depend on `creating`
     * hooks — a shift takes its department and team name snapshots from one and
     * cannot be written without it. So what is under test here is the opposite
     * of what it was: that the full chain completes with hooks live, and that
     * the data those hooks produce is present.
     */
    public function test_database_seeder_runs_the_whole_scenario_with_model_events_live(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            19,
            User::query()->where('email', 'like', '%@northwood-collective.test')->count(),
        );

        // Written by the Shift model's `creating` hook rather than by any
        // service, so a blank one here would mean the hooks were suppressed.
        $this->assertTrue(
            Shift::query()->whereNotNull('department_name_snapshot')->exists(),
        );
        $this->assertFalse(
            Shift::query()->whereNull('team_name_snapshot')->exists(),
        );

        $this->assertTrue(
            Team::query()
                ->where('code', 'DEFAULT')
                ->whereHas('department', fn ($query) => $query->where('code', 'ORGANIZERS'))
                ->exists(),
        );
    }

    public function test_development_scenario_seeder_is_idempotent(): void
    {
        $this->seedScenario();
        $countsAfterFirstRun = $this->scenarioCounts();

        (new DevelopmentScenarioSeeder)->run();

        $this->assertSame($countsAfterFirstRun, $this->scenarioCounts());
    }

    private function seedScenario(): void
    {
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(DevelopmentScenarioSeeder::class);
    }

    private function assertOrganizationStatus(string $email, string $status): void
    {
        $staff = Staff::query()->where('email', $email)->firstOrFail();

        $this->assertSame(
            $status,
            StaffOrganizationStatus::query()
                ->where('staff_id', $staff->id)
                ->value('status'),
        );
    }

    /**
     * @return array<string, int>
     */
    private function scenarioCounts(): array
    {
        $organization = Organization::query()
            ->where('slug', DevelopmentScenarioCatalog::ORGANIZATION_SLUG)
            ->firstOrFail();

        return [
            'departments' => Department::query()->where('organization_id', $organization->id)->count(),
            'events' => Event::query()->where('organization_id', $organization->id)->count(),
            'personas' => User::query()->where('email', 'like', '%@northwood-collective.test')->count(),
            'team_grants' => TeamGrant::query()
                ->whereHas('team.department', fn ($query) => $query->where('organization_id', $organization->id))
                ->count(),
        ];
    }
}
