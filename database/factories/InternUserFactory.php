<?php

namespace Database\Factories;

use App\Models\InternUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InternUser>
 */
class InternUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone_number' => '628'.fake()->numerify('##########'),
            'is_active' => true,
            'notes' => fake()->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
