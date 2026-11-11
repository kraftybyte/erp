<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat perubahan di Board Project Timeline: siapa menambah, menggeser,
 * mengubah, atau menghapus phase (dan menggeser/mengubah task dari board),
 * dengan nilai lama → baru. Tanpa foreign key: riwayat tetap ada walau phase,
 * task, project, atau penggunanya sudah dihapus (nama disimpan sebagai salinan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_timeline_logs')) {
            return;
        }

        Schema::create('project_timeline_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);            // phase | task
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('action', 20);                  // created | moved | updated | deleted
            $table->string('title');
            $table->json('diff')->nullable();              // {"kolom": [lama, baru]}
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_timeline_logs');
    }
};
