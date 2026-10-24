<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use App\Services\TicketStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            // Resumen legible del ciclo de vida (CU-U02).
            'summary' => $this->summary(),
            'allowedTransitions' => array_map(
                fn ($s) => $s->value,
                app(TicketStateMachine::class)->allowedTransitions($this->status)
            ),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'priority' => $this->whenLoaded('priority', fn () => [
                'id' => $this->priority->id,
                'name' => $this->priority->name,
                'level' => $this->priority->level,
            ]),
            'createdBy' => new UserResource($this->whenLoaded('creator')),
            'assignedTo' => new UserResource($this->whenLoaded('assignee')),
            'resolution' => $this->resolution,
            'resolvedAt' => $this->resolved_at?->toISOString(),
            'closedAt' => $this->closed_at?->toISOString(),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'history' => TicketStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }

    private function summary(): string
    {
        $assignee = $this->relationLoaded('assignee') && $this->assignee
            ? "Asignado a {$this->assignee->name}"
            : 'Sin técnico asignado';

        return "{$assignee} - {$this->status->label()}";
    }
}
