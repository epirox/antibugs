<?php

namespace App\Enums;

enum TicketStatus: string
{
    case OPEN = 'OPEN';
    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case PENDING_INFO = 'PENDING_INFO';
    case RESOLVED = 'RESOLVED';
    case CLOSED = 'CLOSED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isFinal(): bool
    {
        return $this === self::CLOSED;
    }

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Abierto',
            self::ASSIGNED => 'Asignado',
            self::IN_PROGRESS => 'En proceso',
            self::PENDING_INFO => 'Pendiente de información',
            self::RESOLVED => 'Resuelto',
            self::CLOSED => 'Cerrado',
        };
    }
}
