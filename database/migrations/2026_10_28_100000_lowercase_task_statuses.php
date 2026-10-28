<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status task dan key tahap Kanban diseragamkan ke huruf kecil (log, process,
 * review, done, hold). Sebagian data tersimpan "Done" / "Process", sehingga
 * task itu hilang dari Kanban dan dihitung salah di laporan HR. Isinya sama,
 * hanya huruf; updated_at tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')->whereNotNull('status')->update(['status' => DB::raw('LOWER(TRIM(status))')]);

        if (Schema::hasTable('task_stages')) {
            DB::table('task_stages')->update(['key' => DB::raw('LOWER(TRIM(`key`))')]);
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan: huruf besar/kecil aslinya tidak tercatat.
    }
};
