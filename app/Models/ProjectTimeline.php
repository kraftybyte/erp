<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu phase pekerjaan project (Design, Shooting, …) dengan rentang tanggal,
 * ditampilkan di Board Project Timeline. start_date dan end_date sama-sama
 * inklusif (phase 1–3 Nov berarti tiga hari kerja).
 */
class ProjectTimeline extends Model
{
    public const STATUSES = [
        'pending'   => 'Menunggu',
        'ongoing'   => 'Berjalan',
        'delayed'   => 'Tertunda',
        'completed' => 'Selesai',
    ];

    public const DONE = 'completed';

    /** Warna tetap untuk phase yang umum; nama lain mendapat warna dari PALETTE. */
    private const PHASE_COLORS = [
        'content planning' => '#2394b8',
        'design'           => '#ff5915',
        'video editing'    => '#fcc530',
    ];

    private const PALETTE = ['#2563eb', '#7c3aed', '#0d9488', '#db2777', '#65a30d', '#ea580c', '#0891b2', '#9333ea'];

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

    /** Warna phase: sama untuk nama yang sama di semua project. */
    public static function colorFor(string $phase): string
    {
        $key = strtolower(trim($phase));

        return self::PHASE_COLORS[$key] ?? self::PALETTE[crc32($key) % count(self::PALETTE)];
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    /** Hari terlambat (0 bila selesai atau belum lewat tenggat). */
    public function daysLate(CarbonInterface $today): int
    {
        return $this->isDone() || ! $this->end_date->startOfDay()->lt($today) ? 0 : (int) $this->end_date->startOfDay()->diffInDays($today);
    }

    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('status', '!=', self::DONE)->orWhereNull('status'));
    }

    public function scopeOverdue(Builder $query, CarbonInterface $today): Builder
    {
        return $query->unfinished()->whereDate('end_date', '<', $today);
    }

    public function scopeRunning(Builder $query, CarbonInterface $today): Builder
    {
        return $query->unfinished()->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today);
    }
}
