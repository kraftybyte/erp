<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'start_time', 'end_time', 'color','create_by'];

    protected $casts = [
        'start_time' => 'datetime:Y-m-d\TH:i:sP',
        'end_time' => 'datetime:Y-m-d\TH:i:sP',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'create_by'); // Sesuaikan dengan foreign key yang digunakan
    }
}
