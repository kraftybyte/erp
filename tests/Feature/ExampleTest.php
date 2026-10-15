<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Halaman depan sengaja melempar pengunjung ke panel admin, jadi 302 adalah
     * jawaban yang benar — bukan 200 seperti asumsi tes bawaan Laravel.
     */
    public function test_root_redirects_to_admin_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(Filament::getUrl());
    }

    /**
     * Panel admin harus menolak tamu, bukan menampilkan isinya.
     */
    public function test_admin_panel_requires_authentication(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect();
        $this->assertStringContainsString('login', $response->headers->get('Location') ?? '');
    }
}
