<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Directory in the offline read set (M18.77; DIR-037; CLIENT-021,
 * CLIENT-022; technical spec 21E.8).
 *
 * The property under test is M18.47's, applied to a surface whose whole
 * content is people: the device holds what its user could have retrieved from
 * the API and nothing else. The sections are composed by the same service the
 * online chart read serves, and these tests assert the consequences — an
 * unauthorized handle is absent from the stored set, a demoted lead's member
 * list is gone on the next composition, no PII field travels, and a disabled
 * Directory synchronizes nothing.
 */
class DirectoryOfflineReadSetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $organizers;

    private Department $rangers;

    private Department $gate;

    private Team $organizersTeam;

    private Team $rangerLeads;

    private Team $dirt;

    private Team $gateCrew;

    private TeamGrant $rangerLeadGrant;

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

        $this->organization->forceFill([
            'organizers_department_id' => $this->organizers->getKey(),
        ])->save();

        $this->organizersTeam = $this->team($this->organizers, 'Core');
        $this->rangerLeads = $this->team($this->rangers, 'Ranger Leads');
        $this->dirt = $this->team($this->rangers, 'Dirt');
        $this->gateCrew = $this->team($this->gate, 'Gate Crew');

        $this->grant(PermissionCatalog::ROLE_ORGANIZER, $this->organizersTeam);
        $this->rangerLeadGrant = $this->grant(
            PermissionCatalog::ROLE_DEPARTMENT_LEAD,
            $this->rangerLeads,
        );

        $this->person('dana', [[$this->rangerLeads, 'member']]);
        $this->person('vera', [[$this->dirt, 'member']]);
        $this->person('greta', [[$this->gateCrew, 'member']]);
    }

    public function test_the_stored_set_carries_the_authorized_projection_and_no_pii_field(): void
    {
        $sections = $this->sectionsFor('dana');

        $this->assertArrayHasKey('directory_departments', $sections);
        $this->assertArrayHasKey('directory_people', $sections);

        $handles = array_column($sections['directory_people'], 'handle');
        $this->assertContains('Vera', $handles);

        $body = json_encode($sections['directory_people'], JSON_THROW_ON_ERROR)
            .json_encode($sections['directory_departments'], JSON_THROW_ON_ERROR);

        foreach (['SENTINEL-LEGAL', 'SENTINEL-PREFERRED', 'sentinel-email', '555-0100'] as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $body,
                'A personally identifying field reached the stored Directory set (DIR-037, DIR-028).',
            );
        }
    }

    public function test_an_unauthorized_handle_is_absent_from_the_offline_index(): void
    {
        // Vera is ordinary staff: her device's set carries the leadership she
        // may see and not Greta, who is nobody's to find offline either
        // (DIR-033, DIR-037).
        $sections = $this->sectionsFor('vera');

        $handles = array_column($sections['directory_people'], 'handle');

        $this->assertContains('Dana', $handles);
        $this->assertNotContains('Greta', $handles);
        $this->assertNotContains('Vera', $handles);

        $this->assertStringNotContainsString(
            'Greta',
            json_encode($sections, JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_demoted_department_leads_stored_member_list_is_gone_after_the_next_refresh(): void
    {
        $before = $this->sectionsFor('dana');
        $this->assertContains('Vera', array_column($before['directory_people'], 'handle'));

        // The grant is revoked; the next composition simply resolves without
        // it (CLIENT-022; M18.49).
        $this->rangerLeadGrant->forceFill(['revoked_at' => now()])->save();

        $after = $this->sectionsFor('dana');
        $this->assertNotContains('Vera', array_column($after['directory_people'] ?? [], 'handle'));
    }

    public function test_a_disabled_directory_synchronizes_nothing(): void
    {
        $this->organization->forceFill(['directory_enabled' => false])->save();

        $sections = $this->sectionsFor('dana');

        // Absent, not empty (DIR-005): no section exists for a surface the
        // organization has withdrawn.
        $this->assertArrayNotHasKey('directory_departments', $sections);
        $this->assertArrayNotHasKey('directory_people', $sections);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function sectionsFor(string $key): array
    {
        return $this->actingAsClient($this->users[$key])
            ->getJson(route('api.offline-read-set'))
            ->assertOk()
            ->json('sections');
    }

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

    private function grant(string $roleCode, Team $team): TeamGrant
    {
        return TeamGrant::factory()->create([
            'team_id' => $team->getKey(),
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()
                ->where('code', $roleCode)
                ->firstOrFail()
                ->getKey(),
        ]);
    }

    /**
     * @param  list<array{0: Team, 1: string}>  $teams
     */
    private function person(string $key, array $teams): void
    {
        $staff = Staff::factory()->create([
            'handle' => ucfirst($key),
            'legal_name' => 'SENTINEL-LEGAL '.ucfirst($key),
            'preferred_name' => 'SENTINEL-PREFERRED '.ucfirst($key),
            'email' => $key.'@sentinel-email.test',
            'phone' => '555-0100',
        ]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->getKey());

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'staff_id' => $staff->getKey(),
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        $this->staff[$key] = $staff;
        $this->users[$key] = $user;

        foreach ($teams as [$team, $role]) {
            $membership = DepartmentMembership::factory()->create([
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
    }
}
