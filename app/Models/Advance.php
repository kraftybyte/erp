<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Observers\AdvanceObserver;
use Spatie\Permission\Traits\HasRoles;


class Advance extends Model
{
    use HasRoles;

    protected static function boot(): void
    {
        parent::boot();
        static::observe(AdvanceObserver::class);
    }

    protected $fillable = [
        'user_id',
        'submission_date',
        'project_id',
        'keterangan_advance',
        'amount',
        'attachment',
        'reference',
        'rejection_reason',
        'attachment_pay',
        'total_harga'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function itemAdvance()
    {
        return $this->hasMany(ItemAdvance::class);
    }
}