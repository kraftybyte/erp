<?php

namespace Tests\Feature;

use App\Filament\Pages\Setting\ManageGeneral;
use App\Filament\Resources\ContractResource\Pages\CreateContract;
use App\Filament\Resources\ContractResource\Pages\EditContract;
use App\Models\Contract;
use App\Models\User;
use App\Services\ContractTemplateService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Penanda tangan Pihak Pertama (perusahaan kita) di kontrak: bisa diganti per
 * kontrak, bawaannya dari Settings → Contract Template, lalu penanda tangan
 * invoice.
 */
class ContractSignatoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin());
        $this->defaults(name: '', title: '');
    }

    public function test_edit_kontrak_mengganti_penanda_tangan_pihak_pertama(): void
    {
        $contract = $this->contract();

        Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
            ->assertFormFieldExists('agency_signatory_name')
            ->fillForm(['agency_signatory_name' => 'Rudi Hartono', 'agency_signatory_title' => 'Direktur Utama'])
            ->call('save')
            ->assertHasNoFormErrors();

        $contract->refresh();
        $this->assertSame(['Rudi Hartono', 'Direktur Utama'], [$contract->agency_signatory_name, $contract->agency_signatory_title]);

        $html = app(ContractTemplateService::class)->generate($contract);
        $this->assertStringContainsString('diwakili oleh <strong>Rudi Hartono</strong> dalam kedudukannya selaku <strong>Direktur Utama</strong>', $html);
        $this->assertSame(2, substr_count($html, 'Rudi Hartono'), 'Di pembukaan dan di kolom tanda tangan.');
    }

    public function test_pratinjau_mengikuti_penanda_tangan_yang_belum_disimpan(): void
    {
        $contract = $this->contract(['agency_signatory_name' => 'Nama Lama']);

        $html = Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
            ->fillForm(['agency_signatory_name' => 'Nama Baru'])
            ->instance()
            ->contractPreviewHtml();

        $this->assertStringContainsString('Nama Baru', $html);
        $this->assertStringNotContainsString('Nama Lama', $html);
    }

    public function test_kontrak_baru_diisi_bawaan_dari_pengaturan(): void
    {
        $this->defaults(name: 'Sari Dewi', title: 'Chief Executive Officer');

        Livewire::test(CreateContract::class)
            ->assertFormSet(['agency_signatory_name' => 'Sari Dewi', 'agency_signatory_title' => 'Chief Executive Officer']);

        // Kontrak lama yang belum mengisi sendiri memakai bawaan saat dokumen dibuat.
        $html = app(ContractTemplateService::class)->generate($this->contract());
        $this->assertStringContainsString('diwakili oleh <strong>Sari Dewi</strong> dalam kedudukannya selaku <strong>Chief Executive Officer</strong>', $html);
    }

    public function test_tanpa_pengaturan_kontrak_memakai_penanda_tangan_invoice(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->inv_signature_name = 'Madita Tes';
        $settings->save();

        $html = app(ContractTemplateService::class)->generate($this->contract(['agency_signatory_name' => '', 'agency_signatory_title' => '']));

        $this->assertStringContainsString('diwakili oleh <strong>Madita Tes</strong> dalam kedudukannya selaku <strong>Direksi</strong>', $html);
    }

    public function test_bawaan_ada_di_pengaturan_contract_template(): void
    {
        // Tanpa save: simpan di halaman ini ikut menulis ulang file tema.
        Livewire::test(ManageGeneral::class)
            ->assertFormFieldExists('contract_signature_name')
            ->assertFormFieldExists('contract_signature_title');
    }

    private function defaults(string $name, string $title): void
    {
        $settings = app(GeneralSettings::class);
        $settings->contract_signature_name  = $name;
        $settings->contract_signature_title = $title;
        $settings->save();
    }

    private function contract(array $attributes = []): Contract
    {
        return Contract::create($attributes + [
            'contract_number'  => 'TES/' . Str::random(6),
            'client_name_full' => 'PT Uji Penanda Tangan',
            'effective_date'   => now(),
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::forceCreate([
            'username'          => 'cs_' . Str::lower(Str::random(8)),
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
