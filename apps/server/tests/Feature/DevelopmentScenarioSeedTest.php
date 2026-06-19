<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
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

        $event = Event::query()
            ->where('slug', DevelopmentScenarioCatalog::EVENT_SLUG)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        $this->assertSame(DevelopmentScenarioCatalog::EVENT_NAME, $event->name);
        $this->assertSame($organization->default_ic_department_id, $event->ic_department_id);

        $this->assertSame(
            ['COMMAND', 'DIRT', 'LOGISTICS', 'OPERATOR'],
            Team::query()
                ->whereHas('department', fn ($query) => $query->where('organization_id', $organization->id))
                ->where('is_default', false)
                ->orderBy('code')
                ->pluck('code')
                ->all(),
        );

        $this->assertSame(4, Department::query()->where('organization_id', $organization->id)->count());

        $this->assertCount(11, User::query()->where('email', 'like', '%@idaho-burners.test')->get());
        $this->assertCount(11, Staff::query()->where('email', 'like', '%@idaho-burners.test')->get());
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

        $this->assertOrganizationStatus('debbie.dns@idaho-burners.test', StaffOrganizationStatus::STATUS_DO_NOT_STAFF);
        $this->assertOrganizationStatus('pat.prospective@idaho-burners.test', StaffOrganizationStatus::STATUS_PROSPECTIVE);

        $ira = Staff::query()->where('email', 'ira.ineligible@idaho-burners.test')->firstOrFail();
        $iraMembership = $ira->departmentMemberships()
            ->whereHas('department', fn ($query) => $query->where('organization_id', $organization->id))
            ->firstOrFail();
        $this->assertSame('ineligible', $iraMembership->status);

        $dirtTeam = Team::query()
            ->where('code', 'DIRT')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('code', 'RANGERS'))
            ->firstOrFail();

        $this->assertTrue(
            TeamGrant::query()
                ->active()
                ->where('team_id', $dirtTeam->id)
                ->whereHas('permissionRole', fn ($query) => $query->where('code', 'shift_lead'))
                ->exists(),
        );

        $commandTeam = Team::query()
            ->where('code', 'COMMAND')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('code', 'ORGANIZERS'))
            ->firstOrFail();

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
            ->where('code', 'OPERATOR')
            ->whereHas('department', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('code', 'GATE'))
            ->firstOrFail();

        $this->assertTrue(
            TeamGrant::query()
                ->active()
                ->where('team_id', $operatorTeam->id)
                ->where('event_id', $event->id)
                ->whereHas('permissionRole', fn ($query) => $query->where('code', 'ic_operator'))
                ->exists(),
        );

        $sam = Staff::query()->where('email', 'sam.shiftlead@idaho-burners.test')->firstOrFail();
        $roles = (new EffectiveRoleResolver)->resolveForStaff($sam);
        $this->assertSame('shift_lead', $roles->first()?->roleCode);

        $ingrid = Staff::query()->where('email', 'ingrid.iclead@idaho-burners.test')->firstOrFail();
        $icRoles = (new EffectiveRoleResolver)->resolveForStaff($ingrid, $event);
        $this->assertSame('ic_lead', $icRoles->sole()->roleCode);

        $omar = Staff::query()->where('email', 'omar.icoperator@idaho-burners.test')->firstOrFail();
        $this->assertSame(
            'ic_operator',
            (new EffectiveRoleResolver)->resolveForStaff($omar, $event)->sole()->roleCode,
        );
    }

    public function test_seeded_users_can_authenticate_with_the_documented_development_password(): void
    {
        $this->seedScenario();

        $vera = User::query()->where('email', 'vera.staff@idaho-burners.test')->firstOrFail();

        $this->assertTrue(Hash::check(DevelopmentScenarioCatalog::DEFAULT_PASSWORD, $vera->password));
    }

    public function test_database_seeder_succeeds_with_model_events_disabled(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            11,
            User::query()->where('email', 'like', '%@idaho-burners.test')->count(),
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
            'personas' => User::query()->where('email', 'like', '%@idaho-burners.test')->count(),
            'team_grants' => TeamGrant::query()
                ->whereHas('team.department', fn ($query) => $query->where('organization_id', $organization->id))
                ->count(),
        ];
    }
}
