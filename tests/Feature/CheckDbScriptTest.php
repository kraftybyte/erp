<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * scripts/check-db.php dijalankan di server dari luar folder situs; di sini
 * dijalankan terhadap kode lokal, tanpa tes koneksi Gemini.
 */
class CheckDbScriptTest extends TestCase
{
    public function test_skrip_cek_database_menampilkan_semua_bagian(): void
    {
        $result = Process::env(['CEK_DB_SKIP_GEMINI' => '1'])
            ->run([PHP_BINARY, base_path('scripts/check-db.php'), base_path()]);

        $this->assertTrue($result->successful(), $result->errorOutput() . $result->output());

        foreach ([
            '== Migrasi yang belum jalan',
            'Tes koneksi: dilewati',
            '== Advance per status',
            'Terbuka untuk realisasi:',
            '== Quotation',
            '== Penomoran invoice bulan ini',
            '== Pelunasan belum tertaut ke invoice DP',
            '== Pemakaian bot 7 hari terakhir',
            '== Jenis pembayaran invoice',
            '== Status vs nominal (yang ditagih = amountDue)',
            'Kolom dibayar ≠ uang masuk di rekening + potongan:',
            '== DP dan pelunasan',
            'DP lunas, invoice pelunasan belum dibuat:',
            'Total pelunasan berbeda dari total invoice DP-nya',
        ] as $expected) {
            $this->assertStringContainsString($expected, $result->output());
        }
    }

    public function test_tanpa_folder_situs_ditolak(): void
    {
        $result = Process::run([PHP_BINARY, base_path('scripts/check-db.php')]);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('Pemakaian: php check-db.php <folder_situs>', $result->errorOutput());
    }
}
