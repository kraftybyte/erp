<?php

namespace App\Enums;

enum LeadStatus: string
{
    case DalamKomunikasi = 'Dalam Komunikasi';
    case ProposalSudahDikirim = 'Proposal Sudah Dikirim';
    case Menang = 'Menang';
    case Kalah = 'Kalah';
    case Converted = 'Converted';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}