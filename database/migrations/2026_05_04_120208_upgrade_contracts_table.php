<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Konversi enum ke VARCHAR (MySQL tidak bisa alter enum langsung)
        DB::statement("ALTER TABLE absences MODIFY COLUMN category VARCHAR(20) DEFAULT 'cuti'");

        // Hapus constraint enum lama jika ada
        // Update data invalid ke nilai default
        DB::table('absences')
            ->whereNotIn('category', ['cuti', 'wfa', 'tukar_hari', 'sakit'])
            ->update(['category' => 'cuti']);

        // Tambahkan kolom baru
        Schema::table('absences', function (Blueprint $table) {
            $table->string('sick_letter')->nullable()->after('work_report');
            $table->text('rejection_reason')->nullable()->after('sick_letter');
        });
    }

    public function down(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->dropColumn(['sick_letter', 'rejection_reason']);
        });
    }
};