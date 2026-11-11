<?php

namespace App\Observers;

use App\Models\ProjectTimeline;
use App\Models\ProjectTimelineLog;

/**
 * Setiap perubahan phase timeline (dari Board maupun menu Timeline) masuk ke
 * riwayat ProjectTimelineLog beserta penggunanya.
 */
class ProjectTimelineObserver
{
    public function created(ProjectTimeline $timeline): void
    {
        $this->log($timeline, 'created', [], $this->values($timeline->getAttributes()));
    }

    public function updated(ProjectTimeline $timeline): void
    {
        // Di event updated, getOriginal() masih berisi nilai sebelum disimpan.
        $this->log($timeline, 'updated', $this->values($timeline->getRawOriginal()), $this->values($timeline->getAttributes()));
    }

    public function deleted(ProjectTimeline $timeline): void
    {
        $this->log($timeline, 'deleted', $this->values($timeline->getAttributes()), []);
    }

    private function log(ProjectTimeline $timeline, string $action, array $old, array $new): void
    {
        ProjectTimelineLog::record('phase', $timeline->getKey(), $timeline->project_id, (string) $timeline->nama_phase, $action, $old, $new);
    }

    /** @return array{start: ?string, end: ?string, status: ?string, name: ?string, notes: ?string} */
    private function values(array $attributes): array
    {
        return [
            'start'  => ProjectTimelineLog::day($attributes['start_date'] ?? null),
            'end'    => ProjectTimelineLog::day($attributes['end_date'] ?? null),
            'status' => $attributes['status'] ?? null,
            'name'   => $attributes['nama_phase'] ?? null,
            'notes'  => $attributes['notes'] ?? null,
        ];
    }
}
