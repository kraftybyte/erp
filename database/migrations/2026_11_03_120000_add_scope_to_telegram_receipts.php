<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Struk realisasi boleh dipakai lagi untuk realisasi advance lain, jadi satu
 * struk bisa punya beberapa baris: unik per (struk, scope), bukan per struk.
 * scope = "pengajuan" (expense/advance) atau "realisasi:{advance_id}".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_receipts', function (Blueprint $table) {
            $table->string('scope', 40)->default('pengajuan')->after('file_unique_id');
        });

        Schema::table('telegram_receipts', function (Blueprint $table) {
            $table->dropUnique(['file_unique_id']);
            $table->unique(['file_unique_id', 'scope']);
        });

        // Struk realisasi lama: advance-nya dicari dari batch realisasi yang
        // dibuat pengirim yang sama pada saat yang sama. Yang tidak ketemu
        // tetap "pengajuan" (tidak boleh dipakai lagi), sama seperti sebelumnya.
        $rows = DB::table('telegram_receipts')->where('reference', 'like', 'Realisasi %')->get();

        foreach ($rows as $row) {
            $at = Carbon::parse($row->created_at);

            $advances = DB::table('realisasi_batches')
                ->where('uploaded_by', $row->user_id)
                ->whereBetween('created_at', [$at->copy()->subSeconds(10), $at->copy()->addSeconds(10)])
                ->distinct()
                ->pluck('advance_id');

            if ($advances->count() === 1) {
                DB::table('telegram_receipts')->where('id', $row->id)->update(['scope' => 'realisasi:' . $advances->first()]);
            }
        }
    }

    public function down(): void
    {
        // Sisakan satu baris per struk supaya kolomnya bisa unik lagi.
        $keep = DB::table('telegram_receipts')->selectRaw('MIN(id)')->groupBy('file_unique_id')->pluck('MIN(id)');
        DB::table('telegram_receipts')->whereNotIn('id', $keep)->delete();

        Schema::table('telegram_receipts', function (Blueprint $table) {
            $table->dropUnique(['file_unique_id', 'scope']);
            $table->unique('file_unique_id');
        });

        Schema::table('telegram_receipts', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
