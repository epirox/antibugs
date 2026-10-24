<?php

namespace App\Enums;

enum Role: string
{
    case USER = 'USER';
    case TECHNICIAN = 'TECHNICIAN';
    case ADMIN = 'ADMIN';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isStaff(): bool
    {
        return $this !== self::USER;
    }
}
