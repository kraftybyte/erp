<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Hasil analisa keuangan mingguan (financial:analyze): analysis = ringkasan
 * lima baris, metrics = hasil lengkap FinanceAdvisor (fakta, temuan, prioritas).
 */
class FinancialAiReport extends Model
{
    use HasFactory;

    protected $table = 'financial_ai_reports';

    // Dulu hanya 'analysis', sehingga metrics dan periode diam-diam tidak tersimpan.
    protected $fillable = [
        'analysis',
        'metrics',
        'period_start',
        'period_end',
        'version',
    ];

    protected $casts = [
        'metrics'      => 'array',
        'period_start' => 'date',
        'period_end'   => 'date',
    ];
}
