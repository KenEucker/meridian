<?php

namespace Database\Factories;

use App\Models\PermissionRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PermissionRole>
 */
class PermissionRoleFactory extends Factory
{
    protected $model = PermissionRole::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->slug(2);

        return [
            'name' => Str::title(str_replace('-', ' ', $code)),
            'code' => $code,
            'scope_type' => PermissionRole::SCOPE_TEAM,
        ];
    }

    public function scope(string $scopeType): static
    {
        return $this->state(fn (): array => [
            'scope_type' => $scopeType,
        ]);
    }
}
