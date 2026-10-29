<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project bisa dibuat dari invoice: simpan asal invoice-nya (agar tidak dibuat
 * dua kali dan bisa dibuka dari invoice), dan detail project menampung rincian
 * item invoice yang sering lebih dari 255 karakter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'invoice_id')) {
                $table->foreignId('invoice_id')->nullable()->after('deal_id')->constrained('invoices')->nullOnDelete();
            }

            $table->text('detail_project')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'invoice_id')) {
                $table->dropConstrainedForeignId('invoice_id');
            }

            // detail_project tetap TEXT: mengecilkannya bisa memotong isi yang sudah ada.
        });
    }
};
