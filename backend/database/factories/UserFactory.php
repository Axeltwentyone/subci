<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '07'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['phone_verified_at' => null]);
    }
}
