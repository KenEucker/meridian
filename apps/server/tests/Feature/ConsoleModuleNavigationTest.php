<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Models\Node;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\User;
use App\Orchid\PlatformProvider;
use App\Services\Console\ConsoleModuleVisibility;
use App\Services\Node\NodeOrganizationBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Orchid\Screen\Actions\Menu;
use Tests\TestCase;

/**
 * God Mode console navigation by node binding (M19.14; MOD-021; technical spec
 * 7.3, 15A.6).
 *
 * One rule, driven from both sides: **enforcement is by organization, console
 * visibility is by node binding**. A node serving many organizations advertises
 * every module-owned screen whatever any one organization has switched off,
 * because the entry belongs to all of them and is where an operator turns a
 * module back on. A node bound to one organization advertises what that
 * organization runs.
 *
 * Nothing here is enforcement. No screen is refused by module state, on either
 * kind of node — what a request may reach is decided against the organization
 * the request names (M19.12), and the last test in this file is the one that
 * matters most: on the node where a module was hidden, an operator can still
 * turn it on.
 */
class ConsoleModuleNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every module-owned entry in the sidebar, by the module that owns it.
     *
     * @var array<string, list<string>>
     */
    private const MODULE_OWNED = [
        ModuleKey::Scheduling->value => [
            'platform.shifts',
            'platform.imports.shifts',
            'platform.imports.assignments',
        ],
        ModuleKey::IncidentManagement->value => [
            'platform.incident-types',
            'platform.field-reports',
            'platform.incidents',
        ],
        ModuleKey::Documents->value => [
            'platform.policy-documents',
            'platform.procedure-documents',
            'platform.document-fragments',
            'platform.document-acknowledgments',
        ],
        ModuleKey::Equipment->value => [
            'platform.equipment',
        ],
    ];

    /**
     * Console entries no module owns, which no node may hide.
     *
     * @var list<string>
     */
    private const CORE = [
        'platform.organizations',
        'platform.events',
        'platform.departments',
        'platform.teams',
        'platform.staff',
        'platform.credit-policies',
        'platform.audit',
        'platform.sync-conflicts',
        'platform.node.config',
    ];

    /**
     * MOD-021. The central node serves many organizations at once, so an
     * organization with everything switched off must not take a single entry
     * out of a console that other organizations are administered from.
     */
    public function test_an_unbound_node_advertises_every_module_owned_screen(): void
    {
        $organization = Organization::factory()->create();
        $this->deactivateAll($organization);

        $this->assertNull(app(NodeOrganizationBinding::class)->organizationId());

        $response = $this->actingAs($this->consoleOperator())->get(route('platform.main'));

        $response->assertOk();

        foreach (self::MODULE_OWNED as $routes) {
            foreach ($routes as $routeName) {
                $response->assertSee(route($routeName), escape: false);
            }
        }
    }

    /**
     * Technical spec 15A.6: on a node with exactly one organization's context,
     * the clutter of screens that organization does not run is real.
     */
    public function test_a_bound_node_hides_the_bound_organizations_inactive_modules(): void
    {
        $organization = Organization::factory()->create();

        $this->deactivate($organization, ModuleKey::Scheduling);
        $this->deactivate($organization, ModuleKey::Documents);

        $this->bindNodeTo($organization);

        $response = $this->actingAs($this->consoleOperator())->get(route('platform.main'));

        $response->assertOk();

        foreach (self::MODULE_OWNED[ModuleKey::Scheduling->value] as $routeName) {
            $response->assertDontSee(route($routeName), escape: false);
        }

        foreach (self::MODULE_OWNED[ModuleKey::Documents->value] as $routeName) {
            $response->assertDontSee(route($routeName), escape: false);
        }

        // The section heading lives on the first item of its group, so the
        // Documents group takes "Policies & Procedures" with it.
        $response->assertDontSee('Policies &amp; Procedures', escape: false);

        // What the organization still runs is still advertised.
        foreach (self::MODULE_OWNED[ModuleKey::IncidentManagement->value] as $routeName) {
            $response->assertSee(route($routeName), escape: false);
        }

        $response->assertSee(route('platform.equipment'), escape: false);

        foreach (self::CORE as $routeName) {
            $response->assertSee(route($routeName), escape: false);
        }
    }

    /**
     * MOD-021's second sentence. Module state administration stays reachable on
     * a bound node, because the screen belongs to no module and there is
     * nothing else to turn a module back on from.
     */
    public function test_a_bound_node_keeps_the_organization_modules_screen_advertised(): void
    {
        $organization = Organization::factory()->create();
        $this->deactivateAll($organization);
        $this->bindNodeTo($organization);

        $this->actingAs($this->consoleOperator())
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee(route('platform.organization-modules'), escape: false);
    }

    /**
     * The narrowing is the *bound* organization's. Another organization's
     * module state has no say on this node's navigation, which is the same
     * boundary MOD-021 draws on central, seen from the other side.
     */
    public function test_another_organizations_module_state_does_not_narrow_a_bound_node(): void
    {
        $bound = Organization::factory()->create();
        $other = Organization::factory()->create();

        $this->deactivateAll($other);
        $this->bindNodeTo($bound);

        $response = $this->actingAs($this->consoleOperator())->get(route('platform.main'));

        $response->assertOk();

        foreach (self::MODULE_OWNED as $routes) {
            foreach ($routes as $routeName) {
                $response->assertSee(route($routeName), escape: false);
            }
        }
    }

    /**
     * The requirement the whole task rests on: hiding a module must never be
     * the reason nobody can bring it back. A standalone node bound to one
     * organization is its own central (M19.13's authority rule), so the
     * operator standing in front of it both sees the module gone from the
     * sidebar and turns it on from the same console.
     */
    public function test_an_operator_on_a_bound_node_can_still_activate_a_hidden_module(): void
    {
        $organization = Organization::factory()->create();

        $this->deactivate($organization, ModuleKey::Equipment);
        $this->bindNodeTo($organization, Node::ROLE_STANDALONE);

        $operator = $this->consoleOperator();

        $this->actingAs($operator)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertDontSee(route('platform.equipment'), escape: false);

        // The screen that fixes it is reachable, and still presents the module
        // that is missing from the sidebar.
        $this->actingAs($operator)
            ->get(route('platform.organization-modules.edit', $organization))
            ->assertOk()
            ->assertSee(ModuleKey::Equipment->label())
            ->assertSee('modules[equipment][entitled]', escape: false);

        $this->actingAs($operator)
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitled(),
                'reason' => 'The camp is running its own gear this year after all.',
            ])
            ->assertRedirect(route('platform.organization-modules.edit', $organization->id));

        $this->assertTrue((bool) OrganizationModule::query()
            ->where('organization_id', $organization->getKey())
            ->where('module_key', ModuleKey::Equipment->value)
            ->sole()
            ->entitled);

        // And the entry is back, on the node it went missing from.
        $this->actingAs($operator)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee(route('platform.equipment'), escape: false);
    }

    /**
     * The binding is node configuration (technical spec 7.3): the node record
     * is the node's own copy, and file config is the boot default a prepared
     * deployment ships.
     */
    public function test_the_node_record_binding_wins_over_the_file_default(): void
    {
        $shipped = Organization::factory()->create();
        $actual = Organization::factory()->create();

        config()->set('meridian.node.organization_id', (string) $shipped->getKey());

        $this->assertSame(
            (string) $shipped->getKey(),
            app(NodeOrganizationBinding::class)->organizationId(),
        );

        Node::factory()->create([
            'is_local' => true,
            'organization_id' => $actual->getKey(),
        ]);

        $this->assertSame(
            (string) $actual->getKey(),
            app(NodeOrganizationBinding::class)->organizationId(),
        );
    }

    /**
     * A stale id — an organization removed, a config copied between installs —
     * is not a binding. The safe direction is a console that shows too much,
     * not one that hides the module somebody came to turn on.
     */
    public function test_an_id_naming_no_organization_is_not_a_binding(): void
    {
        config()->set('meridian.node.organization_id', '0195b0f1-0000-7000-8000-000000000000');

        $binding = app(NodeOrganizationBinding::class);

        $this->assertFalse($binding->isBound());
        $this->assertNull($binding->organizationId());
    }

    /**
     * Ownership is declared once and derived from technical spec 15A.2's
     * namespace ownership, so a namespace that moves between modules moves the
     * console with it rather than needing a second edit here.
     */
    public function test_navigation_ownership_follows_the_domain_namespace_declaration(): void
    {
        $visibility = app(ConsoleModuleVisibility::class);

        foreach (self::MODULE_OWNED as $moduleKey => $routes) {
            foreach ($routes as $routeName) {
                $this->assertSame(
                    ModuleKey::from($moduleKey),
                    $visibility->ownerOf($routeName),
                    "{$routeName} should be owned by {$moduleKey}",
                );
            }
        }

        foreach (self::CORE as $routeName) {
            $this->assertNull($visibility->ownerOf($routeName), "{$routeName} is core");
        }

        // Undeclared answers the same as core, so a console entry nobody
        // thought about stays reachable rather than disappearing.
        $this->assertNull($visibility->ownerOf('platform.not-a-screen'));
    }

    /**
     * Every entry in the sidebar has decided whether a module owns it.
     *
     * This is the completeness guard, and it is what makes central narrowing
     * safe to do in one place: a screen added to the console without a line in
     * the ownership table fails here rather than defaulting quietly to visible
     * on every node forever.
     */
    public function test_every_navigation_entry_declares_what_it_administers(): void
    {
        $declared = [];

        foreach (array_keys(ConsoleModuleVisibility::NAVIGATION) as $routeName) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($routeName),
                "{$routeName} is declared in the console ownership table but is not a registered route",
            );

            $declared[route($routeName)] = $routeName;
        }

        $addresses = array_map(
            static fn (Menu $item): string => (string) $item->get('href'),
            (new PlatformProvider($this->app))->menu(),
        );

        $advertised = [];

        foreach ($addresses as $address) {
            $this->assertArrayHasKey(
                $address,
                $declared,
                "The console menu entry at {$address} is not in the module ownership table",
            );

            $advertised[] = $declared[$address];
        }

        $this->assertSame(
            [],
            array_values(array_diff(array_keys(ConsoleModuleVisibility::NAVIGATION), $advertised)),
            'The ownership table names console entries the menu no longer has',
        );
    }

    private function bindNodeTo(Organization $organization, string $role = Node::ROLE_ONSITE): Node
    {
        return Node::factory()->create([
            'node_role' => $role,
            'is_local' => true,
            'organization_id' => $organization->getKey(),
        ]);
    }

    private function deactivate(Organization $organization, ModuleKey $module): void
    {
        OrganizationModule::factory()->for($organization)->create([
            'module_key' => $module->value,
            'entitled' => false,
            'enabled' => true,
        ]);
    }

    private function deactivateAll(Organization $organization): void
    {
        foreach (ModuleKey::cases() as $module) {
            $this->deactivate($organization, $module);
        }
    }

    /**
     * @return array<string, array{entitled: string}>
     */
    private function allEntitled(): array
    {
        $modules = [];

        foreach (ModuleKey::cases() as $module) {
            $modules[$module->value] = ['entitled' => '1'];
        }

        return $modules;
    }

    private function consoleOperator(): User
    {
        $permissions = ['platform.index' => true];

        foreach ([
            'platform.systems.users',
            'platform.systems.roles',
            'platform.organizations',
            'platform.organization-inquiries',
            'platform.events',
            'platform.applications',
            'platform.departments',
            'platform.teams',
            'platform.shifts',
            'platform.staff',
            'platform.equipment',
            'platform.incident-types',
            'platform.credit-policies',
            'platform.policy-documents',
            'platform.procedure-documents',
            'platform.document-fragments',
            'platform.document-acknowledgments',
            'platform.permissions',
            'platform.organization-modules',
            'platform.audit',
            'platform.audit.settings',
            'platform.field-reports',
            'platform.incidents',
            'platform.imports',
            'platform.sync-conflicts',
            'platform.node.config',
            'platform.api-tokens',
            'platform.shared-workstations',
            'platform.shared-workstation-login-codes',
            'platform.system.configuration',
            'platform.system.diagnostics',
            'platform.documentation',
            'platform.changelog',
        ] as $permission) {
            $permissions[$permission] = true;
        }

        return User::factory()->create(['permissions' => $permissions]);
    }
}
