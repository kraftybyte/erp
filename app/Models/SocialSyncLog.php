<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialSyncLog extends Model
{
    protected $fillable = [
        'social_account_id',
        'sync_type',
        'status',
        'error_message',
        'response_summary',
        'records_processed',
        'execution_time_ms',
    ];

    protected $casts = [
        'response_summary' => 'array',
        'records_processed' => 'integer',
        'execution_time_ms' => 'integer',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
