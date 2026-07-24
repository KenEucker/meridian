<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Orchid\Layouts\ScopeFiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Organization / department / team narrowing for God Mode list screens.
 *
 * Without narrowing these screens span every organization on the node, which
 * makes them unusable once more than one organization exists.
 */
class OrchidScopeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_filters_only_offer_narrowing_levels_above_the_listed_model(): void
    {
        $levels = fn (string $model): array => collect(ScopeFiltersLayout::for($model)->filters())
            ->map(fn ($filter): string => $filter->name())
            ->all();

        $this->assertSame(['Organization', 'Department', 'Team'], $levels(Shift::class));
        $this->assertSame(['Organization', 'Department', 'Team'], $levels(Staff::class));

        // A screen never offers its own level: filtering teams by team, or
        // departments by department, would always return a single row.
        $this->assertSame(['Organization', 'Department'], $levels(Team::class));
        $this->assertSame(['Organization'], $levels(Department::class));
        $this->assertSame(['Organization'], $levels(Event::class));
    }

    public function test_department_and_team_options_cascade_from_the_selected_organization(): void
    {
        $mine = Organization::factory()->create();
        $other = Organization::factory()->create();
        $mineDepartment = Department::factory()->for($mine)->create(['name' => 'Rangers']);
        $otherDepartment = Department::factory()->for($other)->create(['name' => 'Foreign Gate']);
        Team::factory()->for($mineDepartment)->create(['name' => 'Dirt']);
        Team::factory()->for($otherDepartment)->create(['name' => 'Foreign Credentials']);

        $response = $this->actingAs($this->admin('platform.shifts'))
            ->get(route('platform.shifts', ['scope_organization' => $mine->id]));

        $response->assertOk();
        $response->assertSee('Rangers');
        $response->assertSee('Dirt');
        $response->assertDontSee('Foreign Gate');
        $response->assertDontSee('Foreign Credentials');
    }

    public function test_scope_filters_render_as_a_visible_bar(): void
    {
        $this->assertSame(
            ScopeFiltersLayout::TEMPLATE_LINE,
            ScopeFiltersLayout::for(Shift::class)->template,
        );

        $response = $this->actingAs($this->admin('platform.shifts'))->get(route('platform.shifts'));

        $response->assertOk();
        $response->assertSee('All organizations');
        $response->assertSee('All departments');
        $response->assertSee('All teams');
    }

    public function test_department_list_narrows_to_the_selected_organization(): void
    {
        $mine = Organization::factory()->create(['name' => 'Idaho Burners']);
        $other = Organization::factory()->create(['name' => 'Other Org']);
        Department::factory()->for($mine)->create(['name' => 'Rangers']);
        Department::factory()->for($other)->create(['name' => 'Foreign Gate']);

        $response = $this->actingAs($this->admin('platform.departments'))
            ->get(route('platform.departments', ['scope_organization' => $mine->id]));

        $response->assertOk();
        $response->assertSee('Rangers');
        $response->assertDontSee('Foreign Gate');
    }

    public function test_shift_list_narrows_by_organization_department_and_team(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $peerDepartment = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $peerTeam = Team::factory()->for($department)->create();

        $this->shift($event, $department, $team, 'Led Shift');
        $this->shift($event, $department, $peerTeam, 'Peer Team Shift');
        $this->shift($event, $peerDepartment, Team::factory()->for($peerDepartment)->create(), 'Peer Dept Shift');

        $foreignOrganization = Organization::factory()->create();
        $foreignDepartment = Department::factory()->for($foreignOrganization)->create();
        $this->shift(
            Event::factory()->for($foreignOrganization)->create(),
            $foreignDepartment,
            Team::factory()->for($foreignDepartment)->create(),
            'Foreign Org Shift',
        );

        $admin = $this->admin('platform.shifts');

        $byOrganization = $this->actingAs($admin)
            ->get(route('platform.shifts', ['scope_organization' => $organization->id]));
        $byOrganization->assertOk();
        $byOrganization->assertSee('Led Shift');
        $byOrganization->assertSee('Peer Dept Shift');
        $byOrganization->assertDontSee('Foreign Org Shift');

        $byDepartment = $this->actingAs($admin)
            ->get(route('platform.shifts', ['scope_department' => $department->id]));
        $byDepartment->assertOk();
        $byDepartment->assertSee('Led Shift');
        $byDepartment->assertSee('Peer Team Shift');
        $byDepartment->assertDontSee('Peer Dept Shift');

        $byTeam = $this->actingAs($admin)
            ->get(route('platform.shifts', ['scope_team' => $team->id]));
        $byTeam->assertOk();
        $byTeam->assertSee('Led Shift');
        $byTeam->assertDontSee('Peer Team Shift');
    }

    public function test_staff_list_narrows_through_memberships(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();

        $member = Staff::factory()->create(['legal_name' => 'Vera Member', 'preferred_name' => null]);
        $membership = DepartmentMembership::factory()->for($department)->for($member)->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $member->id,
            'department_membership_id' => $membership->id,
        ]);

        Staff::factory()->create(['legal_name' => 'Unaffiliated Person', 'preferred_name' => null]);

        $response = $this->actingAs($this->admin('platform.staff'))
            ->get(route('platform.staff', ['scope_team' => $team->id]));

        $response->assertOk();
        $response->assertSee('Vera Member');
        $response->assertDontSee('Unaffiliated Person');
    }

    public function test_document_list_narrows_to_the_department_audience_scope(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        PolicyDocument::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'title' => 'Ranger Department Policy',
        ]);
        PolicyDocument::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'title' => 'Organization Wide Policy',
        ]);

        $response = $this->actingAs($this->admin('platform.policy-documents'))
            ->get(route('platform.policy-documents', ['scope_department' => $department->id]));

        $response->assertOk();
        $response->assertSee('Ranger Department Policy');
        $response->assertDontSee('Organization Wide Policy');
    }

    public function test_cleared_filter_does_not_narrow_the_list(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        Department::factory()->for($first)->create(['name' => 'First Dept']);
        Department::factory()->for($second)->create(['name' => 'Second Dept']);

        $response = $this->actingAs($this->admin('platform.departments'))
            ->get(route('platform.departments', ['scope_organization' => '']));

        $response->assertOk();
        $response->assertSee('First Dept');
        $response->assertSee('Second Dept');
    }

    private function shift(Event $event, Department $department, Team $team, string $title): Shift
    {
        $startsAt = Carbon::now()->addWeek();

        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
        ]);
    }

    private function admin(string $permission): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                $permission => true,
            ],
        ]);
    }
}
