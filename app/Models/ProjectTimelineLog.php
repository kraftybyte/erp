<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris riwayat Board Project Timeline: siapa (user_name disalin supaya
 * tetap terbaca walau akunnya dihapus) melakukan apa pada phase/task, dengan
 * perubahan [lama, baru] per kolom (start, end, status, name, notes) di diff.
 * (Bukan "changes": nama itu dipakai properti internal Eloquent.)
 *
 * Hanya ditambah, tidak pernah diubah atau dihapus dari aplikasi.
 */
class ProjectTimelineLog extends Model
{
    public const UPDATED_AT = null;

    private const TASK_STATUSES = ['log' => 'To Do', 'process' => 'Progress', 'review' => 'Review', 'done' => 'Done', 'hold' => 'Hold'];

    protected $fillable = ['subject_type', 'subject_id', 'project_id', 'user_id', 'user_name', 'action', 'title', 'diff'];

    protected $casts = [
        'diff'       => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    /**
     * Catat perubahan. $old/$new: nilai per kolom (start, end, status, name,
     * notes); kolom yang sama nilainya diabaikan. Tidak mencatat apa pun bila
     * tidak ada yang berubah. Gagal mencatat tidak menggagalkan perubahannya.
     */
    public static function record(string $type, int $subjectId, ?int $projectId, string $title, string $action, array $old, array $new): void
    {
        $changes = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $before = $old[$key] ?? null;
            $after  = $new[$key] ?? null;

            if ((string) $before !== (string) $after) {
                $changes[$key] = [$before, $after];
            }
        }

        // Satu tanggal berubah: simpan keduanya supaya rentang lama/baru terbaca utuh.
        if (isset($changes['start']) || isset($changes['end'])) {
            foreach (['start', 'end'] as $key) {
                $changes[$key] ??= [$old[$key] ?? null, $new[$key] ?? null];
            }
        }

        if ($action === 'updated') {
            if ($changes === []) {
                return;
            }

            // Hanya tanggal yang berubah: digeser atau ditarik ujungnya.
            if (array_diff(array_keys($changes), ['start', 'end']) === []) {
                $action = 'moved';
            }
        }

        try {
            $user = auth()->user();

            static::create([
                'subject_type' => $type,
                'subject_id'   => $subjectId,
                'project_id'   => $projectId,
                'user_id'      => $user?->getKey(),
                'user_name'    => $user?->name,
                'action'       => $action,
                'title'        => $title,
                'diff'         => $changes ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Tanggal sebagai Y-m-d (atau null) untuk dibandingkan dan disimpan. */
    public static function day(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    /** Kalimat riwayat, mis. "menggeser phase Design: 3–7 Nov → 4–8 Nov 2026". */
    public function summary(): string
    {
        $kind    = $this->subject_type === 'task' ? 'task' : 'phase';
        $changes = $this->diff ?? [];
        $title   = $this->title;

        return match ($this->action) {
            'created' => "menambah {$kind} {$title}" . $this->rangeText($changes, 1, ' (%s)'),
            'deleted' => "menghapus {$kind} {$title}" . $this->rangeText($changes, 0, ' (%s)'),
            'moved'   => "menggeser {$kind} {$title}: " . $this->rangeText($changes, 0) . ' → ' . $this->rangeText($changes, 1),
            default   => "mengubah {$kind} {$title}: " . implode('; ', $this->changeParts($changes)),
        };
    }

    /** @return list<string> */
    private function changeParts(array $changes): array
    {
        $parts = [];

        if (isset($changes['start']) || isset($changes['end'])) {
            $parts[] = 'tanggal ' . $this->rangeText($changes, 0) . ' → ' . $this->rangeText($changes, 1);
        }

        if (isset($changes['name'])) {
            $parts[] = "nama {$changes['name'][0]} → {$changes['name'][1]}";
        }

        if (isset($changes['status'])) {
            $parts[] = 'status ' . $this->statusLabel($changes['status'][0]) . ' → ' . $this->statusLabel($changes['status'][1]);
        }

        if (isset($changes['notes'])) {
            $parts[] = 'catatan';
        }

        return $parts ?: ['detail'];
    }

    private function statusLabel(?string $status): string
    {
        $labels = $this->subject_type === 'task' ? self::TASK_STATUSES : ProjectTimeline::STATUSES;

        return $labels[$status] ?? ($status ?: '-');
    }

    /**
     * Rentang tanggal lama ($side 0) atau baru ($side 1). Kolom yang tidak
     * berubah tidak ada di $changes; untuk "moved" keduanya selalu tersimpan.
     */
    private function rangeText(array $changes, int $side, string $format = '%s'): string
    {
        $start = $changes['start'][$side] ?? $changes['end'][$side] ?? null;
        $end   = $changes['end'][$side] ?? $start;

        if (! $start) {
            return '';
        }

        $from = Carbon::parse($start);
        $last = Carbon::parse($end);
        $text = match (true) {
            $from->isSameDay($last)   => $from->translatedFormat('d M Y'),
            $from->isSameMonth($last) => $from->format('d') . '–' . $last->translatedFormat('d M Y'),
            $from->isSameYear($last)  => $from->translatedFormat('d M') . '–' . $last->translatedFormat('d M Y'),
            default                   => $from->translatedFormat('d M Y') . '–' . $last->translatedFormat('d M Y'),
        };

        return sprintf($format, $text);
    }
}
