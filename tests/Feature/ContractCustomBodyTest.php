<?php

namespace Tests\Feature;

use App\Filament\Pages\Setting\ManageGeneral;
use App\Filament\Resources\ContractResource\Pages\CreateContract;
use App\Filament\Resources\ContractResource\Pages\EditContract;
use App\Models\Contract;
use App\Models\User;
use App\Services\ContractDefaultBody;
use App\Services\ContractTemplateService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Isi kontrak yang bisa diedit (judul, pembukaan, pasal, penutup) dan kop/kaki
 * surat per kontrak atau dari Pengaturan.
 */
class ContractCustomBodyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin());
    }

    public function test_teks_bawaan_yang_bisa_diedit_sama_dengan_template_lama(): void
    {
        $contract = $this->contract([
            'scope_of_work'          => 'Kelola 3 akun Instagram',
            'content'                => "Klausul khusus klien.\nBaris kedua.",
            'penalty_clause'         => 'Denda 1% per hari.',
            'force_majeure_extended' => true,
            'project_description'    => 'Digital Ads Funnel',
        ]);

        $edited = (clone $contract)->forceFill(ContractDefaultBody::make($contract));

        $this->assertSame($this->text($this->render($contract)), $this->text($this->render($edited)));
    }

    public function test_kontrak_lama_dibuka_di_edit_dengan_teks_bawaan(): void
    {
        $contract = $this->contract(['penalty_clause' => 'Denda 1% per hari.']);

        $page = Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()]);

        $this->assertCount(11, $page->get('data.articles'));
        $this->assertSame($this->text($this->render($contract)), $this->text($page->instance()->contractPreviewHtml()));
    }

    public function test_pasal_diedit_bernomor_urut_dan_penanda_diisi(): void
    {
        Livewire::test(CreateContract::class)
            ->fillForm([
                'client_name_full' => 'PT Penanda',
                'duration_months'  => 6,
                'document_title'   => 'Perjanjian Jasa Konten',
                'articles'         => [
                    ['title' => 'Ruang Kerja', 'content' => '<div>Untuk <strong>{nama_klien}</strong> selama {durasi} bulan.</div>'],
                    ['title' => '', 'content' => ''],
                    ['title' => 'Biaya', 'content' => '<div>Sesuai Lampiran.</div>'],
                ],
                'closing_text' => '<div>Ditandatangani di Jakarta.</div>',
                'fee_items'    => [['item' => 'Termin 1', 'amount' => 1_000_000]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contract = Contract::where('client_name_full', 'PT Penanda')->firstOrFail();
        $html     = $this->render($contract);

        $this->assertCount(3, $contract->articles);
        $this->assertStringContainsString('Perjanjian Jasa Konten', $html);
        $this->assertStringContainsString('Pasal 1 <br> Ruang Kerja', $html);
        $this->assertStringContainsString('Untuk <strong>PT Penanda</strong> selama 6 bulan.', $html);
        $this->assertStringContainsString('Pasal 2 <br> Biaya', $html);     // pasal kosong dilewati
        $this->assertStringNotContainsString('Pasal 3', $html);
        $this->assertStringContainsString('Ditandatangani di Jakarta.', $html);
        $this->assertStringNotContainsString('{nama_klien}', $html);
    }

    public function test_isi_kontrak_dibersihkan_dari_skrip(): void
    {
        $contract = $this->contract(['articles' => [
            ['title' => 'Uji', 'content' => '<div>Aman<script>alert(1)</script><img src="x" onerror="alert(1)"></div>'],
        ]]);

        $html = $this->render($contract);

        $this->assertStringContainsString('Aman', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_kop_surat_dari_kontrak_lalu_pengaturan_lalu_bawaan(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        foreach (['contracts/letterhead/milik-kontrak.png', 'contract/letterhead/default.png', 'sites/header.png'] as $path) {
            $disk->put($path, 'png');
        }

        $own = $this->contract(['header_image' => 'contracts/letterhead/milik-kontrak.png']);
        $this->assertStringContainsString($disk->path('contracts/letterhead/milik-kontrak.png'), $this->render($own));

        $settings = app(GeneralSettings::class);
        $settings->contract_header = 'contract/letterhead/default.png';
        $settings->save();

        $plain = $this->contract();
        $this->assertStringContainsString($disk->path('contract/letterhead/default.png'), $this->render($plain));

        $settings->contract_header = null;
        $settings->save();
        $this->assertStringContainsString($disk->path('sites/header.png'), $this->render($plain));

        // Tidak ada gambar sama sekali: tanpa kop, bukan gambar rusak.
        $disk->delete('sites/header.png');
        $this->assertStringNotContainsString('class="header"', $this->render($plain));
    }

    public function test_kop_default_diatur_di_pengaturan(): void
    {
        Livewire::test(ManageGeneral::class)
            ->assertFormFieldExists('contract_header')
            ->assertFormFieldExists('contract_footer');
    }

    // =================================================================
    // Helper
    // =================================================================

    private function render(Contract $contract): string
    {
        return app(ContractTemplateService::class)->generate($contract);
    }

    /** Teks yang terbaca di dokumen, tanpa tag dan spasi berlebih. */
    private function text(string $html): string
    {
        $html = preg_replace('/<style.*?<\/style>/s', '', $html);
        $html = preg_replace('/<(br|\/li|\/div|\/p|\/tr|\/td|\/th)[^>]*>/i', ' ', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }

    private function contract(array $attributes = []): Contract
    {
        return Contract::create($attributes + [
            'contract_number'  => 'TES/' . Str::random(6),
            'client_name_full' => 'PT Klien Uji',
            'effective_date'   => now(),
            'duration_months'  => 3,
            'total_value'      => 10_000_000,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::forceCreate([
            'username'          => 'cb_' . Str::lower(Str::random(8)),
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
