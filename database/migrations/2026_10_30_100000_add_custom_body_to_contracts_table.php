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
        // Hanya kolom yang belum ada (database lama bisa sudah punya sebagian).
        $columns = [
            'document_title' => fn (Blueprint $table) => $table->string('document_title')->nullable(),
            'opening_text'   => fn (Blueprint $table) => $table->longText('opening_text')->nullable(),
            'articles'       => fn (Blueprint $table) => $table->json('articles')->nullable(),
            'closing_text'   => fn (Blueprint $table) => $table->longText('closing_text')->nullable(),
            'header_image'   => fn (Blueprint $table) => $table->string('header_image')->nullable(),
            'footer_image'   => fn (Blueprint $table) => $table->string('footer_image')->nullable(),
        ];
        $after = Schema::hasColumn('contracts', 'contract_number') ? 'contract_number' : null;

        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('contracts', $name)) {
                Schema::table('contracts', function (Blueprint $table) use ($define, $after) {
                    $column = $define($table);
                    $after && $column->after($after);
                });
            }

            $after = $name;
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['document_title', 'opening_text', 'articles', 'closing_text', 'header_image', 'footer_image']);
        });
    }
};
