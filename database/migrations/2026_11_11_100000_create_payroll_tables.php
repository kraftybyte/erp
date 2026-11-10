<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji karyawan: komponen tetap per karyawan (pendapatan dan potongan, termasuk
 * PPh 21 dan BPJS yang ditulis sebagai potongan), lalu slip gaji per bulan yang
 * menyalin komponen itu dan bisa ditambah penyesuaian sekali pakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 10); // earning | deduction
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            // Slip adalah catatan keuangan: tetap ada walau akun karyawannya dihapus.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period'); // tanggal 1 bulan gaji
            $table->string('status', 10)->default('draft'); // draft | final | paid
            // Data karyawan saat slip dibuat.
            $table->string('employee_name');
            $table->string('employee_nik', 30)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->decimal('earnings', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
            $table->index(['period', 'status']);
        });

        Schema::create('payslip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 10); // earning | deduction
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            // Disalin dari komponen tetap (diganti saat "Muat ulang komponen"); false = penyesuaian bulan itu.
            $table->boolean('from_component')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_items');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_components');
    }
};
