<?php

namespace App\DTOs;

final readonly class TicketFilters
{
    public function __construct(
        public ?string $status = null,
        public ?int $categoryId = null,
        public ?int $priorityId = null,
        public ?int $assignedTo = null,
        public ?int $createdBy = null,
        public bool $onlyMine = false,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            status: $query['status'] ?? null,
            categoryId: isset($query['categoryId']) ? (int) $query['categoryId'] : null,
            priorityId: isset($query['priorityId']) ? (int) $query['priorityId'] : null,
            assignedTo: isset($query['assignedTo']) ? (int) $query['assignedTo'] : null,
            createdBy: isset($query['createdBy']) ? (int) $query['createdBy'] : null,
            onlyMine: filter_var($query['mine'] ?? false, FILTER_VALIDATE_BOOLEAN),
            perPage: min((int) ($query['perPage'] ?? 15), 100),
        );
    }
}
