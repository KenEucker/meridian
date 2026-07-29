<?php

namespace Database\Factories;

use App\Models\CreditPolicy;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditPolicy>
 */
class CreditPolicyFactory extends Factory
{
    protected $model = CreditPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'event_id' => null,
            'shift_id' => null,
            'name' => 'Standard Credit',
            'credit_multiplier' => '1.000',
            'archived_at' => null,
        ];
    }

    public function multiplier(string|float $multiplier): self
    {
        return $this->state(fn (): array => ['credit_multiplier' => (string) $multiplier]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
