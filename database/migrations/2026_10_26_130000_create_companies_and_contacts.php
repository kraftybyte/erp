<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data induk perusahaan dan PIC-nya. Satu perusahaan bisa punya banyak PIC,
 * dan setiap Client (deal) menunjuk perusahaan serta PIC untuk order itu.
 *
 * Hanya menambah tabel dan kolom baru yang boleh kosong; kolom teks
 * nama_perusahaan/nama_pic di deals tetap ada dan tetap dipakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Nama huruf kecil dengan spasi dirapatkan, untuk mencocokkan nama yang diketik.
            $table->string('name_key')->index();
            $table->text('alamat')->nullable();
            $table->string('npwp', 100)->nullable();
            $table->text('notes')->nullable();
            // Diisi saat perusahaan ini digabung ke perusahaan lain.
            $table->foreignId('merged_into_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('company_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_key')->index();
            $table->string('jabatan')->nullable();
            $table->string('telephone', 50)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('company_contacts')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('company_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::dropIfExists('company_contacts');
        Schema::dropIfExists('companies');
    }
};
