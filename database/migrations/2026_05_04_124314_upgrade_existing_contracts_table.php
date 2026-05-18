<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Simpan data lama
            $table->string('client_name_old')->nullable();

            // Drop kolom lama
            $table->dropColumn(['client_name', 'address', 'email', 'contract_content']);

            // Tambah kolom baru
            $table->foreignId('deal_id')->nullable()->after('id')->constrained('deals')->nullOnDelete();
            $table->enum('type', ['editor', 'file'])->default('editor')->after('deal_id');
            $table->longText('content')->nullable()->after('type');
            $table->date('effective_date')->nullable()->after('content');
            $table->date('expiration_date')->nullable()->after('effective_date');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
            $table->dropColumn(['deal_id', 'type', 'content', 'effective_date', 'expiration_date']);

            $table->string('client_name')->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->text('contract_content')->nullable();
        });
    }
};