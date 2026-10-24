<?php

namespace Database\Factories;

use App\Models\Priority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Priority>
 */
class PriorityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lexify('PRIO_????'),
            'level' => fake()->unique()->numberBetween(1, 10),
            'sla_hours' => 24,
        ];
    }

    public function low(): static
    {
        return $this->state(fn () => ['name' => 'LOW', 'level' => 1, 'sla_hours' => 72]);
    }

    public function high(): static
    {
        return $this->state(fn () => ['name' => 'HIGH', 'level' => 3, 'sla_hours' => 8]);
    }
}
