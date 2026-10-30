<?php

namespace Tests\Feature;

use App\Filament\Resources\ContractResource\Pages\CreateContract;
use App\Filament\Resources\ContractResource\Pages\EditContract;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\User;
use App\Services\ContractTemplateService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pratinjau kontrak di samping form Buat/Edit: mengikuti isian yang belum
 * disimpan, dan tidak menyimpan apa pun.
 */
class ContractPreviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin());
    }

    public function test_pratinjau_buat_kontrak_mengikuti_isian_tanpa_menyimpan(): void
    {
        $before = [Contract::count(), ContractItem::count()];

        $page = Livewire::test(CreateContract::class)
            ->fillForm([
                'client_name_full' => 'PT Uji Pratinjau',
                'total_value'      => 12_500_000,
                'sow_categories'   => [
                    ['item' => 'Social Media Management', 'children' => [['item' => 'Produksi 20 materi', 'description' => 'Feed dan story']]],
                    ['item' => '', 'children' => []],
                ],
                'fee_items' => [['item' => 'Termin pertama', 'amount' => 5_000_000, 'due_date' => '1 Nov 2026']],
            ])
            ->assertOk()
            ->assertSee('Preview kontrak');

        $html = $page->instance()->contractPreviewHtml();

        foreach (['PT Uji Pratinjau', 'Social Media Management', 'Produksi 20 materi', 'Feed dan story', 'Termin pertama', 'Rp 5.000.000', 'Rp 12.500.000'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame($before, [Contract::count(), ContractItem::count()]);
    }

    public function test_pratinjau_edit_menampilkan_perubahan_yang_belum_disimpan(): void
    {
        $contract = Contract::create(['contract_number' => 'TES/' . Str::random(6), 'client_name_full' => 'PT Nama Lama', 'effective_date' => now()]);

        $html = Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
            ->fillForm(['client_name_full' => 'PT Nama Baru'])
            ->instance()
            ->contractPreviewHtml();

        $this->assertStringContainsString('PT Nama Baru', $html);
        $this->assertStringNotContainsString('PT Nama Lama', $html);
        $this->assertSame('PT Nama Lama', $contract->fresh()->client_name_full);
    }

    public function test_angka_yang_belum_valid_tidak_merusak_pratinjau(): void
    {
        $html = Livewire::test(CreateContract::class)
            ->fillForm([
                'client_name_full' => 'PT Isian Setengah',
                'total_value'      => 'abc',
                'fee_items'        => [['item' => 'Termin', 'amount' => '5 juta', 'due_date' => '']],
            ])
            ->assertOk()
            ->instance()
            ->contractPreviewHtml();

        $this->assertStringContainsString('PT Isian Setengah', $html);
    }

    public function test_kontrak_berupa_file_tanpa_pratinjau(): void
    {
        $contract = Contract::create(['contract_number' => 'TES/' . Str::random(6), 'type' => 'file', 'effective_date' => now()]);

        Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
            ->assertOk()
            ->assertDontSee('Preview kontrak');
    }

    public function test_html_pratinjau_memuat_kop_lewat_url_bukan_path_file(): void
    {
        $html = app(ContractTemplateService::class)->preview(new Contract(['client_name_full' => 'PT Kop']));

        $this->assertStringNotContainsString(Storage::path('sites/'), $html);
        $this->assertStringContainsString('src="/storage/sites/header.png"', $html);
        $this->assertStringContainsString('@media screen', $html);
    }

    private function superAdmin(): User
    {
        $user = User::forceCreate([
            'username'          => 'ct_' . Str::lower(Str::random(8)),
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
