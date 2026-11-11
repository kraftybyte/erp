<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TomatoPHP\FilamentLocations\Models\Currency;

/**
 * Mata uang kini dibaca dari tabel MySQL currencies (config/filament-locations.php
 * driver "database"), bukan Sushi/SQLite yang gagal di server dengan SQLite lama.
 * Tabel dibuat bila belum ada, lalu mata uang dari data paket yang belum ada
 * ditambahkan dengan id yang sama (invoice menyimpan currency_id, mis. IDR = 66).
 * Baris yang sudah ada tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->id();
                $table->string('arabic')->nullable();
                $table->string('name');
                $table->string('iso', 10);
                $table->timestamps();
                $table->double('exchange_rate')->nullable();
                $table->string('symbol')->nullable();
                $table->json('translations')->nullable();
                $table->boolean('is_activated')->nullable()->default(true);
            });
        }

        $file = dirname((new ReflectionClass(Currency::class))->getFileName(), 3) . '/database/data/currencies.json';
        $rows = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];

        if ($rows === []) {
            return;
        }

        $columns  = array_flip(Schema::getColumnListing('currencies'));
        $existing = DB::table('currencies')->pluck('id')->map(fn ($id) => (int) $id)->flip();

        $missing = collect($rows)
            ->reject(fn (array $row) => $existing->has((int) $row['id']))
            ->map(function (array $row) use ($columns) {
                $row['id']           = (int) $row['id'];
                $row['is_activated'] = isset($row['is_activated']) ? (int) (bool) $row['is_activated'] : 1;
                $row['translations'] = is_array($row['translations'] ?? null) ? json_encode($row['translations']) : ($row['translations'] ?? null);

                return array_intersect_key($row, $columns);
            });

        foreach ($missing->chunk(50) as $chunk) {
            DB::table('currencies')->insert($chunk->values()->all());
        }
    }

    public function down(): void
    {
        // Data mata uang dibiarkan: invoice merujuk ke id-nya.
    }
};
