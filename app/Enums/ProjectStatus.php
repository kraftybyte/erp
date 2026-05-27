<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case OnGoing = 'On Going';
    case Hold = 'Hold';
    case Completed = 'Completed';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}