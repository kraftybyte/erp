<?php

namespace Tests\Feature;

use App\Support\SchemaDrift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SQL dari SchemaDrift benar-benar jalan di MySQL: tabel percobaan bergaya ERP
 * lama disamakan dengan acuan, data lama tetap, dan baris baru yang tidak
 * mengisi kolom lama bisa disimpan. Tanpa DatabaseTransactions: ALTER TABLE
 * di MySQL selalu commit, jadi tabel percobaan dihapus sendiri.
 */
class SchemaDriftMigrationTest extends TestCase
{
    public function test_tabel_lama_disamakan_tanpa_kehilangan_data(): void
    {
        $table = 'tes_drift_' . Str::lower(Str::random(6));

        DB::statement("CREATE TABLE `{$table}` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `uuid` varchar(20) NOT NULL,
            `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
            `kode_lama` varchar(20) NOT NULL,
            `paid` double NOT NULL
        )");

        try {
            DB::table($table)->insert(['uuid' => '#PI/LAMA/1', 'status' => 'paid', 'kode_lama' => 'K1', 'paid' => 5]);

            $expected = [$table => [
                'id'       => ['type' => 'bigint unsigned', 'nullable' => false, 'auto' => true],
                'uuid'     => ['type' => 'varchar(255)', 'nullable' => false],
                'for_type' => ['type' => 'varchar(255)', 'nullable' => true],
                'for_id'   => ['type' => 'bigint unsigned', 'nullable' => true],
                'status'   => ['type' => 'varchar(255)', 'nullable' => true, 'default' => 'pending'],
                'paid'     => ['type' => 'double', 'nullable' => false, 'default' => '0'],
                'total'    => ['type' => 'decimal(15,2)', 'nullable' => true, 'default' => '0.00'],
            ]];

            $rows = DB::select('select TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
                                  from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ?', [$table]);
            $plan = SchemaDrift::plan(SchemaDrift::siteColumns(array_map(fn ($r) => array_values((array) $r), $rows)), $expected);

            foreach ($plan['statements'] as $name => $clauses) {
                DB::statement('ALTER TABLE `' . $name . '` ' . implode(', ', $clauses));
            }

            $this->assertTrue(Schema::hasColumns($table, ['for_type', 'for_id', 'total']));

            // Baris baru seperti konversi quotation → proforma: tanpa kode_lama dan paid.
            DB::table($table)->insert(['uuid' => '#PI/0132611/00001-' . Str::random(30), 'for_type' => 'quotation', 'for_id' => 132, 'status' => 'draft']);

            $old = DB::table($table)->where('uuid', '#PI/LAMA/1')->first();
            $new = DB::table($table)->where('for_type', 'quotation')->first();
            $this->assertSame(['paid', 'K1', 5.0], [$old->status, $old->kode_lama, (float) $old->paid], 'Data lama tetap.');
            $this->assertSame(['draft', null, 0.0, '0.00'], [$new->status, $new->kode_lama, (float) $new->paid, $new->total]);

            // Dijalankan lagi: tidak ada yang perlu diubah.
            $rows  = DB::select('select TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
                                   from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ?', [$table]);
            $again = SchemaDrift::plan(SchemaDrift::siteColumns(array_map(fn ($r) => array_values((array) $r), $rows)), $expected);
            $this->assertSame([], $again['statements']);
        } finally {
            Schema::dropIfExists($table);
        }
    }
}
