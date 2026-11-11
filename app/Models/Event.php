<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'start_time', 'end_time', 'color', 'create_by', 'task_id'];

    protected $casts = [
        'start_time' => 'datetime:Y-m-d\TH:i:sP',
        'end_time' => 'datetime:Y-m-d\TH:i:sP',
    ];

    /**
     * Keterangan event dari detail task (HTML editor) sebagai teks biasa:
     * paragraf, baris, dan butir daftar menjadi baris baru. Keterangan event
     * diisi lewat textarea dan ditampilkan sebagai teks di kalender.
     */
    public static function plainText(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|h[1-6]|li|blockquote)>/i', '/<li[^>]*>/i'], ["\n", "\n", '• '], $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(["/[ \t\x{00A0}]+/u", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], $text);

        return trim($text);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'create_by');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
