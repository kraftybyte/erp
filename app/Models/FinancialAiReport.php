<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialAiReport extends Model
{
    use HasFactory;

    protected $table = 'financial_ai_reports';

    protected $fillable = [
        'analysis',
    ];
}
