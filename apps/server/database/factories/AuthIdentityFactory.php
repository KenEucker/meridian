<?php

namespace Database\Factories;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthIdentity>
 */
class AuthIdentityFactory extends Factory
{
    protected $model = AuthIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => AuthIdentity::PROVIDER_EMAIL,
            'provider_subject' => fake()->unique()->safeEmail(),
            'provider_email' => fake()->unique()->safeEmail(),
            'provider_email_verified' => true,
        ];
    }

    public function google(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => (string) fake()->unique()->numberBetween(100000000, 999999999),
        ]);
    }

    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => AuthIdentity::PROVIDER_DISCORD,
            'provider_subject' => (string) fake()->unique()->numberBetween(100000000, 999999999),
        ]);
    }
}
