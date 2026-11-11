<?php

use App\Support\SchemaDrift;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database ERP lama (mis. erp.getkrafty.id) pernah diubah di luar migrasi:
 * ada kolom yang tidak pernah ditambahkan (mis. invoices.for_type, sehingga
 * konversi quotation → proforma gagal), atau kolom lama yang wajib diisi
 * padahal kode baru tidak mengisinya. Migrasi ini menyamakan kolom dengan
 * acuan database/schema/expected-columns.php lewat SchemaDrift: hanya
 * menambah kolom, membuat kolom boleh kosong, atau melebarkan tipe — tidak
 * ada kolom atau data yang dihapus. Database yang sudah sesuai tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $rows = DB::select(
            'select TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
               from information_schema.COLUMNS where TABLE_SCHEMA = database()'
        );

        $plan = SchemaDrift::plan(
            SchemaDrift::siteColumns(array_map(fn ($row) => array_values((array) $row), $rows)),
            require database_path('schema/expected-columns.php'),
        );

        foreach ($plan['statements'] as $table => $clauses) {
            DB::statement('ALTER TABLE `' . $table . '` ' . implode(', ', $clauses));
        }

        if ($plan['statements'] !== []) {
            Log::info('Kolom database disamakan dengan acuan', array_intersect_key($plan, array_flip(['added', 'relaxed', 'widened'])));
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan: kolom tambahan dan kolom boleh-kosong tidak merusak data.
    }
};
