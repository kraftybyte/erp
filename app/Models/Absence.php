<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Observers\AbsenceObserver;
use Illuminate\Database\Eloquent\SoftDeletes;
use Filament\Notifications\Notification;

class Absence extends Model
{
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();
        static::observe(AbsenceObserver::class);
    }

    protected $fillable = [
        'user_id',
        'category',
        'description',
        'start_time',
        'end_time',
        'approval_status',
        'approved_by',
        'work_report',
        'sick_letter',
        'rejection_reason',
    ];

    protected $casts = [
        'start_time' => 'date',
        'end_time' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function hasSickLetter(): bool
    {
        return !empty($this->sick_letter);
    }

    public function getSickLetterUrl(): ?string
    {
        return $this->sick_letter
            ? asset('storage/' . $this->sick_letter)
            : null;
    }
}