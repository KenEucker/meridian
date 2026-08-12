<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Domain\Modules\DomainNamespace;
use App\Domain\Modules\ModuleKey;
use App\Services\Modules\ActiveModuleResolver;
use App\Services\Node\NodeOrganizationBinding;
use Orchid\Screen\Actions\Menu;

/**
 * Which God Mode navigation entries a node advertises (MOD-021; technical spec
 * 15A.6, 22.2).
 *
 * The rule the module system follows is that **enforcement is by organization
 * and console visibility is by node binding**. This is the second half. It
 * decides what a sidebar advertises and nothing else: no screen is refused
 * here, no permission is consulted, and what any request may reach is decided
 * by the route gate against the organization that request names (M19.12).
 *
 * On central, everything is advertised. Central serves many organizations at
 * once, so a module one of them has switched off is a module another is running
 * — hiding it would take the entry away from the organization that uses it, and
 * would remove the surface an operator turns it back on from. MOD-021 makes
 * that the central node's rule rather than a preference.
 *
 * On a node bound to one organization ({@see NodeOrganizationBinding}), that
 * organization's inactive modules are hidden, because there the console has
 * exactly one organization's context and the clutter is real. The Organization
 * Modules screens are exempt by construction rather than by exception: they
 * belong to no module, so no module state can take them away, which is what
 * keeps activation reachable from the node where somebody noticed the module
 * was missing.
 *
 * **Ownership is declared once, here.** {@see self::NAVIGATION} names the
 * domain namespace each menu entry administers, or `null` where it is core, and
 * the module follows from {@see DomainNamespace} rather than being restated —
 * so a namespace that moves between modules in technical spec 15A.2 moves this
 * console's navigation with it. Entries are narrowed centrally in
 * {@see self::narrow()} rather than each menu item carrying its own visibility
 * call, so declaring the namespace is all a new module-owned screen has to do.
 * `ConsoleModuleNavigationTest` asserts every entry in the sidebar appears in
 * the table, so a screen added without a decision fails rather than defaulting
 * quietly to visible.
 */
class ConsoleModuleVisibility
{
    /**
     * Every God Mode navigation entry, and the domain namespace it
     * administers.
     *
     * `null` is core (MOD-004): the entry has no owning module and no module
     * state can hide it. Most of the console is core, which is the point — a
     * repair surface for nodes, sync, audit, and people does not go away
     * because an organization does not run Scheduling.
     *
     * @var array<string, DomainNamespace|null>
     */
    public const NAVIGATION = [
        'platform.main' => null,

        'platform.systems.users' => null,
        'platform.systems.roles' => null,

        'platform.organizations' => null,
        'platform.organization-inquiries' => null,
        'platform.events' => null,
        'platform.applications' => null,
        'platform.departments' => null,
        'platform.teams' => null,
        'platform.shifts' => DomainNamespace::Shifts,
        'platform.staff' => null,
        'platform.equipment' => DomainNamespace::Equipment,
        'platform.incident-types' => DomainNamespace::Incidents,

        // Credit policies are core. Credits are earned from recorded hours and
        // check-in does not require a shift (requirements 5.8), so an
        // organization with Scheduling inactive still calculates them.
        'platform.credit-policies' => null,

        'platform.policy-documents' => DomainNamespace::PolicyDocuments,
        'platform.procedure-documents' => DomainNamespace::ProcedureDocuments,
        'platform.document-fragments' => DomainNamespace::DocumentFragments,
        'platform.document-acknowledgments' => DomainNamespace::DocumentAcknowledgments,

        'platform.permissions' => null,

        // Never hidden, and MOD-021 is why: it is the surface an operator
        // activates a module from, so a node that hid it while a module was off
        // would have removed the only way back.
        'platform.organization-modules' => null,

        'platform.audit' => null,
        'platform.audit.settings' => null,

        'platform.field-reports' => DomainNamespace::FieldReports,
        'platform.incidents' => DomainNamespace::Incidents,

        'platform.imports.users' => null,
        'platform.imports.teams' => null,
        'platform.imports.shifts' => DomainNamespace::Shifts,
        'platform.imports.assignments' => DomainNamespace::ShiftSignupsAndRequirements,

        'platform.sync-conflicts' => null,
        'platform.node.config' => null,
        'platform.api-tokens' => null,
        'platform.shared-workstations' => null,
        'platform.shared-workstation-login-codes' => null,
        'platform.system.configuration' => null,
        'platform.system.diagnostics' => null,
        'platform.system.node-health' => null,

        'platform.documentation' => null,
        'platform.changelog' => null,
    ];

    public function __construct(
        private readonly NodeOrganizationBinding $binding,
        private readonly ActiveModuleResolver $modules,
    ) {}

    /**
     * Hide the menu entries this node does not advertise.
     *
     * Menu items are matched by the address they point at, because that is what
     * a built item carries. An item whose address belongs to no declared entry
     * is left alone — the completeness test is what turns that into a failure,
     * rather than a sidebar quietly losing something at render time.
     *
     * @param  list<Menu>  $menu
     * @return list<Menu>
     */
    public function narrow(array $menu): array
    {
        $hidden = $this->hiddenAddresses();

        if ($hidden === []) {
            return $menu;
        }

        foreach ($menu as $item) {
            if (in_array((string) $item->get('href'), $hidden, true)) {
                $item->canSee(false);
            }
        }

        return $menu;
    }

    /**
     * Whether this node advertises the entries owned by this module.
     */
    public function shows(?ModuleKey $module): bool
    {
        if ($module === null) {
            return true;
        }

        $organizationId = $this->binding->organizationId();

        // Central, or a node nobody has bound: MOD-021's every-module rule.
        if ($organizationId === null) {
            return true;
        }

        return $this->modules->isActive($organizationId, $module);
    }

    /**
     * The module owning a navigation entry, or null where it is core or
     * undeclared. Undeclared answers the same as core for the reason
     * {@see DomainNamespace::ownerOf()} gives: a caller asking this wants to
     * know whether to hide, and a missing declaration must not hide anything.
     */
    public function ownerOf(string $routeName): ?ModuleKey
    {
        return (self::NAVIGATION[$routeName] ?? null)?->module();
    }

    /**
     * @return list<string>
     */
    private function hiddenAddresses(): array
    {
        if (! $this->binding->isBound()) {
            return [];
        }

        $hidden = [];

        foreach (array_keys(self::NAVIGATION) as $routeName) {
            $module = $this->ownerOf($routeName);

            if ($module !== null && ! $this->shows($module)) {
                $hidden[] = route($routeName);
            }
        }

        return $hidden;
    }
}
