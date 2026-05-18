<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Observers\AnnouncementObserver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    protected static function boot(): void
    {
        parent::boot();
        static::observe(AnnouncementObserver::class);
    }

    protected $fillable = ['content', 'start_date', 'end_date', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
