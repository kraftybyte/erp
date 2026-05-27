<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Log = 'Log';
    case Process = 'Process';
    case Review = 'Review';
    case Done = 'Done';
    case Hold = 'Hold';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}