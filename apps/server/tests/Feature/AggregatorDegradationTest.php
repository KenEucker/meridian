<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Deployment;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Surfaces that compose other modules' contributions omit the inactive ones and
 * keep working (MOD-019, HORIZON-017; technical spec 15A.7; data/API 5.9;
 * M19.18).
 *
 * The four department operations reads, the dashboard, and the Event Horizon are
 * core endpoints that read from most of the product. Data/API 5.9 is explicit
 * about what that means: "they omit the sections whose modules are inactive and
 * return the rest… they never refuse on module state." So every assertion here
 * comes in a pair — the section owned by the switched-off module is gone, and
 * something core beside it is still there and still answers `200`.
 *
 * **Absent, not empty.** The section key is missing from the payload rather than
 * present with nothing in it, and the tests assert the key's absence rather than
 * an empty array. An empty `searchable_equipment` is a claim that this
 * department holds no equipment; the key's absence says this Meridian has none.
 * A client that renders "No equipment checked out" over the first would be
 * telling somebody to go and look for a radio that was never issued.
 *
 * **One module at a time.** Each test switches off exactly one module and reads
 * the same scenario, because the failure this guards against is a read that
 * degrades correctly for the module somebody tested and blanks for its
 * neighbour.
 *
 * The Briefing and Insights have no surface on this build (milestones 15 and 17
 * are unstarted), so their contribution to an aggregator is the dashboard's two
 * catalogued-but-deferred widgets. Those are covered here with the rest of the
 * inventory; the surfaces themselves declare their own module when they land.
 */
class AggregatorDegradationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $department;

    private Event $event;

    private Shift $shift;

    /** Holds the department operations roles, so the reads are never refused. */
    private User $lead;

    private Staff $member;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-07-04 18:00:00'));
        $this->seed(PermissionCatalogSeeder::class);

        $this->organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $this->department = Department::factory()->for($this->organization)->create(['name' => 'Rangers']);
        $this->event = Event::factory()->for($this->organization)->create([
            'name' => 'Emberfall 2027',
            'timezone' => 'America/Los_Angeles',
            'active_event_window_starts_at' => Carbon::parse('2027-07-03 00:00:00'),
            'active_event_window_ends_at' => Carbon::parse('2027-07-10 00:00:00'),
        ]);

        $this->member = Staff::factory()->create(['handle' => 'vera']);
        app(DepartmentMembershipService::class)
            ->assignStaffWithDefaultTeam($this->member, $this->department);
        $this->department->load('defaultTeam');
        $team = $this->department->defaultTeam;

        $this->shift = Shift::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Day Patrol',
            'starts_at' => Carbon::parse('2027-07-04 16:00:00'),
            'ends_at' => Carbon::parse('2027-07-04 22:00:00'),
            'capacity' => 3,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $this->shift->id,
            'staff_id' => $this->member->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'removed_at' => null,
        ]);

        AttendanceRecord::factory()->create([
            'shift_id' => $this->shift->id,
            'staff_id' => $this->member->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => Carbon::parse('2027-07-04 16:05:00'),
        ]);

        EventDepartmentPresence::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->department->id,
            'staff_id' => $this->member->id,
            'current_state' => EventDepartmentPresence::STATE_ON_SITE,
            'marked_on_site_at' => Carbon::parse('2027-07-04 15:50:00'),
            'marked_off_site_at' => null,
        ]);

        $radio = EquipmentItem::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'Radio 12',
            'asset_tag' => 'R-12',
        ]);

        EquipmentCheckout::factory()->create([
            'equipment_item_id' => $radio->id,
            'staff_id' => $this->member->id,
            'shift_id' => $this->shift->id,
            'event_id' => $this->event->id,
            'returned_at' => null,
        ]);

        Deployment::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->department->id,
            'name' => 'Gate 1',
        ]);

        $this->lead = $this->leadOf($team);
    }

    /*
    |--------------------------------------------------------------------------
    | Department operations reads (SLB-001 through SLB-022; data/API 5.9)
    |--------------------------------------------------------------------------
    */

    public function test_every_department_operations_read_carries_its_module_owned_sections_while_the_modules_run(): void
    {
        $overview = $this->read('overview');
        $overview->assertJsonStructure(['shifts', 'assignments', 'equipment_out', 'deployments']);
        $this->assertSame('Radio 12', $overview->json('equipment_out.0.item_name'));
        $this->assertSame('Gate 1', $overview->json('deployments.0.name'));

        $logistics = $this->read('logistics');
        $logistics->assertJsonStructure(['searchable_shifts', 'searchable_equipment', 'checkout_inventory']);

        $this->read('operations')->assertJsonStructure(['deployments', 'rows', 'equipment_out_count']);
        $this->read('planning')->assertJsonStructure(['teams', 'rows']);
    }

    public function test_the_four_reads_omit_scheduling_and_keep_answering(): void
    {
        $this->deactivate(ModuleKey::Scheduling);

        $overview = $this->read('overview');
        $this->assertOmits($overview, ['shifts', 'selected_shift_id', 'exceptions', 'assignments']);
        // Presence is core: the department still knows who is standing in it.
        $this->assertSame(1, $overview->json('on_site_count'));
        /*
         * The overview's equipment section is the *selected shift's* equipment,
         * so it needs both modules: Equipment still runs, and the key is
         * therefore present, but there is no shift to have issued anything
         * against and the list is empty rather than absent.
         */
        $this->assertSame([], $overview->json('equipment_out'));

        $logistics = $this->read('logistics');
        $this->assertOmits($logistics, ['searchable_shifts']);
        $this->assertContains(
            'vera',
            collect($logistics->json('searchable_staff'))->pluck('handle')->all(),
        );
        $workspace = $logistics->json('staff_workspaces.'.(string) $this->member->id);
        $this->assertArrayNotHasKey('shift_cards', $workspace);
        $this->assertArrayNotHasKey('future_signups', $workspace);
        // The off-site decision is core and is the whole reason a desk without a
        // schedule is still a desk (requirements 5.8).
        $this->assertArrayHasKey('can_go_off_site', $workspace);
        $this->assertArrayHasKey('open_equipment', $workspace);

        $operations = $this->read('operations');
        $this->assertOmits($operations, ['rows']);
        $this->assertSame('Gate 1', $operations->json('deployments.0.name'));

        $planning = $this->read('planning');
        $this->assertOmits($planning, ['rows']);
        // The table's own frame is core, so the surface renders rather than
        // failing on a missing filter list.
        $this->assertIsArray($planning->json('teams'));
        $this->assertNull($planning->json('filters.team_id'));
    }

    public function test_the_four_reads_omit_equipment_and_keep_answering(): void
    {
        $this->deactivate(ModuleKey::Equipment);

        $overview = $this->read('overview');
        $this->assertOmits($overview, ['equipment_out']);
        $this->assertSame((string) $this->shift->id, $overview->json('selected_shift_id'));
        // The equipment exception went with the section it was derived from,
        // and the two that are not Equipment's stayed.
        $this->assertNotContains('equipment-out', collect($overview->json('exceptions'))->pluck('id')->all());
        $this->assertContains('coverage', collect($overview->json('exceptions'))->pluck('id')->all());

        $logistics = $this->read('logistics');
        $this->assertOmits($logistics, ['searchable_equipment', 'checkout_inventory']);
        $this->assertIsArray($logistics->json('searchable_shifts'));
        $this->assertArrayNotHasKey(
            'open_equipment',
            $logistics->json('staff_workspaces.'.(string) $this->member->id),
        );

        $this->assertOmits($this->read('operations'), ['equipment_out_count']);
        $this->read('planning')->assertOk();
    }

    public function test_the_four_reads_omit_event_geography_and_keep_answering(): void
    {
        $this->deactivate(ModuleKey::EventGeography);

        $overview = $this->read('overview');
        $this->assertOmits($overview, ['deployments']);
        $this->assertSame((string) $this->shift->id, $overview->json('selected_shift_id'));
        $this->assertNull($overview->json('assignments.0.current_deployment_id'));

        $operations = $this->read('operations');
        $this->assertOmits($operations, ['deployments']);
        // The Operations Center's own rows are Scheduling's and are still here,
        // so the screen renders the staff it can no longer place.
        $this->assertSame((string) $this->member->id, $operations->json('rows.0.staff_id'));

        $this->read('logistics')->assertOk();
        $this->read('planning')->assertOk();
    }

    /**
     * The module state of one organization decides nothing for another.
     */
    public function test_another_organizations_module_state_does_not_narrow_this_read(): void
    {
        $other = Organization::factory()->create(['name' => 'Somewhere Else']);
        OrganizationModule::query()->create([
            'organization_id' => $other->id,
            'module_key' => ModuleKey::Scheduling->value,
            'entitled' => true,
            'enabled' => false,
        ]);

        $this->read('overview')->assertJsonStructure(['shifts', 'assignments']);
    }

    /*
    |--------------------------------------------------------------------------
    | The dashboard (UI contract 13; MOD-019)
    |--------------------------------------------------------------------------
    */

    public function test_the_dashboard_omits_an_inactive_modules_widgets_and_still_answers(): void
    {
        $live = $this->dashboard();
        $ids = collect($live->json('widgets'))->pluck('id');

        $this->assertTrue($ids->contains('staff.upcoming_shifts'));
        $this->assertTrue($ids->contains('staff.assigned_departments'));

        $this->deactivate(ModuleKey::Scheduling);

        $degraded = $this->dashboard();
        $degradedIds = collect($degraded->json('widgets'))->pluck('id');

        $this->assertFalse($degradedIds->contains('staff.upcoming_shifts'));
        $this->assertFalse($degradedIds->contains('staff.current_shift'));
        // Core widgets are untouched, so the dashboard is a shorter dashboard
        // rather than an empty one.
        $this->assertTrue($degradedIds->contains('staff.assigned_departments'));
    }

    /**
     * The inventory says which module each widget belongs to, so a surface
     * reading it can tell "this Meridian defers it" from "your organization does
     * not run it" (MOD-013).
     */
    public function test_the_dashboard_inventory_names_the_module_behind_each_widget(): void
    {
        $inventory = collect($this->dashboard()->json('inventory'))->keyBy('id');

        $this->assertSame('scheduling', $inventory['staff.upcoming_shifts']['module']);
        $this->assertSame('documents', $inventory['staff.document_acknowledgments']['module']);
        $this->assertSame('briefing', $inventory['staff.briefing']['module']);
        $this->assertSame('ims', $inventory['ic.active_incidents']['module']);
        $this->assertNull($inventory['staff.assigned_departments']['module']);
    }

    /*
    |--------------------------------------------------------------------------
    | The Event Horizon (HORIZON-017; data/API 5.8A)
    |--------------------------------------------------------------------------
    */

    public function test_the_event_horizon_omits_kinds_an_inactive_module_owns(): void
    {
        $user = $this->memberUser();

        $live = $this->horizon($user);
        $this->assertTrue($live->json('presentable'));
        $this->assertEqualsCanonicalizing(
            ['document_acknowledgment', 'waiver', 'training', 'shift_signup'],
            collect($live->json('kinds'))->pluck('id')->all(),
        );

        $this->deactivate(ModuleKey::Scheduling);

        $degraded = $this->horizon($user);
        $kinds = collect($degraded->json('kinds'))->pluck('id');

        $this->assertFalse($kinds->contains('shift_signup'));
        $this->assertFalse($kinds->contains('coverage_gap'));
        $this->assertTrue($kinds->contains('document_acknowledgment'));
        // Something remains, so the surface is still presented.
        $this->assertTrue($degraded->json('presentable'));
    }

    /**
     * Where no kind remains the surface is not presented at all (HORIZON-017).
     *
     * An empty readiness list reads as "you are ready", which is exactly the
     * false all-clear the requirement exists to prevent. The read still answers
     * `200` with the same shape, because a client has to be able to tell "not
     * applicable" from "no such event".
     */
    public function test_an_event_horizon_with_no_available_kind_is_not_presented(): void
    {
        $user = $this->memberUser();

        $this->deactivate(ModuleKey::Scheduling);
        $this->deactivate(ModuleKey::Documents);
        $this->deactivate(ModuleKey::Qualifications);

        $response = $this->horizon($user);

        $this->assertSame([], $response->json('kinds'));
        $this->assertSame([], $response->json('items'));
        $this->assertFalse($response->json('presentable'));
        // Still an answer about this event rather than a refusal.
        $this->assertSame((string) $this->event->id, $response->json('context.event_id'));
    }

    private function read(string $surface): TestResponse
    {
        return $this->actingAsClient($this->lead)
            ->getJson("/api/events/{$this->event->id}/departments/{$this->department->id}/{$surface}")
            ->assertOk();
    }

    private function dashboard(): TestResponse
    {
        return $this->actingAsClient($this->lead)
            ->getJson("/api/events/{$this->event->id}/dashboard")
            ->assertOk();
    }

    private function horizon(User $user): TestResponse
    {
        return $this->actingAsClient($user)
            ->getJson("/api/events/{$this->event->id}/event-horizon")
            ->assertOk();
    }

    /**
     * A login speaking for the ordinary member, for the readiness surface — the
     * Event Horizon is a staff member's own list rather than a lead's read.
     */
    private function memberUser(): User
    {
        $user = User::factory()->create();
        $user->staffProfiles()->attach($this->member->id);

        return $user;
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertOmits(TestResponse $response, array $keys): void
    {
        $payload = $response->json();

        foreach ($keys as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $payload,
                "{$key} should be absent rather than empty when its module is inactive.",
            );
        }
    }

    private function deactivate(ModuleKey $module): void
    {
        OrganizationModule::query()->updateOrCreate(
            [
                'organization_id' => $this->organization->id,
                'module_key' => $module->value,
            ],
            [
                'entitled' => true,
                'enabled' => false,
            ],
        );
    }

    /**
     * Somebody holding every department operations role, so none of the four
     * reads is ever refused for standing and the only thing under test is
     * module state.
     */
    private function leadOf(Team $team): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create(['handle' => 'dana']);
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create(['status' => DepartmentMembership::STATUS_ACTIVE]);

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        foreach ([
            'department_lead',
            'department_logistics',
            'department_operations',
            'department_planning',
        ] as $roleCode) {
            TeamGrant::factory()->create([
                'team_id' => $team->id,
                'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
            ]);
        }

        return $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
