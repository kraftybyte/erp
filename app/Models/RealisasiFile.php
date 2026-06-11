<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class RealisasiFile extends Model
{
    use SoftDeletes;

    protected $table = 'realisasi_files';

    protected $fillable = [
        'realisasi_item_id',
        'original_name',
        'filepath',
        'filesize',
        'mime_type',
    ];

    protected $casts = [
        'filesize' => 'integer',
    ];

    // Relationships
    public function realizedItem(): BelongsTo
    {
        return $this->belongsTo(RealisasiItem::class, 'realisasi_item_id');
    }

    // Helpers
    public function getUrl(): string
    {
        return Storage::url($this->filepath);
    }

    public function getSizeFormatted(): string
    {
        $bytes = $this->filesize;
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        } else {
            return round($bytes / 1048576, 1) . ' MB';
        }
    }

    public function isImage(): bool
    {
        return $this->mime_type && str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function getIconType(): string
    {
        if ($this->isPdf()) {
            return 'pdf';
        } elseif ($this->isImage()) {
            return 'image';
        }
        return 'file';
    }
}