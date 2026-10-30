<?php

namespace Tests\Feature;

use App\Filament\Pages\Setting\ManageGeneral;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fitur Social Media dan Meta Ads sudah dihapus dari ERP. Tabelnya masih ada
 * di database, termasuk baris pengaturan label menunya.
 */
class SocialMediaRemovedTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin());
    }

    public function test_menu_tools_dan_social_media_tidak_tampil(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertDontSee('Meta Ad Account')
            ->assertDontSee('Social Account')
            ->assertDontSee('Connected Account')
            ->assertDontSee('Account Metrics');

        $this->assertFalse(Route::has('auth.instagram.redirect'));
        $this->assertFalse(Route::has('api.connected-accounts.sync'));
    }

    public function test_pengaturan_umum_tetap_terbuka_tanpa_label_menu_social(): void
    {
        // Baris nav_social_account dkk. yang tertinggal di tabel settings diabaikan.
        Livewire::test(ManageGeneral::class)
            ->assertOk()
            ->assertFormFieldExists('nav_event')
            ->assertFormFieldDoesNotExist('nav_social_account')
            ->assertFormFieldDoesNotExist('nav_meta_ad');
    }

    private function superAdmin(): User
    {
        $user = User::forceCreate([
            'username'          => 'sm_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => 'Admin',
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'email_verified_at' => now(),
            'is_active'         => true,
            'push_enabled'      => false,
        ]);
        $user->assignRole('super_admin');

        return $user;
    }
}
