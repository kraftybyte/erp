<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email leads boleh kosong. Hanya melonggarkan kolom; isi yang sudah ada tetap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Tidak dikembalikan ke NOT NULL: leads tanpa email yang sudah tersimpan akan gagal.
    }
};
