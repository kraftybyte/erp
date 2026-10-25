<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Semua lampiran pengajuan advance (penawaran, referensi, dll.).
        // Kolom `attachment` tetap berisi lampiran pertama supaya tampilan lama
        // tidak berubah.
        Schema::table('advances', function (Blueprint $table) {
            if (! Schema::hasColumn('advances', 'attachments')) {
                $table->json('attachments')->nullable()->after('attachment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            if (Schema::hasColumn('advances', 'attachments')) {
                $table->dropColumn('attachments');
            }
        });
    }
};
