<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran kontrak bisa diatur penuh dari form: judul kolom Tabel A dan B,
 * label baris total, dan kolom Estimasi per baris biaya (dulu selalu sama
 * dengan Jumlah). Kosong = teks bawaan, jadi kontrak lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contracts')) {
            $add = [
                'table_a_columns'     => fn (Blueprint $t) => $t->json('table_a_columns')->nullable(),
                'table_a_total_label' => fn (Blueprint $t) => $t->string('table_a_total_label')->nullable(),
                'table_b_columns'     => fn (Blueprint $t) => $t->json('table_b_columns')->nullable(),
            ];

            foreach ($add as $column => $define) {
                if (! Schema::hasColumn('contracts', $column)) {
                    Schema::table('contracts', fn (Blueprint $table) => $define($table));
                }
            }
        }

        if (Schema::hasTable('contract_items') && ! Schema::hasColumn('contract_items', 'estimate')) {
            Schema::table('contract_items', fn (Blueprint $table) => $table->string('estimate')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contracts')) {
            foreach (['table_a_columns', 'table_a_total_label', 'table_b_columns'] as $column) {
                if (Schema::hasColumn('contracts', $column)) {
                    Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }

        if (Schema::hasTable('contract_items') && Schema::hasColumn('contract_items', 'estimate')) {
            Schema::table('contract_items', fn (Blueprint $table) => $table->dropColumn('estimate'));
        }
    }
};
