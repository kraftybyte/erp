<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris mutasi rekening yang sudah dicatat lewat bot keuangan. Sidik jari
 * (tanggal, arah, nominal, keterangan) unik per rekening: unggahan ulang atau
 * mutasi yang periodenya tumpang tindih tidak tercatat dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_mutations')) {
            return;
        }

        Schema::create('bank_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->string('fingerprint', 64);
            $table->date('mutation_date');
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 15, 2);
            $table->text('description');
            $table->decimal('balance', 15, 2)->nullable();
            // [{type: invoice|expense|advance, id, amount}]
            $table->json('targets');
            $table->string('upload', 36)->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payment_account_id', 'fingerprint']);
            $table->index('mutation_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_mutations');
    }
};
