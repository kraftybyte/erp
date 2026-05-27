<?php

namespace App\Enums;

enum AbsenceCategory: string
{
    case Cuti = 'cuti';
    case Wfa = 'wfa';
    case Wfh = 'wfh';
    case Sakit = 'sakit';
    case TukarHari = 'tukar_hari';
    case Izin = 'izin';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}