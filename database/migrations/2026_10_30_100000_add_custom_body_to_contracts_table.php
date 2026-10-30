<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Isi kontrak yang bisa diedit per kontrak (judul, pembukaan, pasal, penutup)
     * dan kop/kaki suratnya. Kosong = template bawaan, jadi kontrak lama tidak berubah.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('document_title')->nullable()->after('contract_number');
            $table->longText('opening_text')->nullable()->after('document_title');
            $table->json('articles')->nullable()->after('opening_text');
            $table->longText('closing_text')->nullable()->after('articles');
            $table->string('header_image')->nullable()->after('closing_text');
            $table->string('footer_image')->nullable()->after('header_image');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['document_title', 'opening_text', 'articles', 'closing_text', 'header_image', 'footer_image']);
        });
    }
};
