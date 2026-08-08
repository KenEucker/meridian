<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\SyncConflict;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Console\AttentionItem;
use App\Services\Console\ConfigurationReadinessCheck;
use App\Services\Console\ConsoleAttention;
use App\Services\Console\ConsoleOrientation;
use App\Services\Console\OrganizationalDataGapCheck;
use App\Services\Console\SyncConflictAttentionCheck;
use App\Services\Offline\OfflineReadSetProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The God Mode landing screen: Meridian orientation content and the read-only
 * attention list (GOD-001 through GOD-011; technical spec 22.5).
 */
class ConsoleLandingScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The landing screen reports the event-mode offline read set probe.
        // Binding a servable double keeps these assertions about the landing
        // screen rather than about how the test harness loads routes.
        $this->markOfflineReadSetServable();
    }

    public function test_the_landing_screen_replaces_the_framework_welcome_content(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('Meridian God Mode');
        $response->assertSee('Repair and break-glass tooling for a Meridian deployment.');
        $response->assertDontSee('Welcome to your Orchid application');

        // The vendor welcome partial's own copy is gone with it. The framework
        // footer is left to M15C.5 (GOD-032, GOD-033).
        $response->assertDontSee('https://orchid.software/en/docs/quickstart');
    }

    public function test_the_orientation_summary_covers_meridian_end_to_end(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.main'));

        $response->assertOk();

        foreach ([
            'Organizations and departments',
            'Events and the active event window',
            'Staff, teams, and roles',
            'Shifts and eligibility',
            'Operations, attendance, and hours',
            'Policies, procedures, and acknowledgments',
            'Field reports and incidents',
            'Nodes, sync, and authority',
        ] as $heading) {
            $response->assertSee($heading);
        }
    }

    public function test_the_orientation_summary_states_the_god_mode_boundary(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('God Mode is repair and break-glass tooling.');
        $response->assertSee('belong in Meridian Admin', false);
    }

    public function test_a_healthy_deployment_renders_an_explicit_all_clear(): void
    {
        $this->healthyDeployment();

        $attention = app(ConsoleAttention::class)->describe();

        $this->assertTrue($attention['all_clear'], json_encode($attention['groups']));
        $this->assertSame(0, $attention['count']);
        $this->assertSame([], $attention['groups']);

        $response = $this->actingAs($this->godModeUser())->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('Nothing needs attention.');
    }

    public function test_an_install_without_a_node_reports_one_configuration_item(): void
    {
        $items = app(ConfigurationReadinessCheck::class)->items();

        $this->assertSame(
            [ConfigurationReadinessCheck::NO_NODE],
            array_map(fn ($item) => $item->key, $items),
        );
        $this->assertSame(route('platform.node.config'), $items[0]->resolveUrl());
    }

    public function test_an_unpaired_onsite_node_reports_pairing_and_links_to_node_configuration(): void
    {
        $this->localNode(Node::ROLE_ONSITE);

        $keys = $this->itemKeys(app(ConfigurationReadinessCheck::class)->items());

        $this->assertContains(ConfigurationReadinessCheck::PAIRING_INCOMPLETE, $keys);

        $response = $this->actingAs($this->godModeUser())->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('Deployment and configuration readiness');
        $response->assertSee('Central pairing is incomplete');
    }

    public function test_a_node_without_signing_keys_reports_missing_secrets_without_showing_any(): void
    {
        $node = $this->localNode(Node::ROLE_CENTRAL);
        $node->forceFill(['public_key' => ''])->save();
        config()->set('meridian.node.private_key', null);

        $keys = $this->itemKeys(app(ConfigurationReadinessCheck::class)->items());

        $this->assertContains(ConfigurationReadinessCheck::NODE_KEYS_MISSING, $keys);
    }

    public function test_event_mode_https_and_read_set_failures_are_reported_as_configuration_items(): void
    {
        $this->localNode(Node::ROLE_CENTRAL);
        config()->set('app.url', 'http://meridian.test');
        $this->markOfflineReadSetServable(false);

        $keys = $this->itemKeys(app(ConfigurationReadinessCheck::class)->items());

        $this->assertContains(ConfigurationReadinessCheck::SECURE_CONNECTION, $keys);
        $this->assertContains(ConfigurationReadinessCheck::OFFLINE_READ_SET, $keys);
    }

    public function test_development_mode_does_not_report_secure_connection_or_the_read_set(): void
    {
        $this->localNode(Node::ROLE_DEVELOPMENT);
        config()->set('app.url', 'http://localhost');
        $this->markOfflineReadSetServable(false);

        $keys = $this->itemKeys(app(ConfigurationReadinessCheck::class)->items());

        $this->assertNotContains(ConfigurationReadinessCheck::SECURE_CONNECTION, $keys);
        $this->assertNotContains(ConfigurationReadinessCheck::OFFLINE_READ_SET, $keys);
    }

    public function test_an_organization_without_departments_reports_only_that_gap(): void
    {
        Organization::factory()->create(['name' => 'Northwood Collective']);

        $items = app(OrganizationalDataGapCheck::class)->items();

        $this->assertSame(
            [OrganizationalDataGapCheck::NO_DEPARTMENTS],
            $this->itemKeys($items),
        );
        $this->assertStringContainsString('Northwood Collective', $items[0]->label);
    }

    public function test_missing_organizers_ic_and_lead_organizer_are_each_reported(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        Department::factory()->for($organization)->create(['name' => 'Rangers']);

        $keys = $this->itemKeys(app(OrganizationalDataGapCheck::class)->items());

        $this->assertContains(OrganizationalDataGapCheck::NO_ORGANIZERS_DEPARTMENT, $keys);
        $this->assertContains(OrganizationalDataGapCheck::NO_IC_DEPARTMENT, $keys);
        $this->assertContains(OrganizationalDataGapCheck::NO_ACTIVE_LEAD_ORGANIZER, $keys);
    }

    public function test_an_archived_department_does_not_satisfy_a_department_designation(): void
    {
        $organization = Organization::factory()->create();
        $active = Department::factory()->for($organization)->create();
        $archived = Department::factory()->for($organization)->create(['archived_at' => now()]);

        $organization->forceFill([
            'organizers_department_id' => $archived->id,
            'default_ic_department_id' => $active->id,
        ])->save();

        $keys = $this->itemKeys(app(OrganizationalDataGapCheck::class)->items());

        $this->assertContains(OrganizationalDataGapCheck::NO_ORGANIZERS_DEPARTMENT, $keys);
        $this->assertNotContains(OrganizationalDataGapCheck::NO_IC_DEPARTMENT, $keys);
    }

    public function test_an_event_without_departments_or_a_resolvable_ic_department_is_reported(): void
    {
        $organization = Organization::factory()->create();
        Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Emberfall 2026']);

        $keys = $this->itemKeys(app(OrganizationalDataGapCheck::class)->items());

        $this->assertContains(OrganizationalDataGapCheck::EVENT_NO_DEPARTMENTS, $keys);
        $this->assertContains(OrganizationalDataGapCheck::EVENT_NO_IC_DEPARTMENT, $keys);
        $this->assertNotNull($event->id);
    }

    public function test_an_organization_default_ic_department_resolves_for_an_event_without_an_override(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $organization->forceFill(['default_ic_department_id' => $department->id])->save();
        Event::factory()->for($organization)->create();

        $keys = $this->itemKeys(app(OrganizationalDataGapCheck::class)->items());

        $this->assertNotContains(OrganizationalDataGapCheck::EVENT_NO_IC_DEPARTMENT, $keys);
    }

    public function test_unresolved_sync_conflicts_are_counted_and_link_to_the_queue(): void
    {
        SyncConflict::factory()->count(2)->create(['status' => SyncConflict::STATUS_OPEN]);
        SyncConflict::factory()->create(['status' => SyncConflict::STATUS_RESOLVED]);

        $items = app(SyncConflictAttentionCheck::class)->items();

        $this->assertCount(1, $items);
        $this->assertSame(SyncConflictAttentionCheck::UNRESOLVED, $items[0]->key);
        $this->assertStringContainsString('2', $items[0]->label);
        $this->assertSame(route('platform.sync-conflicts'), $items[0]->resolveUrl());

        $response = $this->actingAs($this->godModeUser())->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('Unresolved sync conflicts');
        $response->assertDontSee('Nothing needs attention.');
    }

    /**
     * GOD-010: attention items reflect current state and must not mutate as a
     * side effect of being displayed.
     */
    public function test_viewing_the_landing_screen_performs_no_writes(): void
    {
        $organization = Organization::factory()->create();
        Department::factory()->for($organization)->create();
        Event::factory()->for($organization)->create();
        SyncConflict::factory()->create(['status' => SyncConflict::STATUS_OPEN]);
        $this->localNode(Node::ROLE_ONSITE);
        $user = $this->godModeUser();

        $writes = [];

        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $response = $this->actingAs($user)->get(route('platform.main'));

        $response->assertOk();
        $this->assertSame([], $writes, 'The landing screen must not write while reporting state.');
    }

    public function test_every_attention_item_carries_a_resolvable_link(): void
    {
        $organization = Organization::factory()->create();
        Department::factory()->for($organization)->create();
        Event::factory()->for($organization)->create();
        SyncConflict::factory()->create(['status' => SyncConflict::STATUS_OPEN]);

        foreach (app(ConsoleAttention::class)->groups() as $group) {
            foreach ($group->items as $item) {
                $this->assertNotSame('', $item->resolveUrl(), $item->key.' has no resolve link.');
            }
        }
    }

    public function test_the_orientation_service_names_the_boundary_verbatim(): void
    {
        $this->assertSame(ConsoleOrientation::BOUNDARY, app(ConsoleOrientation::class)->boundary());
    }

    /**
     * A deployment with nothing outstanding: a configured central node with
     * keys, an organization with its designations and an active Lead Organizer,
     * an event with a department, and no open conflicts.
     */
    private function healthyDeployment(): void
    {
        $this->localNode(Node::ROLE_CENTRAL);
        config()->set('app.url', 'https://meridian.test');

        $organization = Organization::factory()->create();
        $organizers = Department::factory()->for($organization)->create(['name' => 'Organizers']);
        $incidentCommand = Department::factory()->for($organization)->create(['name' => 'Incident Command']);

        $organization->forceFill([
            'organizers_department_id' => $organizers->id,
            'default_ic_department_id' => $incidentCommand->id,
        ])->save();

        $team = Team::factory()->for($organizers, 'department')->create();
        $staff = Staff::factory()->create();

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        $departmentMembership = DepartmentMembership::factory()->create([
            'department_id' => $organizers->id,
            'staff_id' => $staff->id,
        ]);

        // Built directly rather than through the factory: TeamMembershipFactory
        // eagerly creates its own department membership, which would seed a
        // second organization into a fixture that is asserting the whole
        // deployment is clean.
        TeamMembership::query()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => 'member',
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', PermissionCatalog::ROLE_LEAD_ORGANIZER)
                ->firstOrFail()->id,
        ]);

        $event = Event::factory()->for($organization)->create();
        $event->departmentAssignments()->create(['department_id' => $incidentCommand->id]);
    }

    /**
     * The effective node role is resolved through node config, not through the
     * `nodes` row, so the file-config value is set alongside the record.
     */
    private function localNode(string $role): Node
    {
        config()->set('meridian.node.private_key', 'test-private-key');
        config()->set('meridian.node.name', 'meridian-test');
        config()->set('meridian.node.role', $role);

        return Node::factory()->create([
            'node_name' => 'meridian-test',
            'node_role' => $role,
            'is_local' => true,
            'public_key' => 'test-public-key',
        ]);
    }

    private function markOfflineReadSetServable(bool $available = true): void
    {
        $this->instance(OfflineReadSetProbe::class, new class($available) extends OfflineReadSetProbe
        {
            public function __construct(private readonly bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }
        });
    }

    /**
     * @param  list<AttentionItem>  $items
     * @return list<string>
     */
    private function itemKeys(array $items): array
    {
        return array_map(static fn ($item): string => $item->key, $items);
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);
    }
}
