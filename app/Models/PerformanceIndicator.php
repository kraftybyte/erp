<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Task;
use App\Models\User;

class PerformanceIndicator extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'nilai',
        'deskripsi_pekerjaan',
    ];

    public function task()
{
    return $this->belongsTo(Task::class, 'task_id');
}

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
