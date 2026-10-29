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
        'invoice_id',
        'detail_project',
        'timeline_mulai',
        'timeline_selesai',
        'nama_perusahaan',
        'alamat',
        'nama_pic',
        'notes',
        'status',
        // Sender Information
        'sender_name',
        'sender_address',
        'sender_phone',
        // Recipient Information
        'recipient_name',
        'recipient_address',
        'recipient_phone',
    ];

    protected $casts = [
        'timeline_mulai' => 'date',
        'timeline_selesai' => 'date',
    ];

    public function Deal()
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /**
     * Invoice asal, bila project dibuat lewat tombol "Buat Project" di invoice.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
