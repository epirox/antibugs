<?php

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TicketStatus::OPEN,
            'category_id' => Category::factory(),
            'priority_id' => Priority::factory(),
            'created_by' => User::factory(),
            'assigned_to' => null,
        ];
    }

    public function assigned(?User $technician = null): static
    {
        return $this->state(fn () => [
            'status' => TicketStatus::ASSIGNED,
            'assigned_to' => $technician?->id ?? User::factory()->technician(),
        ]);
    }

    public function inProgress(?User $technician = null): static
    {
        return $this->state(fn () => [
            'status' => TicketStatus::IN_PROGRESS,
            'assigned_to' => $technician?->id ?? User::factory()->technician(),
        ]);
    }

    public function resolved(?User $technician = null): static
    {
        return $this->state(fn () => [
            'status' => TicketStatus::RESOLVED,
            'assigned_to' => $technician?->id ?? User::factory()->technician(),
            'resolution' => fake()->paragraph(),
            'resolved_at' => now(),
        ]);
    }
}
