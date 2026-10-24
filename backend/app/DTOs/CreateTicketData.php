<?php

namespace App\DTOs;

/**
 * Contrato formal entre el controlador y el servicio (sección 2: DTOs).
 */
final readonly class CreateTicketData
{
    public function __construct(
        public string $title,
        public string $description,
        public int $categoryId,
        public ?int $priorityId,
        public int $createdBy,
    ) {}

    /**
     * @param  array{title: string, description: string, categoryId: int, priorityId?: int|null}  $validated
     */
    public static function fromArray(array $validated, int $createdBy): self
    {
        return new self(
            title: $validated['title'],
            description: $validated['description'],
            categoryId: (int) $validated['categoryId'],
            priorityId: isset($validated['priorityId']) ? (int) $validated['priorityId'] : null,
            createdBy: $createdBy,
        );
    }
}
