<?php

namespace App\Enums;

/**
 * Kategori pengajuan expense dan advance. Dipakai form Filament dan bot
 * Telegram, jadi daftar ini satu-satunya tempat menambah kategori.
 */
enum FinanceCategory: string
{
    case Marketing = 'Marketing';
    case Transport = 'Transport';
    case OperasionalKantor = 'Operasional Kantor';
    case Komunikasi = 'Komunikasi';
    case Software = 'Software';
    case Entertainment = 'Entertainment';
    case ProjectSupport = 'Project Support';
    case BiayaLain = 'Biaya Lain';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
