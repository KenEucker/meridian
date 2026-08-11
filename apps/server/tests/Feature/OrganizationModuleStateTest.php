<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Services\Modules\ActiveModuleResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Module catalogue, stored state, and the resolver that answers what an
 * organization runs (MOD-001 through MOD-005, MOD-007, MOD-009; technical spec
 * 15A.2, 15A.3; data/API 10.1A; M19.11).
 */
class OrganizationModuleStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_is_exactly_the_eight_alpha_1_modules(): void
    {
        // MOD-002. The catalogue is fixed in code for a given build (MOD-003),
        // so this list is a decision rather than a snapshot of one.
        $this->assertSame([
            'scheduling',
            'ims',
            'documents',
            'qualifications',
            'equipment',
            'geography',
            'briefing',
            'insights',
        ], ModuleKey::keys());
    }

    public function test_a_module_with_no_row_resolves_as_entitled_and_enabled(): void
    {
        // MOD-009: an organization created before this table existed is
        // entitled to and enabled for everything, so no organization loses
        // capability when the table arrives.
        $organization = Organization::factory()->create();

        $this->assertSame(0, OrganizationModule::query()->count());

        $resolver = app(ActiveModuleResolver::class);

        $this->assertEquals(ModuleKey::cases(), $resolver->activeFor($organization->id));

        foreach (ModuleKey::cases() as $module) {
            $this->assertTrue($resolver->isActive($organization->id, $module));
        }
    }

    public function test_a_module_is_active_only_when_entitled_and_enabled(): void
    {
        // MOD-005: two independent states, and active is both of them.
        $organization = Organization::factory()->create();

        $states = [
            [true, true, true],
            [true, false, false],
            [false, true, false],
            [false, false, false],
        ];

        foreach ($states as [$entitled, $enabled, $expected]) {
            OrganizationModule::query()->where('organization_id', $organization->id)->delete();

            OrganizationModule::factory()
                ->forModule(ModuleKey::Scheduling)
                ->create([
                    'organization_id' => $organization->id,
                    'entitled' => $entitled,
                    'enabled' => $enabled,
                ]);

            $this->assertSame(
                $expected,
                app(ActiveModuleResolver::class)->isActive($organization->id, ModuleKey::Scheduling),
                'entitled='.var_export($entitled, true).' enabled='.var_export($enabled, true),
            );
        }
    }

    public function test_revoking_entitlement_leaves_the_organizations_enabled_choice_standing(): void
    {
        // MOD-007: revoking entitlement makes the module inactive regardless of
        // enablement and does not clear the choice, so restoring entitlement
        // restores what the organization had rather than a default.
        $organization = Organization::factory()->create();

        $row = OrganizationModule::factory()
            ->forModule(ModuleKey::Documents)
            ->create([
                'organization_id' => $organization->id,
                'entitled' => true,
                'enabled' => false,
            ]);

        $row->update(['entitled' => false]);

        $this->assertFalse($row->fresh()->enabled, 'The enabled choice was rewritten by an entitlement revoke.');
        $this->assertFalse(app(ActiveModuleResolver::class)->isActive($organization->id, ModuleKey::Documents));

        $row->update(['entitled' => true]);

        $this->assertFalse(
            app(ActiveModuleResolver::class)->isActive($organization->id, ModuleKey::Documents),
            'Restoring entitlement defaulted the organization back on instead of honoring its own choice.',
        );
    }

    public function test_inactive_modules_are_absent_from_the_resolved_set(): void
    {
        $organization = Organization::factory()->create();

        OrganizationModule::factory()
            ->forModule(ModuleKey::Scheduling)
            ->disabled()
            ->create(['organization_id' => $organization->id]);

        OrganizationModule::factory()
            ->forModule(ModuleKey::IncidentManagement)
            ->unentitled()
            ->create(['organization_id' => $organization->id]);

        $active = app(ActiveModuleResolver::class)->activeFor($organization->id);

        $this->assertNotContains(ModuleKey::Scheduling, $active);
        $this->assertNotContains(ModuleKey::IncidentManagement, $active);
        $this->assertContains(ModuleKey::Documents, $active);
        $this->assertCount(count(ModuleKey::cases()) - 2, $active);
    }

    public function test_one_organizations_module_state_does_not_answer_for_another(): void
    {
        $narrow = Organization::factory()->create();
        $wide = Organization::factory()->create();

        OrganizationModule::factory()
            ->forModule(ModuleKey::Insights)
            ->disabled()
            ->create(['organization_id' => $narrow->id]);

        $resolver = app(ActiveModuleResolver::class);

        $this->assertFalse($resolver->isActive($narrow->id, ModuleKey::Insights));
        $this->assertTrue($resolver->isActive($wide->id, ModuleKey::Insights));
    }

    public function test_active_for_all_is_the_intersection_across_organizations(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();

        OrganizationModule::factory()
            ->forModule(ModuleKey::Equipment)
            ->disabled()
            ->create(['organization_id' => $first->id]);

        OrganizationModule::factory()
            ->forModule(ModuleKey::Briefing)
            ->disabled()
            ->create(['organization_id' => $second->id]);

        $active = app(ActiveModuleResolver::class)->activeForAll([$first->id, $second->id]);

        $this->assertNotContains(ModuleKey::Equipment, $active);
        $this->assertNotContains(ModuleKey::Briefing, $active);
        $this->assertContains(ModuleKey::Scheduling, $active);
    }

    public function test_the_resolver_answers_one_organization_from_one_read(): void
    {
        $organization = Organization::factory()->create();
        $resolver = app(ActiveModuleResolver::class);

        $resolver->activeFor($organization->id);

        OrganizationModule::factory()
            ->forModule(ModuleKey::Insights)
            ->disabled()
            ->create(['organization_id' => $organization->id]);

        $this->assertTrue(
            $resolver->isActive($organization->id, ModuleKey::Insights),
            'The resolver re-read the table for an organization it had already answered for.',
        );

        // A caller that has just changed module state says so, which is what
        // keeps the memo request-scoped rather than a cache with a lifetime.
        $resolver->forget($organization->id);

        $this->assertFalse($resolver->isActive($organization->id, ModuleKey::Insights));
    }

    public function test_an_unknown_module_key_is_rejected_rather_than_stored(): void
    {
        // Data/API 10.1A: the catalogue is not a table, so a row must never be
        // able to name a module the code does not implement (MOD-003).
        $organization = Organization::factory()->create();

        try {
            OrganizationModule::query()->create([
                'organization_id' => $organization->id,
                'module_key' => 'billing',
                'entitled' => true,
                'enabled' => true,
            ]);

            $this->fail('An unknown module key was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unknown module key [billing]', $exception->getMessage());
        }

        $this->assertSame(0, OrganizationModule::query()->count(), 'The rejected key was stored anyway.');
    }

    public function test_an_unknown_module_key_is_rejected_on_update_as_well(): void
    {
        $organization = Organization::factory()->create();

        $row = OrganizationModule::factory()
            ->forModule(ModuleKey::Scheduling)
            ->create(['organization_id' => $organization->id]);

        try {
            $row->update(['module_key' => 'plugins']);
            $this->fail('An unknown module key was accepted on update.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unknown module key [plugins]', $exception->getMessage());
        }

        $this->assertSame(ModuleKey::Scheduling->value, $row->fresh()->module_key);
    }

    public function test_an_organization_holds_at_most_one_row_per_module(): void
    {
        $organization = Organization::factory()->create();

        OrganizationModule::factory()
            ->forModule(ModuleKey::EventGeography)
            ->create(['organization_id' => $organization->id]);

        $this->expectException(QueryException::class);

        OrganizationModule::factory()
            ->forModule(ModuleKey::EventGeography)
            ->create(['organization_id' => $organization->id]);
    }

    public function test_a_row_naming_a_module_this_build_does_not_have_is_ignored(): void
    {
        // A catalogue that shrinks in a later build leaves rows behind. They
        // must not decide anything, and must not throw on the way past.
        $organization = Organization::factory()->create();

        OrganizationModule::factory()
            ->forModule(ModuleKey::Insights)
            ->disabled()
            ->create(['organization_id' => $organization->id]);

        OrganizationModule::query()->update(['module_key' => 'retired_module']);

        $resolver = app(ActiveModuleResolver::class);

        $this->assertEquals(ModuleKey::cases(), $resolver->activeFor($organization->id));
    }
}
