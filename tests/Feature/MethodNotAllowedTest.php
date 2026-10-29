<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 405 (metode salah, mis. GET ke alamat yang hanya menerima POST) dicatat di
 * log dan ditampilkan dengan halaman yang bisa dimengerti pengguna.
 */
class MethodNotAllowedTest extends TestCase
{
    public function test_405_dicatat_dan_tampil_dengan_halaman_berbahasa_indonesia(): void
    {
        Log::spy();

        $this->get('/livewire/update?_token=rahasia', ['Referer' => 'https://erp.signcare.id/admin/quotations/create'])
            ->assertStatus(405)
            ->assertSee('Tidak bisa dibuka dengan cara ini')
            ->assertSee('Kembali ke ERP');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === '405 Method Not Allowed'
            && $context['method'] === 'GET'
            && $context['path'] === '/livewire/update'
            && $context['referer'] === 'https://erp.signcare.id/admin/quotations/create')->once();
    }
}
