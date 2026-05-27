<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Pending = 'Pending';
    case Disetujui = 'Disetujui';
    case Lunas = 'Lunas';
    case Ditolak = 'Ditolak';
    case Batal = 'Batal';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}