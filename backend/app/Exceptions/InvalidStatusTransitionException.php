<?php

namespace App\Exceptions;

use App\Enums\TicketStatus;
use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly TicketStatus $from,
        public readonly TicketStatus $to,
        ?string $reason = null,
    ) {
        parent::__construct(
            $reason ?? sprintf('La transición %s → %s no está permitida.', $from->value, $to->value)
        );
    }
}
