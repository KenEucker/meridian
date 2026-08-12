<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\AuditActionCatalog;
use App\Domain\Modules\ModuleKey;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\User;
use App\Services\Modules\ActiveModuleResolver;
use App\Services\Modules\ModuleStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The God Mode organization modules screen (M19.13; MOD-006, MOD-011, MOD-021;
 * technical spec 15A.6, 22.2).
 *
 * Entitlement is the platform's half of module state. These assert the three
 * things the requirements make non-negotiable about the surface that sets it:
 * the central console presents every module for every organization whatever
 * their state, every transition is audited with its previous and new state, and
 * the organization's own enablement choice survives a revoke untouched.
 */
class ConsoleOrganizationModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MOD-021. An organization with everything switched off is exactly the one
     * somebody has opened this screen to fix, so it is the case that proves the
     * console does not filter by state.
     */
    public function test_the_central_console_lists_every_module_for_an_organization_with_them_all_disabled(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);

        foreach (ModuleKey::cases() as $module) {
            OrganizationModule::factory()->for($organization)->create([
                'module_key' => $module->value,
                'entitled' => false,
                'enabled' => false,
            ]);
        }

        $response = $this->actingAs($this->moduleOperator())
            ->get(route('platform.organization-modules.edit', $organization));

        $response->assertOk();
        $response->assertSee('Northwood Collective');

        foreach (ModuleKey::cases() as $module) {
            $response->assertSee($module->label());
            $response->assertSee('modules['.$module->value.'][entitled]', escape: false);
        }

        // Not merely listed: still settable, which is the point of MOD-021.
        $response->assertSee('Save entitlement');
    }

    public function test_the_list_screen_shows_every_organization_and_what_it_is_missing(): void
    {
        $running = Organization::factory()->create(['name' => 'Northwood Collective']);

        $narrowed = Organization::factory()->create(['name' => 'Harbor Light Crew']);
        OrganizationModule::factory()->for($narrowed)->create([
            'module_key' => ModuleKey::Scheduling->value,
            'entitled' => false,
            'enabled' => true,
        ]);
        OrganizationModule::factory()->for($narrowed)->create([
            'module_key' => ModuleKey::Documents->value,
            'entitled' => true,
            'enabled' => false,
        ]);

        $response = $this->actingAs($this->moduleOperator())
            ->get(route('platform.organization-modules'));

        $response->assertOk();
        $response->assertSee('Northwood Collective');
        $response->assertSee('Harbor Light Crew');

        // An organization with no rows at all is running everything (MOD-009).
        $response->assertSee('8 of 8');
        $this->assertSame(8, count(ModuleKey::cases()));

        // The two ways a module is inactive, reported separately, because only
        // one of them is this screen's to fix.
        $response->assertSee('6 of 8');
        $response->assertSee(ModuleKey::Scheduling->label());
        $response->assertSee(ModuleKey::Documents->label());

        $this->assertNotNull($running->fresh());
    }

    public function test_both_screens_require_the_module_permission(): void
    {
        $organization = Organization::factory()->create();

        // Holding every other God Mode grant is not holding this one:
        // entitlement decides whether a capability exists for an organization
        // at all, which no other console permission implies.
        $operator = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.organizations' => true,
                'platform.permissions' => true,
                'platform.audit' => true,
            ],
        ]);

        $this->actingAs($operator)->get(route('platform.organization-modules'))->assertForbidden();
        $this->actingAs($operator)
            ->get(route('platform.organization-modules.edit', $organization))
            ->assertForbidden();
        $this->actingAs($operator)
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => [ModuleKey::Scheduling->value => ['entitled' => '0']],
            ])
            ->assertForbidden();

        $this->assertSame(0, OrganizationModule::query()->count());
    }

    /**
     * MOD-011: the module, the previous and new state, the actor, and the
     * reason where one is supplied.
     */
    public function test_revoking_entitlement_is_audited_with_previous_and_new_state(): void
    {
        $organization = Organization::factory()->create();
        $operator = $this->moduleOperator();

        $this->actingAs($operator)
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitledExcept(ModuleKey::Scheduling),
                'reason' => 'Northwood runs its rosters elsewhere.',
            ])
            ->assertRedirect(route('platform.organization-modules.edit', $organization->id));

        $row = OrganizationModule::query()
            ->where('organization_id', $organization->id)
            ->where('module_key', ModuleKey::Scheduling->value)
            ->sole();

        $this->assertFalse((bool) $row->entitled);
        $this->assertSame((string) $operator->getKey(), (string) $row->entitlement_changed_by_user_id);
        $this->assertNotNull($row->entitlement_changed_at);

        $audit = AuditEvent::query()
            ->where('action', ModuleStateService::AUDIT_ENTITLEMENT_CHANGED)
            ->sole();

        $this->assertSame((string) $organization->getKey(), (string) $audit->organization_id);
        $this->assertSame((string) $operator->getKey(), (string) $audit->actor_user_id);
        $this->assertSame((string) $row->getKey(), (string) $audit->entity_id);
        $this->assertSame('Northwood runs its rosters elsewhere.', $audit->reason);

        $this->assertSame([
            'module_key' => ModuleKey::Scheduling->value,
            'entitled' => true,
            'enabled' => true,
            'active' => true,
        ], $audit->before_json);

        $this->assertSame([
            'module_key' => ModuleKey::Scheduling->value,
            'entitled' => false,
            'enabled' => true,
            'active' => false,
        ], $audit->after_json);
    }

    /**
     * MOD-011 makes the audit unconditional, so an organization's verbosity
     * configuration must not be able to switch it off.
     */
    public function test_the_entitlement_audit_is_in_the_required_floor(): void
    {
        $this->assertTrue(AuditActionCatalog::isRequired(ModuleStateService::AUDIT_ENTITLEMENT_CHANGED));

        $organization = Organization::factory()->create([
            'audit_action_overrides' => [ModuleStateService::AUDIT_ENTITLEMENT_CHANGED => false],
        ]);

        $this->actingAs($this->moduleOperator())
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitledExcept(ModuleKey::Insights),
            ]);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', ModuleStateService::AUDIT_ENTITLEMENT_CHANGED)
            ->count());
    }

    public function test_a_reason_is_optional_and_absent_rather_than_empty_when_not_given(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->moduleOperator())
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitledExcept(ModuleKey::Equipment),
                'reason' => '   ',
            ]);

        $audit = AuditEvent::query()
            ->where('action', ModuleStateService::AUDIT_ENTITLEMENT_CHANGED)
            ->sole();

        $this->assertNull($audit->reason);
    }

    /**
     * MOD-007. The organization's own choice is not this screen's to touch, in
     * either direction.
     */
    public function test_revoking_and_restoring_entitlement_leaves_the_organizations_enabled_choice_alone(): void
    {
        $organization = Organization::factory()->create();

        OrganizationModule::factory()->for($organization)->create([
            'module_key' => ModuleKey::Documents->value,
            'entitled' => true,
            'enabled' => false,
        ]);

        $operator = $this->moduleOperator();

        $this->actingAs($operator)
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitledExcept(ModuleKey::Documents),
            ]);

        $revoked = $this->row($organization, ModuleKey::Documents);
        $this->assertFalse((bool) $revoked->entitled);
        $this->assertFalse((bool) $revoked->enabled);

        $this->actingAs($operator)
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitled(),
            ]);

        $restored = $this->row($organization, ModuleKey::Documents);
        $this->assertTrue((bool) $restored->entitled);
        $this->assertFalse((bool) $restored->enabled, 'Restoring entitlement must not re-enable a module the organization turned off.');
    }

    /**
     * A revoke against a module with no row has to keep the organization
     * running it once entitlement returns, because the un-stated default is the
     * choice it was running under (MOD-007, MOD-009).
     */
    public function test_a_revoke_of_an_unstated_module_records_the_default_enabled_choice(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->moduleOperator())
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitledExcept(ModuleKey::EventGeography),
            ]);

        $row = $this->row($organization, ModuleKey::EventGeography);

        $this->assertFalse((bool) $row->entitled);
        $this->assertTrue((bool) $row->enabled);
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->moduleOperator())
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitled(),
                'reason' => 'Reviewed, no change.',
            ])
            ->assertRedirect(route('platform.organization-modules.edit', $organization->id));

        $this->assertSame(0, OrganizationModule::query()->count());
        $this->assertSame(0, AuditEvent::query()
            ->where('action', ModuleStateService::AUDIT_ENTITLEMENT_CHANGED)
            ->count());
    }

    public function test_a_revoke_takes_the_module_out_of_the_active_set(): void
    {
        $organization = Organization::factory()->create();

        $this->assertTrue(app(ActiveModuleResolver::class)
            ->isActive((string) $organization->getKey(), ModuleKey::IncidentManagement));

        $this->actingAs($this->moduleOperator())
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitledExcept(ModuleKey::IncidentManagement),
            ]);

        $this->assertFalse(app(ActiveModuleResolver::class)
            ->isActive((string) $organization->getKey(), ModuleKey::IncidentManagement));
    }

    /**
     * MOD-010: module state is governance data, and the freeze applies to God
     * Mode as much as to an organizer. Deactivating a module mid-event would
     * take its records off devices that are holding them.
     */
    public function test_entitlement_is_frozen_during_the_active_event_window(): void
    {
        $organization = Organization::factory()->create();

        Event::factory()->for($organization)->create([
            'name' => 'Northwood Summer Gathering',
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        $operator = $this->moduleOperator();

        // The screen says so before a save is attempted, rather than letting an
        // operator find out by having one refused.
        $this->actingAs($operator)
            ->get(route('platform.organization-modules.edit', $organization))
            ->assertOk()
            ->assertSee('Northwood Summer Gathering')
            ->assertDontSee('Save entitlement');

        $this->actingAs($operator)
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitledExcept(ModuleKey::Scheduling),
            ])
            ->assertRedirect(route('platform.organization-modules.edit', $organization->id));

        $this->assertSame(0, OrganizationModule::query()->count());
        $this->assertSame(0, AuditEvent::query()
            ->where('action', ModuleStateService::AUDIT_ENTITLEMENT_CHANGED)
            ->count());
    }

    /**
     * MOD-010 and data/API 10.1A: the central node is authoritative, and an
     * on-site node does not originate module state changes.
     */
    public function test_an_on_site_node_does_not_originate_entitlement_changes(): void
    {
        $organization = Organization::factory()->create();

        $this->onNode(Node::ROLE_ONSITE);

        $this->actingAs($this->moduleOperator())
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => $this->allEntitledExcept(ModuleKey::Briefing),
            ])
            ->assertRedirect(route('platform.organization-modules.edit', $organization->id));

        $this->assertSame(0, OrganizationModule::query()->count());
        $this->assertSame(0, AuditEvent::query()
            ->where('action', ModuleStateService::AUDIT_ENTITLEMENT_CHANGED)
            ->count());
    }

    /**
     * MOD-003: the catalogue is fixed in code, so a key a submitted form
     * invented decides nothing rather than reaching the model's guard as a
     * server error.
     */
    public function test_a_module_key_outside_the_catalogue_is_ignored(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->moduleOperator())
            ->post(route('platform.organization-modules.edit', ['organization' => $organization, 'method' => 'save']), [
                'modules' => ['payroll' => ['entitled' => '0']],
            ])
            ->assertRedirect(route('platform.organization-modules.edit', $organization->id));

        $this->assertSame(0, OrganizationModule::query()->count());
    }

    /**
     * Enablement belongs to the organization (MOD-008) and is set from its own
     * configuration surface, so this screen offers no control for it.
     */
    public function test_the_screen_does_not_offer_the_organizations_enablement_control(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->actingAs($this->moduleOperator())
            ->get(route('platform.organization-modules.edit', $organization));

        $response->assertOk();

        foreach (ModuleKey::cases() as $module) {
            $response->assertDontSee('modules['.$module->value.'][enabled]', escape: false);
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

    /**
     * @return array<string, array{entitled: string}>
     */
    private function allEntitledExcept(ModuleKey $withheld): array
    {
        $modules = $this->allEntitled();
        $modules[$withheld->value] = ['entitled' => '0'];

        return $modules;
    }

    private function row(Organization $organization, ModuleKey $module): OrganizationModule
    {
        return OrganizationModule::query()
            ->where('organization_id', $organization->getKey())
            ->where('module_key', $module->value)
            ->sole();
    }

    private function onNode(string $role): Node
    {
        config()->set('meridian.node.role', $role);
        config()->set('meridian.node.name', 'meridian-test');

        return Node::factory()->create([
            'node_name' => 'meridian-test',
            'node_role' => $role,
            'is_local' => true,
        ]);
    }

    private function moduleOperator(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.organization-modules' => true,
            ],
        ]);
    }
}
