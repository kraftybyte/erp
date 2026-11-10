<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database ERP lama (mis. erp.getkrafty.id) menyimpan rincian advance di
 * item_advances dan batch realisasi di realizasis, sedangkan model memakai
 * item_advance dan realisasi_batches (database produksi pernah diubah di luar
 * migrasi). Tabel lama diganti namanya, datanya ikut; kolom yang dibutuhkan
 * model ditambahkan bila belum ada. Database yang sudah benar tidak tersentuh.
 *
 * Bertanggal sebelum fix_missing_columns_safe (2026_06_06), yang mengubah
 * item_advance, supaya di database lama migrasi ini jalan lebih dulu. Di
 * instalasi baru, realizasis (dibuat 2026_06_03) langsung menjadi
 * realisasi_batches.
 */
return new class extends Migration
{
    private const RENAMES = [
        'item_advances' => 'item_advance',
        'realizasis'    => 'realisasi_batches',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $legacy => $current) {
            if (Schema::hasTable($legacy) && ! Schema::hasTable($current)) {
                Schema::rename($legacy, $current);
            }
        }

        $this->ensure('item_advance', [
            'name'        => fn (Blueprint $table) => $table->string('name')->default(''),
            'description' => fn (Blueprint $table) => $table->text('description')->nullable(),
            'quantity'    => fn (Blueprint $table) => $table->integer('quantity')->default(1),
            'price'       => fn (Blueprint $table) => $table->decimal('price', 10, 2)->default(0),
        ]);

        $this->ensure('realisasi_batches', [
            'batch_number' => fn (Blueprint $table) => $table->unsignedInteger('batch_number')->default(1),
            'uploaded_by'  => fn (Blueprint $table) => $table->unsignedBigInteger('uploaded_by')->nullable(),
            'notes'        => fn (Blueprint $table) => $table->text('notes')->nullable(),
            'deleted_at'   => fn (Blueprint $table) => $table->softDeletes(),
        ]);
    }

    /**
     * @param  array<string, callable(Blueprint): mixed>  $columns
     */
    private function ensure(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column => $define) {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $define($blueprint));
            }
        }
    }

    public function down(): void
    {
        // Nama lama tidak dikembalikan: model hanya mengenal nama yang baru.
    }
};
