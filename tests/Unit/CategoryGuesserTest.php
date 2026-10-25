<?php

namespace Tests\Unit;

use App\Services\Telegram\CategoryGuesser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CategoryGuesserTest extends TestCase
{
    #[DataProvider('pesan')]
    public function test_menebak_kategori_dari_kata_kunci(string $pesan, ?string $harapan): void
    {
        $this->assertSame($harapan, CategoryGuesser::guess($pesan));
    }

    public static function pesan(): array
    {
        return [
            'grab ke kantor klien'     => ['expense 150rb grab ke kantor klien nestory', 'Transport'],
            'bensin'                   => ['Bensin 80rb ke lokasi', 'Transport'],
            'e-toll bertanda hubung'   => ['isi e-toll 100rb', 'Transport'],
            'kuota'                    => ['beli kuota internet 120rb', 'Komunikasi'],
            'langganan canva'          => ['langganan canva pro 1 bulan', 'Software'],
            'iklan meta'               => ['top up meta ads 500rb', 'Marketing'],
            'makan dengan klien'       => ['makan klien di senayan', 'Entertainment'],
            'atk'                      => ['beli ATK kertas dan tinta', 'Operasional Kantor'],
            'sewa kamera shooting'     => ['sewa kamera untuk shooting', 'Project Support'],
            'grab food bukan transport' => ['grab food lembur tim', null],
            'tol bukan toleransi'      => ['toleransi deadline', null],
            'tanpa kata kunci'         => ['beli sesuatu 50rb', null],
        ];
    }
}
