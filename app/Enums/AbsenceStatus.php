<?php

namespace App\Enums;

enum AbsenceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}