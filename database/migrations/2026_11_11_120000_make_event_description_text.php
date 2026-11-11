<?php

use App\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * events.description dulu VARCHAR(255), padahal setiap task menyalin detail
 * task-nya (HTML dari editor) ke sana: detail yang panjang membuat simpan task
 * gagal ("Data too long"), dan yang pendek tampil sebagai kode HTML di daftar
 * event dan kalender. Kolomnya dijadikan TEXT, lalu keterangan event yang
 * berasal dari task disalin ulang sebagai teks biasa. Event yang dibuat
 * manual tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events') || ! Schema::hasColumn('events', 'description')) {
            return;
        }

        if (! in_array(Schema::getColumnType('events', 'description'), ['text', 'mediumtext', 'longtext'], true)) {
            Schema::table('events', fn (Blueprint $table) => $table->text('description')->nullable()->change());
        }

        if (! Schema::hasColumn('events', 'task_id') || ! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'detail_task')) {
            return;
        }

        DB::table('events')
            ->join('tasks', 'tasks.id', '=', 'events.task_id')
            ->select('events.id', 'events.description', 'tasks.detail_task')
            ->orderBy('events.id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $plain = Event::plainText($row->detail_task);

                    if ($plain !== (string) $row->description) {
                        DB::table('events')->where('id', $row->id)->update(['description' => $plain]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Tidak dikembalikan ke VARCHAR(255): keterangan yang panjang akan terpotong.
    }
};
