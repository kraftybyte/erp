<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTimeline extends Model
{
    protected $fillable = [
        'project_id',
        'nama_phase',
        'start_date',
        'end_date',
        'status',
        'notes',
        'updated_by',
        'color',

    ];

protected $casts = [
    'start_date' => 'datetime',
    'end_date'   => 'datetime',
];

public function updater()
{
    return $this->belongsTo(User::class, 'updated_by');
}

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
