<?php

namespace App\DTOs;

use App\Enums\TicketStatus;

final readonly class ChangeTicketStatusData
{
    public function __construct(
        public TicketStatus $status,
        public string $justification,
        public ?int $assignedToUserId = null,
        public ?string $resolution = null,
    ) {}

    /**
     * @param  array{status: string, justification: string, assignedToUserId?: int|null, resolution?: string|null}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            status: TicketStatus::from($validated['status']),
            justification: $validated['justification'],
            assignedToUserId: isset($validated['assignedToUserId']) ? (int) $validated['assignedToUserId'] : null,
            resolution: $validated['resolution'] ?? null,
        );
    }
}
