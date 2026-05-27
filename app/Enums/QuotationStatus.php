<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
    case Converted = 'Converted';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}