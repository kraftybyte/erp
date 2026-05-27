<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Pending = 'Pending';
    case Disetujui = 'Disetujui';
    case Ditolak = 'Ditolak';
    case Dibayarkan = 'Dibayarkan';
    case Selesai = 'Selesai';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}