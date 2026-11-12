<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tulis ulang database/schema/expected-columns.php (acuan SchemaDrift untuk
 * menyamakan database lama, mis. erp.getkrafty.id) dari database ini. Jalankan
 * di database yang sudah menjalankan semua migrasi, setiap kali ada migrasi
 * yang menambah atau mengubah kolom.
 */
class SchemaSnapshot extends Command
{
    protected $signature = 'schema:snapshot';

    protected $description = 'Perbarui database/schema/expected-columns.php dari database ini';

    public function handle(): int
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('Hanya untuk MySQL/MariaDB.');

            return self::FAILURE;
        }

        $rows = DB::select(
            'select c.TABLE_NAME t, c.COLUMN_NAME c, c.COLUMN_TYPE ty, c.IS_NULLABLE n, c.COLUMN_DEFAULT d, c.EXTRA e
               from information_schema.COLUMNS c
               join information_schema.TABLES tb on tb.TABLE_SCHEMA = c.TABLE_SCHEMA and tb.TABLE_NAME = c.TABLE_NAME and tb.TABLE_TYPE = ?
              where c.TABLE_SCHEMA = database()
              order by c.TABLE_NAME, c.ORDINAL_POSITION',
            ['BASE TABLE']
        );

        $schema = [];

        foreach ($rows as $row) {
            $type    = strtolower($row->ty);
            $extra   = strtolower((string) $row->e);
            $literal = $row->d !== null && ! str_contains($extra, 'default_generated')
                && ! preg_match('/^(tiny|medium|long)?(text|blob)|^json/', $type);

            $schema[$row->t][$row->c] = array_filter([
                'type'     => $type,
                'nullable' => $row->n === 'YES',
                'default'  => $literal ? (string) $row->d : null,
                'auto'     => str_contains($extra, 'auto_increment') ?: null,
            ], fn ($value) => $value !== null);
        }

        $header = "<?php\n\n/**\n * Kolom setiap tabel ERP (tipe MySQL, boleh kosong, nilai bawaan) setelah semua\n * migrasi dijalankan. Acuan untuk migrasi 2026_11_11_150000 (menambah kolom\n * yang hilang di database lama) dan `migrate-cwp.sh check`.\n *\n * Dibuat ulang dengan `php artisan schema:snapshot` dari database yang sudah\n * menjalankan semua migrasi; jangan diubah manual.\n */\n\nreturn ";

        file_put_contents(database_path('schema/expected-columns.php'), $header . var_export($schema, true) . ";\n");
        $this->info(count($schema) . ' tabel, ' . count($rows) . ' kolom.');

        return self::SUCCESS;
    }
}
