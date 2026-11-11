<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status phase timeline dulu ditulis dua cara: Board memakai "completed",
 * menu Timeline memakai "done". Keduanya berarti selesai; disatukan menjadi
 * "completed" (ProjectTimeline::DONE) supaya board, hitungan terlambat, dan
 * filter membacanya sama. Status kosong menjadi "ongoing" (bawaan kolom).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_timelines') || ! Schema::hasColumn('project_timelines', 'status')) {
            return;
        }

        DB::table('project_timelines')->whereIn(DB::raw('lower(status)'), ['done', 'selesai', 'complete'])->update(['status' => 'completed']);
        DB::table('project_timelines')->where(fn ($q) => $q->whereNull('status')->orWhere('status', ''))->update(['status' => 'ongoing']);
    }

    public function down(): void
    {
        // Tidak dikembalikan: "done" dan "completed" berarti sama.
    }
};
