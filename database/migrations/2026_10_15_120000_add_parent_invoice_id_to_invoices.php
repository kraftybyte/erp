<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan kolom parent_invoice_id pada invoices.
 *
 * Kolom ini menautkan invoice pelunasan ke invoice DP yang mendahuluinya.
 * PDF pelunasan membaca nilai DP dari tautan ini — sebelumnya angkanya
 * di-parse dari teks bebas di kolom notes, yang membuat dokumen ke klien
 * mencetak nilai yang tidak ada hubungannya dengan tagihan sebenarnya.
 *
 * Migration ini dibuat ulang karena berkas aslinya sempat terhapus padahal
 * kolomnya sudah terlanjur dibuat di sebagian lingkungan. Karena itu
 * keberadaan kolom diperiksa dulu: aman dijalankan di database yang sudah
 * punya kolomnya maupun yang belum.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'parent_invoice_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_invoice_id')
                ->nullable()
                ->after('id');

            $table->index('parent_invoice_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoices', 'parent_invoice_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['parent_invoice_id']);
            $table->dropColumn('parent_invoice_id');
        });
    }
};
