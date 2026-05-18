<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('task_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')
                ->constrained('tasks') // Menambahkan referensi ke tabel tasks
                ->onDelete('cascade'); // Jika task dihapus, akan menghapus data terkait
            $table->foreignId('user_id')
                ->constrained('users') // Menambahkan referensi ke tabel users
                ->onDelete('cascade'); // Jika user dihapus, akan menghapus data terkait
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_user');
    }
};
