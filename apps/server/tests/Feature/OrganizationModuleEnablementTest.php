<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\AuditActionCatalog;
use App\Domain\Modules\ModuleKey;
use App\Http\Middleware\EnforceActiveModule;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Modules\ActiveModuleResolver;
use App\Services\Modules\ModuleStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Organizer module enablement on the ORG-018 configuration surface (M19.15;
 * ORG-018, ORG-020, ORG-021; MOD-008, MOD-010, MOD-011; data/API 6.8, 10.1A).
 *
 * Enablement is the organization's half of module state: whether it uses what
 * the platform has made available to it. These assert what the requirements make
 * non-negotiable about the surface that sets it — only entitled modules are
 * offered, only `organization.configuration.manage` may change them, the
 * governance freeze applies, and every transition is audited with both halves of
 * previous and new state.
 */
class OrganizationModuleEnablementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_reads_the_modules_the_organization_may_choose(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/modules")
            ->assertOk()
            ->assertJsonPath('organization_id', (string) $organization->id)
            ->assertJsonPath('governance.editable', true)
            ->assertJsonPath('governance.frozen_by_event', null);

        // An organization with no rows is entitled to and running everything
        // (MOD-009), and the catalogue is what is listed rather than the rows.
        $this->assertCount(count(ModuleKey::cases()), $response->json('modules'));

        // MOD-022: Meridian's own names, and the key is an identifier rather
        // than a label.
        $this->assertSame(ModuleKey::Scheduling->label(), $response->json('modules.0.name'));
        $this->assertSame(ModuleKey::Scheduling->value, $response->json('modules.0.key'));
        $this->assertTrue($response->json('modules.0.enabled'));
        $this->assertNotSame('', (string) $response->json('modules.0.summary'));
        $this->assertNull($response->json('modules.0.changed_at'));
    }

    /**
     * MOD-008: a module the organization is not entitled to is not presented as
     * an organizer choice.
     */
    public function test_an_unentitled_module_is_not_offered(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        OrganizationModule::factory()->for($organization)->create([
            'module_key' => ModuleKey::Insights->value,
            'entitled' => false,
            'enabled' => true,
        ]);

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/modules")
            ->assertOk();

        $offered = array_column($response->json('modules'), 'key');

        $this->assertNotContains(ModuleKey::Insights->value, $offered);
        $this->assertContains(ModuleKey::Scheduling->value, $offered);
        $this->assertCount(count(ModuleKey::cases()) - 1, $offered);
    }

    /**
     * The other half of MOD-008. The control is absent, and so is the write
     * behind it — an organizer cannot reach around entitlement by naming the
     * module in a request, and the refusal leaves the organization's stored
     * choice standing (MOD-007).
     */
    public function test_enabling_an_unentitled_module_is_refused_and_writes_nothing(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        OrganizationModule::factory()->for($organization)->create([
            'module_key' => ModuleKey::Insights->value,
            'entitled' => false,
            'enabled' => false,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [
                    ModuleKey::Insights->value => true,
                    ModuleKey::Documents->value => false,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Insights'));

        // Refused as a whole: the entitled module named alongside it did not
        // half-apply.
        $this->assertFalse((bool) $this->row($organization, ModuleKey::Insights)->enabled);
        $this->assertNull(OrganizationModule::query()
            ->where('organization_id', $organization->id)
            ->where('module_key', ModuleKey::Documents->value)
            ->first());
        $this->assertSame(0, $this->auditCount());
    }

    /**
     * A stale form that restates an unentitled module's current state asks for
     * no transition, so it is not refused — an entitlement revoked while an
     * organizer had the page open does not turn their next save into an error.
     */
    public function test_restating_an_unentitled_modules_current_state_changes_nothing_and_is_not_refused(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        OrganizationModule::factory()->for($organization)->create([
            'module_key' => ModuleKey::Insights->value,
            'entitled' => false,
            'enabled' => true,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [
                    ModuleKey::Insights->value => true,
                    ModuleKey::Equipment->value => false,
                ],
            ])
            ->assertOk();

        $this->assertTrue((bool) $this->row($organization, ModuleKey::Insights)->enabled);
        $this->assertFalse((bool) $this->row($organization, ModuleKey::Equipment)->enabled);
    }

    /**
     * MOD-011: the module, both halves of the previous and new state, the actor,
     * and the reason where one is supplied.
     */
    public function test_disabling_a_module_is_audited_with_previous_and_new_state(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Scheduling->value => false],
                'reason' => 'Northwood runs its rosters elsewhere.',
            ])
            ->assertOk();

        $row = $this->row($organization, ModuleKey::Scheduling);

        $this->assertFalse((bool) $row->enabled);
        $this->assertTrue((bool) $row->entitled, 'Enablement is not entitlement\'s to move.');
        $this->assertSame((string) $organizer->getKey(), (string) $row->enablement_changed_by_user_id);
        $this->assertNotNull($row->enablement_changed_at);
        $this->assertNull($row->entitlement_changed_at);

        $audit = AuditEvent::query()
            ->where('action', ModuleStateService::AUDIT_ENABLEMENT_CHANGED)
            ->sole();

        $this->assertSame((string) $organization->getKey(), (string) $audit->organization_id);
        $this->assertSame((string) $organizer->getKey(), (string) $audit->actor_user_id);
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
            'entitled' => true,
            'enabled' => false,
            'active' => false,
        ], $audit->after_json);
    }

    /**
     * MOD-011 audits every transition without qualification, so an
     * organization's verbosity configuration must not be able to switch it off.
     */
    public function test_the_enablement_audit_is_in_the_required_floor(): void
    {
        $this->assertTrue(AuditActionCatalog::isRequired(ModuleStateService::AUDIT_ENABLEMENT_CHANGED));

        [$organization, $organizer] = $this->organizationWithOrganizer();

        $organization->forceFill([
            'audit_action_overrides' => [ModuleStateService::AUDIT_ENABLEMENT_CHANGED => false],
        ])->save();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Equipment->value => false],
            ])
            ->assertOk();

        $this->assertSame(1, $this->auditCount());
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => $this->allEnabled(),
                'reason' => 'Reviewed, no change.',
            ])
            ->assertOk();

        $this->assertSame(0, OrganizationModule::query()->count());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_turning_a_module_off_and_back_on_moves_the_active_set_both_ways(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();
        $organizationId = (string) $organization->getKey();

        $this->assertTrue($this->resolver()->isActive($organizationId, ModuleKey::Documents));

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Documents->value => false],
            ])
            ->assertOk()
            ->assertJsonPath('modules.2.key', ModuleKey::Documents->value)
            ->assertJsonPath('modules.2.enabled', false);

        $this->assertFalse($this->resolver()->isActive($organizationId, ModuleKey::Documents));

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Documents->value => true],
            ])
            ->assertOk();

        $this->assertTrue($this->resolver()->isActive($organizationId, ModuleKey::Documents));
        $this->assertSame(2, $this->auditCount());
    }

    /**
     * MOD-020: deactivating a module never deletes, archives, or anonymizes its
     * records. Turning Scheduling off is the first user-reachable deactivation
     * in the product, so it is worth asserting that the only row it wrote is its
     * own state.
     */
    public function test_disabling_a_module_leaves_its_records_alone(): void
    {
        [$organization, $organizer, $department] = $this->organizationWithOrganizer();

        $event = Event::factory()->for($organization)->create();
        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Scheduling->value => false],
            ])
            ->assertOk();

        $shift->refresh();
        $this->assertNotNull($shift->id);
        $this->assertNull($shift->deleted_at ?? null);
    }

    /**
     * ORG-020 and data/API 6.8: enablement requires
     * `organization.configuration.manage`, and nothing else grants it.
     */
    public function test_a_staff_coordinator_cannot_read_or_change_enablement(): void
    {
        [$organization] = $this->organizationWithOrganizer();
        $coordinator = $this->userWithRole('staff_coordinator', $organization);

        $this->actingAsClient($coordinator)
            ->getJson("/api/organizations/{$organization->id}/modules")
            ->assertForbidden();

        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Scheduling->value => false],
            ])
            ->assertForbidden();

        $this->assertSame(0, OrganizationModule::query()->count());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_an_organizer_of_another_organization_is_refused(): void
    {
        [$organization] = $this->organizationWithOrganizer();
        [, $outsider] = $this->organizationWithOrganizer();

        $this->actingAsClient($outsider)
            ->getJson("/api/organizations/{$organization->id}/modules")
            ->assertForbidden();

        $this->actingAsClient($outsider)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Scheduling->value => false],
            ])
            ->assertForbidden();

        $this->assertSame(0, OrganizationModule::query()->count());
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson("/api/organizations/{$organization->id}/modules")->assertUnauthorized();
        $this->postJson('/api/commands/update-organization-modules', [
            'organization_id' => $organization->id,
            'modules' => [ModuleKey::Scheduling->value => false],
        ])->assertUnauthorized();
    }

    /**
     * MOD-010: module state is governance data on the ORG-021 rule, so an
     * organizer is frozen out of it during the active event window exactly as
     * God Mode is. No module vanishes mid-event from under on-site staff.
     */
    public function test_enablement_is_frozen_during_the_active_event_window(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        Event::factory()->for($organization)->create([
            'name' => 'Northwood Summer Gathering',
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Scheduling->value => false],
            ])
            ->assertStatus(409);

        $this->assertSame(0, OrganizationModule::query()->count());
        $this->assertSame(0, $this->auditCount());

        // The read stays open and names the event holding the change, so the
        // surface explains the freeze rather than letting a save discover it.
        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/modules")
            ->assertOk()
            ->assertJsonPath('governance.editable', false)
            ->assertJsonPath('governance.frozen_by_event.name', 'Northwood Summer Gathering');
    }

    /**
     * MOD-010 and data/API 10.1A: the central node is authoritative and an
     * on-site node does not originate module state changes.
     */
    public function test_an_on_site_node_does_not_originate_enablement_changes(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        Node::factory()->create([
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => [ModuleKey::Scheduling->value => false],
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'central node'));

        $this->assertSame(0, OrganizationModule::query()->count());
        $this->assertSame(0, $this->auditCount());
    }

    /**
     * MOD-003: the catalogue is fixed in code, so a key a submitted form
     * invented decides nothing rather than reaching the model's guard as a
     * server error.
     */
    public function test_a_module_key_outside_the_catalogue_decides_nothing(): void
    {
        [$organization, $organizer] = $this->organizationWithOrganizer();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-organization-modules', [
                'organization_id' => $organization->id,
                'modules' => ['payroll' => false],
            ])
            ->assertOk();

        $this->assertSame(0, OrganizationModule::query()->count());
    }

    /**
     * The surface that turns a module back on must never be gated on one
     * (data/API 5.9). This is the same rule MOD-021 states for the console, one
     * layer down.
     */
    public function test_neither_endpoint_is_gated_on_a_module(): void
    {
        foreach (['api.organizations.modules.index', 'api.commands.update-organization-modules'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] no longer exists.");

            foreach ($route->gatherMiddleware() as $middleware) {
                $this->assertFalse(
                    is_string($middleware) && str_starts_with($middleware, EnforceActiveModule::class),
                    "[{$name}] administers module state and must not be gated on module state.",
                );
            }
        }
    }

    /**
     * @return array<string, bool>
     */
    private function allEnabled(): array
    {
        $modules = [];

        foreach (ModuleKey::cases() as $module) {
            $modules[$module->value] = true;
        }

        return $modules;
    }

    private function row(Organization $organization, ModuleKey $module): OrganizationModule
    {
        return OrganizationModule::query()
            ->where('organization_id', $organization->getKey())
            ->where('module_key', $module->value)
            ->sole();
    }

    private function auditCount(): int
    {
        return AuditEvent::query()
            ->where('action', ModuleStateService::AUDIT_ENABLEMENT_CHANGED)
            ->count();
    }

    private function resolver(): ActiveModuleResolver
    {
        // A fresh instance each time: the resolver memoizes for its own life, so
        // reusing one would answer from before the change under test.
        return new ActiveModuleResolver;
    }

    /**
     * @return array{0: Organization, 1: User, 2: Department}
     */
    private function organizationWithOrganizer(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $organization->forceFill(['organizers_department_id' => $department->id])->save();

        return [$organization, $this->userWithRole('organizer', $organization, $department), $department];
    }

    private function userWithRole(
        string $roleCode,
        Organization $organization,
        ?Department $department = null,
    ): User {
        $department ??= Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
