<?php

namespace Database\Factories;

use App\Domain\Modules\ModuleKey;
use App\Models\Organization;
use App\Models\OrganizationModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationModule>
 */
class OrganizationModuleFactory extends Factory
{
    protected $model = OrganizationModule::class;

    /**
     * Define the model's default state.
     *
     * Entitled and enabled, because that is what MOD-009 makes every module for
     * every organization unless somebody deliberately narrows it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'module_key' => ModuleKey::Scheduling->value,
            'entitled' => true,
            'enabled' => true,
            'entitlement_changed_at' => null,
            'entitlement_changed_by_user_id' => null,
            'enablement_changed_at' => null,
            'enablement_changed_by_user_id' => null,
        ];
    }

    public function forModule(ModuleKey $module): static
    {
        return $this->state(fn (): array => [
            'module_key' => $module->value,
        ]);
    }

    /**
     * The platform has taken the module away (MOD-007). The organization's own
     * choice is left standing.
     */
    public function unentitled(): static
    {
        return $this->state(fn (): array => [
            'entitled' => false,
            'entitlement_changed_at' => now(),
        ]);
    }

    /**
     * The organization has chosen not to run it (MOD-008).
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'enabled' => false,
            'enablement_changed_at' => now(),
        ]);
    }
}
