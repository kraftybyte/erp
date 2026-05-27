<?php

namespace App\Enums;

enum DealStatus: string
{
    case Negosiasi = 'Negosiasi';
    case MenungguPembayaran = 'Menunggu Pembayaran';
    case DalamTahapProject = 'Dalam Tahap Project';
    case Selesai = 'Selesai';
    case Pending = 'Pending';
    case Hold = 'Hold';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}