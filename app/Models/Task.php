<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Observers\TaskObserver;

class Task extends Model
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'nama_task',
        'project_id',
        'deal_id',
        'detail_task',
        'mulai_task',
        'selesai_task',
        'status',
        'proritas',
        'nama_layanan',
        'creator_id',
    ];

    protected static function boot()
    {
        parent::boot();
        static::observe(TaskObserver::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'task_user', 'task_id', 'user_id');
    }

    public function performanceIndicator()
    {
        return $this->hasOne(PerformanceIndicator::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }
}
