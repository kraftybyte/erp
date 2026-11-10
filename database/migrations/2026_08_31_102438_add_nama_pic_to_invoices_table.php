<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'nama_pic')) {
            return; // database lama yang kolomnya sudah ditambah di luar migrasi
        }

        Schema::table('invoices', function (Blueprint $table) {
            $column = $table->string('nama_pic')->nullable();
            Schema::hasColumn('invoices', 'alamat') && $column->after('alamat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('nama_pic');
        });
    }
};
