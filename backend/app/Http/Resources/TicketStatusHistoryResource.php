<?php

namespace App\Http\Resources;

use App\Models\TicketStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketStatusHistory */
class TicketStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from_status?->value,
            'to' => $this->to_status->value,
            'justification' => $this->justification,
            'actor' => new UserResource($this->whenLoaded('actor')),
            'assignedTo' => new UserResource($this->whenLoaded('assignee')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
