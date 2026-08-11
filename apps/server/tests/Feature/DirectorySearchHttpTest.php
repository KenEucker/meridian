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
use App\Services\Directory\DirectorySearchService;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Directory handle search (M18.74; DIR-031 through DIR-034; technical spec
 * 21E.7).
 *
 * The load-bearing assertions are the negative ones: a legal name, a
 * preferred name, a department name, a team name, and a role name match
 * nothing, and an unauthorized person is absent from the index itself rather
 * than filtered out of results — which is what keeps them out of counts,
 * partial matches, and timing.
 */
class DirectorySearchHttpTest extends TestCase
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
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->rangerLeads);

        $this->person('olive', [[$this->organizersTeam, 'member']]);
        $this->person('dana', [[$this->rangerLeads, 'member'], [$this->dirt, 'member']]);
        $this->person('tess', [[$this->dirt, 'lead']]);
        $this->person('vera', [[$this->dirt, 'member']]);
        // Greta is visible only to Gate leadership and organizers; she is the
        // unauthorized handle in every negative assertion below.
        $this->person('greta', [[$this->gateCrew, 'member']]);
        // Milo holds three visible locations.
        $this->person('milo', [
            [$this->dirt, 'member'],
            [$this->gateCrew, 'member'],
            [$this->organizersTeam, 'member'],
        ]);
    }

    public function test_a_handle_matches_and_returns_the_location_as_a_breadcrumb(): void
    {
        $results = $this->searchAs('olive', 'Tess')->json('results');

        $this->assertCount(1, $results);
        $this->assertSame('Tess', $results[0]['handle']);
        $this->assertSame('Rangers → Dirt → Team Lead', $results[0]['breadcrumb']);
        $this->assertSame((string) $this->dirt->getKey(), $results[0]['location']['team_id']);
    }

    public function test_a_legal_name_and_a_preferred_name_match_nothing(): void
    {
        // Every seeded record carries these sentinels in its legal and
        // preferred name fields (DIR-032).
        $this->assertSame([], $this->searchAs('olive', 'SENTINEL-LEGAL')->json('results'));
        $this->assertSame([], $this->searchAs('olive', 'SENTINEL-PREFERRED')->json('results'));
    }

    public function test_a_department_name_a_team_name_and_a_role_name_match_nothing(): void
    {
        $this->assertSame([], $this->searchAs('olive', 'Rangers')->json('results'));
        $this->assertSame([], $this->searchAs('olive', 'Dirt')->json('results'));
        $this->assertSame([], $this->searchAs('olive', 'Team Lead')->json('results'));
    }

    public function test_an_unauthorized_handle_is_absent_from_results_counts_and_partial_matches(): void
    {
        // Vera is ordinary staff: Greta is not hers to find (DIR-033).
        $this->assertSame([], $this->searchAs('vera', 'Greta')->json('results'));

        // A partial match confirms nothing either.
        $this->assertSame([], $this->searchAs('vera', 'Gre')->json('results'));

        // And an organizer finds her, so the absence above is authorization
        // rather than a broken index.
        $this->assertCount(1, $this->searchAs('olive', 'Greta')->json('results'));
    }

    public function test_an_unauthorized_person_is_absent_from_the_index_rather_than_removed_from_results(): void
    {
        $index = app(DirectorySearchService::class)->index(
            $this->users['vera'],
            new DirectoryContext($this->organization),
        );

        $handles = array_column($index, 'handle');

        // The index vera's searches run against simply has no Greta entry —
        // nothing is filtered out at match time (DIR-033).
        $this->assertNotContains('Greta', $handles);
        $this->assertContains('Tess', $handles);
        $this->assertContains('Dana', $handles);
    }

    public function test_a_person_with_several_visible_locations_produces_a_row_per_location(): void
    {
        $results = $this->searchAs('olive', 'Milo')->json('results');

        $this->assertCount(3, $results);
        $this->assertEqualsCanonicalizing([
            'Gate → Gate Crew → Member',
            'Organizers → Core → Member',
            'Rangers → Dirt → Member',
        ], array_column($results, 'breadcrumb'));
    }

    public function test_search_reaches_only_the_authorized_locations_of_a_visible_person(): void
    {
        // Dana leads Rangers: she finds Milo's Rangers location and not his
        // Gate one (DIR-034), while his Organizers location is baseline.
        $results = $this->searchAs('dana', 'Milo')->json('results');

        $this->assertEqualsCanonicalizing([
            'Organizers → Core → Member',
            'Rangers → Dirt → Member',
        ], array_column($results, 'breadcrumb'));
    }

    public function test_the_event_search_reads_the_event_population(): void
    {
        $event = Event::factory()->create(['organization_id' => $this->organization->getKey()]);

        foreach ([$this->organizers, $this->rangers] as $department) {
            EventDepartmentAssignment::factory()->create([
                'event_id' => $event->getKey(),
                'department_id' => $department->getKey(),
            ]);
        }

        // Gate is not working this event: Greta has no entry in its index,
        // and Milo's Gate location is not among his rows.
        $response = $this->actingAsClient($this->users['olive'])
            ->getJson("/api/events/{$event->getKey()}/directory/search?q=Greta")
            ->assertOk();

        $this->assertSame([], $response->json('results'));

        $milo = $this->actingAsClient($this->users['olive'])
            ->getJson("/api/events/{$event->getKey()}/directory/search?q=Milo")
            ->assertOk()
            ->json('results');

        $this->assertEqualsCanonicalizing([
            'Organizers → Core → Member',
            'Rangers → Dirt → Member',
        ], array_column($milo, 'breadcrumb'));
    }

    public function test_a_disabled_directory_answers_404_on_search(): void
    {
        $this->organization->forceFill(['directory_enabled' => false])->save();

        $this->actingAsClient($this->users['olive'])
            ->getJson("/api/organizations/{$this->organization->getKey()}/directory/search?q=Tess")
            ->assertNotFound();
    }

    public function test_a_blank_query_returns_no_results_rather_than_everybody(): void
    {
        $this->assertSame([], $this->searchAs('olive', '')->json('results'));
        $this->assertSame([], $this->searchAs('olive', '   ')->json('results'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function searchAs(string $key, string $query): TestResponse
    {
        return $this->actingAsClient($this->users[$key])
            ->getJson("/api/organizations/{$this->organization->getKey()}/directory/search?q=".urlencode($query))
            ->assertOk();
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
     * @param  list<array{0: Team, 1: string}>  $teams
     */
    private function person(string $key, array $teams): void
    {
        $staff = Staff::factory()->create([
            'handle' => ucfirst($key),
            'legal_name' => 'SENTINEL-LEGAL '.ucfirst($key),
            'preferred_name' => 'SENTINEL-PREFERRED '.ucfirst($key),
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
    }
}
