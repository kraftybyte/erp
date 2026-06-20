<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    use HasFactory;
    protected $fillable = [
        'nama_project',
        'deal_id',
        'detail_project',
        'timeline_mulai',
        'timeline_selesai',
        'nama_perusahaan',
        'alamat',
        'nama_pic',
        'notes',
        'status',
    ];

    protected $casts = [
        'timeline_mulai' => 'date',
        'timeline_selesai' => 'date',
    ];

    public function Deal()
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function tasks()
    {
        return $this->hasMany(Task::class, 'project_id', 'id');
    }

    public function timelines()
    {
        return $this->hasMany(ProjectTimeline::class);
    }


}
