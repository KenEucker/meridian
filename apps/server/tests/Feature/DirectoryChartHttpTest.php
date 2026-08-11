<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Directory chart read (M18.73; DIR-005 through DIR-015, DIR-027 through
 * DIR-030; technical spec 21E.2, 21E.3, 21E.6, 21E.9, 21E.10).
 *
 * The privacy assertions here are the ones that make DIR-028 checkable: the
 * scenario seeds every personally identifying field with a sentinel value and
 * asserts none of them reaches the response body, because a projection that
 * carried them to be hidden at render would pass every other test in this
 * file.
 */
class DirectoryChartHttpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $organizers;

    private Department $rangers;

    private Department $gate;

    private Department $emptyDepartment;

    private Team $organizersTeam;

    private Team $rangerLeads;

    private Team $dirt;

    private Team $emptyTeam;

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
        $this->emptyDepartment = $this->department('Airport');

        $this->organization->forceFill([
            'organizers_department_id' => $this->organizers->getKey(),
        ])->save();

        $this->organizersTeam = $this->team($this->organizers, 'Core');
        $this->rangerLeads = $this->team($this->rangers, 'Ranger Leads');
        $this->dirt = $this->team($this->rangers, 'Dirt');
        $this->emptyTeam = $this->team($this->rangers, 'Zero Crew');
        $this->gateCrew = $this->team($this->gate, 'Gate Crew');

        $this->grant(PermissionCatalog::ROLE_ORGANIZER, $this->organizersTeam);
        $this->grant(PermissionCatalog::ROLE_DEPARTMENT_LEAD, $this->rangerLeads);

        $this->person('olive', [[$this->organizersTeam, 'member']]);
        $this->person('dana', [[$this->rangerLeads, 'member'], [$this->dirt, 'member']]);
        $this->person('tess', [[$this->dirt, 'lead'], [$this->gateCrew, 'member']]);
        $this->person('vera', [[$this->dirt, 'member']]);
        // The persona who works three departments (DIR-014).
        $this->person('milo', [
            [$this->dirt, 'member'],
            [$this->gateCrew, 'member'],
            [$this->organizersTeam, 'member'],
        ]);
        $this->person('pia', [], department: $this->rangers);
    }

    /*
    |--------------------------------------------------------------------------
    | Availability (DIR-005; technical spec 21E.9)
    |--------------------------------------------------------------------------
    */

    public function test_a_disabled_directory_answers_404_identically_for_a_permitted_and_an_unpermitted_viewer(): void
    {
        $this->organization->forceFill(['directory_enabled' => false])->save();

        $stranger = User::factory()->create();

        $permitted = $this->actingAsClient($this->users['olive'])
            ->getJson("/api/organizations/{$this->organization->getKey()}/directory");
        $unpermitted = $this->actingAsClient($stranger)
            ->getJson("/api/organizations/{$this->organization->getKey()}/directory");

        $permitted->assertNotFound();
        $unpermitted->assertNotFound();

        // Absent, not refused: the two answers are indistinguishable, so a 403
        // cannot confirm the feature exists and is switched off. (The debug
        // stack trace the test environment appends is not part of a production
        // body, so status and message are what is compared.)
        $this->assertSame($permitted->json('message'), $unpermitted->json('message'));

        $event = $this->eventWithDepartments([$this->rangers]);

        $this->actingAsClient($this->users['olive'])
            ->getJson("/api/events/{$event->getKey()}/directory")
            ->assertNotFound();
    }

    public function test_a_viewer_without_standing_in_the_organization_is_refused(): void
    {
        // Unauthenticated first: actingAsClient authenticates the rest of the
        // test lifecycle.
        $this->getJson("/api/organizations/{$this->organization->getKey()}/directory")
            ->assertUnauthorized();

        $stranger = User::factory()->create();

        $this->actingAsClient($stranger)
            ->getJson("/api/organizations/{$this->organization->getKey()}/directory")
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The projection carries no PII (DIR-027, DIR-028)
    |--------------------------------------------------------------------------
    */

    public function test_no_personally_identifying_field_appears_anywhere_in_the_response_body(): void
    {
        $body = (string) $this->chartFor('olive')->getContent();

        // The sentinel values seeded onto every staff record by person().
        foreach ([
            'SENTINEL-LEGAL',
            'SENTINEL-PREFERRED',
            'sentinel-email',
            '555-0100',
            'SENTINEL-EMERGENCY',
            '555-0199',
            'SENTINEL-CITY',
            '1970-01-01',
        ] as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $body,
                'A personally identifying field reached the Directory response body (DIR-028).',
            );
        }

        // And the field names themselves are not part of the shape.
        foreach (['legal_name', 'preferred_name', 'email', 'phone', 'emergency', 'date_of_birth'] as $field) {
            $this->assertStringNotContainsString($field, $body);
        }

        // What the projection does carry: the handle.
        $this->assertStringContainsString('Olive', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Chart structure (DIR-009 through DIR-015)
    |--------------------------------------------------------------------------
    */

    public function test_the_organizers_department_is_presented_first_and_empty_branches_render(): void
    {
        $departments = $this->chartFor('olive')->json('departments');

        $this->assertSame((string) $this->organizers->getKey(), $departments[0]['id']);
        $this->assertTrue($departments[0]['is_organizers']);

        $byId = collect($departments)->keyBy('id');

        // An empty department renders as itself (DIR-015)...
        $empty = $byId->get((string) $this->emptyDepartment->getKey());
        $this->assertNotNull($empty);
        $this->assertSame([], $empty['leads']);
        $this->assertSame([], $empty['prospectives']);

        // ...and an empty team does too.
        $rangers = $byId->get((string) $this->rangers->getKey());
        $emptyTeam = collect($rangers['teams'])->firstWhere('id', (string) $this->emptyTeam->getKey());
        $this->assertNotNull($emptyTeam);
        $this->assertSame([], $emptyTeam['leads']);
        $this->assertSame([], $emptyTeam['members']);
    }

    public function test_a_team_lead_appears_once_in_their_own_team(): void
    {
        $rangers = collect($this->chartFor('olive')->json('departments'))
            ->firstWhere('id', (string) $this->rangers->getKey());
        $dirt = collect($rangers['teams'])->firstWhere('id', (string) $this->dirt->getKey());

        $tessId = (string) $this->staff['tess']->getKey();

        $this->assertContains($tessId, $dirt['leads']);
        $this->assertNotContains($tessId, $dirt['members']);
    }

    public function test_a_person_in_three_departments_appears_in_every_authorized_one(): void
    {
        $response = $this->chartFor('olive');
        $miloId = (string) $this->staff['milo']->getKey();

        $departments = collect($response->json('departments'));
        $placements = [];

        foreach ($departments as $department) {
            foreach ($department['teams'] as $team) {
                if (in_array($miloId, $team['members'], true)) {
                    $placements[] = $team['id'];
                }
            }
        }

        $this->assertEqualsCanonicalizing([
            (string) $this->dirt->getKey(),
            (string) $this->gateCrew->getKey(),
            (string) $this->organizersTeam->getKey(),
        ], $placements);

        $milo = collect($response->json('people'))->firstWhere('id', $miloId);
        $this->assertCount(3, $milo['locations']);
    }

    public function test_unauthorized_locations_are_absent_from_an_authorized_persons_entry(): void
    {
        // Vera sees Tess as the team lead of Dirt; Tess's Gate Crew membership
        // is not vera's to know about (DIR-030).
        $response = $this->chartFor('vera');
        $tess = collect($response->json('people'))
            ->firstWhere('id', (string) $this->staff['tess']->getKey());

        $this->assertNotNull($tess);
        $this->assertCount(1, $tess['locations']);
        $this->assertSame((string) $this->dirt->getKey(), $tess['locations'][0]['team_id']);

        // And the chart lists her nowhere else either.
        $gate = collect($response->json('departments'))
            ->firstWhere('id', (string) $this->gate->getKey());
        $gateCrew = collect($gate['teams'])->firstWhere('id', (string) $this->gateCrew->getKey());
        $this->assertNotContains((string) $this->staff['tess']->getKey(), $gateCrew['members']);
    }

    public function test_prospectives_render_as_a_department_level_section(): void
    {
        $rangers = collect($this->chartFor('dana')->json('departments'))
            ->firstWhere('id', (string) $this->rangers->getKey());

        $this->assertContains((string) $this->staff['pia']->getKey(), $rangers['prospectives']);
    }

    /*
    |--------------------------------------------------------------------------
    | Years of service (open question 37, settled; DIR-029)
    |--------------------------------------------------------------------------
    */

    public function test_years_of_service_counts_distinct_years_with_recorded_hours(): void
    {
        $event = $this->eventWithDepartments([$this->rangers]);

        // Three shifts across two distinct years, with a gap year: reads 2.
        foreach (['2019-08-01 10:00:00', '2019-08-02 10:00:00', '2021-08-01 10:00:00'] as $start) {
            $shift = Shift::factory()->create([
                'event_id' => $event->getKey(),
                'department_id' => $this->rangers->getKey(),
            ]);

            HoursWorked::factory()->create([
                'shift_id' => $shift->getKey(),
                'staff_id' => $this->staff['vera']->getKey(),
                'actual_started_at' => Carbon::parse($start),
                'actual_ended_at' => Carbon::parse($start)->addHours(2),
            ]);
        }

        $people = collect($this->chartFor('olive')->json('people'));

        $this->assertSame(2, $people->firstWhere('id', (string) $this->staff['vera']->getKey())['years_of_service']);

        // No recorded hours reads zero rather than a guess.
        $this->assertSame(0, $people->firstWhere('id', (string) $this->staff['pia']->getKey())['years_of_service']);
    }

    /*
    |--------------------------------------------------------------------------
    | Context populations (DIR-006 through DIR-008; technical spec 21E.2)
    |--------------------------------------------------------------------------
    */

    public function test_the_event_and_organization_populations_do_not_leak_into_each_other(): void
    {
        $event = $this->eventWithDepartments([$this->organizers, $this->rangers]);

        $eventResponse = $this->actingAsClient($this->users['olive'])
            ->getJson("/api/events/{$event->getKey()}/directory")
            ->assertOk();

        $this->assertSame('event', $eventResponse->json('context.scope'));

        // Gate is not working this event: its department is not drawn and its
        // people are absent, however visible they are at organization level.
        $eventDepartmentIds = array_column($eventResponse->json('departments'), 'id');
        $this->assertNotContains((string) $this->gate->getKey(), $eventDepartmentIds);

        $eventPeople = array_column($eventResponse->json('people'), 'id');
        $gateOnly = $this->personInGateOnly();
        $this->assertNotContains((string) $gateOnly->getKey(), $eventPeople);

        // The organization chart keeps the whole population.
        $organizationResponse = $this->chartFor('olive');
        $this->assertSame('organization', $organizationResponse->json('context.scope'));
        $this->assertContains(
            (string) $gateOnly->getKey(),
            array_column($organizationResponse->json('people'), 'id'),
        );

        // Tess's Gate Crew location is likewise absent from her event entry.
        $tess = collect($eventResponse->json('people'))
            ->firstWhere('id', (string) $this->staff['tess']->getKey());
        $this->assertNotContains(
            (string) $this->gateCrew->getKey(),
            array_column($tess['locations'], 'team_id'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bounded composition (technical spec 21E.10)
    |--------------------------------------------------------------------------
    */

    public function test_the_chart_is_composed_in_a_bounded_number_of_queries(): void
    {
        // Widen the organization well past the fixture: more departments, more
        // teams, more people, and repeated placements.
        foreach (range(1, 6) as $i) {
            $department = $this->department("Extra {$i}");
            $teamA = $this->team($department, 'Alpha');
            $teamB = $this->team($department, 'Bravo');

            foreach (range(1, 4) as $j) {
                $this->person("extra-{$i}-{$j}", [[$teamA, 'member'], [$teamB, 'member']]);
            }
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->chartFor('olive');

        $this->assertLessThanOrEqual(
            30,
            $queries,
            "The Directory chart took {$queries} queries. The composition must be bounded — "
            .'not one query per department, team, lead, or person (technical spec 21E.10).',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function chartFor(string $key): TestResponse
    {
        return $this->actingAsClient($this->users[$key])
            ->getJson("/api/organizations/{$this->organization->getKey()}/directory")
            ->assertOk();
    }

    private function eventWithDepartments(array $departments): Event
    {
        $event = Event::factory()->create(['organization_id' => $this->organization->getKey()]);

        foreach ($departments as $department) {
            EventDepartmentAssignment::factory()->create([
                'event_id' => $event->getKey(),
                'department_id' => $department->getKey(),
            ]);
        }

        return $event;
    }

    private function personInGateOnly(): Staff
    {
        if (! isset($this->staff['greta'])) {
            $this->person('greta', [[$this->gateCrew, 'member']]);
        }

        return $this->staff['greta'];
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
     * One staff member with every personally identifying field seeded to a
     * sentinel value, so the PII test can assert their absence from the body.
     *
     * @param  list<array{0: Team, 1: string}>  $teams
     */
    private function person(string $key, array $teams, ?Department $department = null): void
    {
        $staff = Staff::factory()->create([
            'handle' => ucfirst($key),
            'legal_name' => 'SENTINEL-LEGAL '.ucfirst($key),
            'preferred_name' => 'SENTINEL-PREFERRED '.ucfirst($key),
            'email' => $key.'@sentinel-email.test',
            'phone' => '555-0100',
            'city' => 'SENTINEL-CITY',
            'date_of_birth' => '1970-01-01',
            'emergency_contact_name' => 'SENTINEL-EMERGENCY',
            'emergency_contact_phone' => '555-0199',
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
