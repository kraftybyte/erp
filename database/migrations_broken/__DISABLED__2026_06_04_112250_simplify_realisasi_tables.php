<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create realizeisasi_batches table
        Schema::create('realisasi_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advance_id');
            $table->unsignedInteger('batch_number')->default(1);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->foreign('advance_id')->references('id')->on('advances')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['advance_id', 'batch_number']);
        });

        // 2. Add realizeisasi fields to item_advances
        Schema::table('item_advances', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_id')->nullable()->after('price');
            $table->boolean('is_realized')->default(false)->after('batch_id');
            $table->timestamp('realized_at')->nullable()->after('is_realized');
            $table->unsignedBigInteger('realized_by')->nullable()->after('realized_at');

            $table->foreign('batch_id')->references('id')->on('realisasi_batches')->nullOnDelete();
            $table->foreign('realized_by')->references('id')->on('users')->nullOnDelete();
        });

        // 3. Update realizeisasi_files: rename column and FK
        if (Schema::hasTable('realisasi_files')) {
            // Drop old FK
            Schema::table('realisasi_files', function (Blueprint $table) {
                if (Schema::hasColumn('realisasi_files', 'realisasi_item_id')) {
                    $table->dropForeign(['realisasi_item_id']);
                }
            });

            // Rename column via raw SQL (Laravel doesn't support rename with FK)
            try {
                DB::statement('ALTER TABLE realizeisasi_files CHANGE COLUMN realizeisasi_item_id item_advance_id BIGINT UNSIGNED NOT NULL');
            } catch (\Exception $e) {
                // Column might already be named correctly or doesn't exist
                // Try to add if doesn't exist
                if (!Schema::hasColumn('realisasi_files', 'item_advance_id')) {
                    Schema::table('realisasi_files', function (Blueprint $table) {
                        $table->unsignedBigInteger('item_advance_id')->nullable()->after('id');
                    });
                }
            }

            // Add new FK
            Schema::table('realisasi_files', function (Blueprint $table) {
                if (Schema::hasColumn('realisasi_files', 'item_advance_id')) {
                    $table->foreign('item_advance_id')->references('id')->on('item_advances')->cascadeOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove fields from item_advances
        if (Schema::hasColumn('item_advances', 'batch_id')) {
            Schema::table('item_advances', function (Blueprint $table) {
                $table->dropForeign(['batch_id']);
                $table->dropForeign(['realized_by']);
                $table->dropColumn(['batch_id', 'is_realized', 'realized_at', 'realized_by']);
            });
        }

        // Drop realizeisasi_batches
        Schema::dropIfExists('realisasi_batches');

        // Revert realizeisasi_files column
        if (Schema::hasColumn('realisasi_files', 'item_advance_id')) {
            Schema::table('realisasi_files', function (Blueprint $table) {
                $table->dropForeign(['item_advance_id']);
            });

            try {
                DB::statement('ALTER TABLE realizeisasi_files CHANGE COLUMN item_advance_id realizeisasi_item_id BIGINT UNSIGNED NOT NULL');
            } catch (\Exception $e) {
                // Ignore
            }
        }
    }
};