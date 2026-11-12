<?php

namespace Tests\Feature;

use App\Filament\Resources\ContractResource\Pages\CreateContract;
use App\Filament\Resources\ContractResource\Pages\EditContract;
use App\Helpers\Terbilang;
use App\Models\Contract;
use App\Models\User;
use App\Services\ContractFormats;
use App\Services\ContractTemplateService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Format kontrak PKS: pembukaan dengan kota dan dasar kewenangan, 13 pasal,
 * tanda tangan dengan jabatan, Lampiran 1 ruang lingkup, Lampiran 2 dengan
 * catatan, format nomor; semuanya bisa diubah. Data contoh fiktif.
 */
class ContractPksFormatTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin());

        $this->settings([
            'inv_company_name'            => 'PT Kreasi Nusa Contoh',
            'inv_company_address'         => 'Jl. Contoh Raya No. 1',
            'inv_bank_name'               => 'Bank Contoh',
            'inv_bank_account'            => '000 111 222',
            'inv_bank_account_name'       => 'PT Kreasi Nusa Contoh',
            'contract_city'               => 'Kota Contoh',
            'contract_agency_legal_basis' => 'Akta Pendirian No. 1 dan SK Kemenkumham No. AHU-0001',
            'contract_signature_name'     => 'Budi Contoh',
            'contract_signature_title'    => 'Direktur Utama',
        ]);
    }

    public function test_kontrak_pks_tampil_seperti_draft(): void
    {
        $contract = Contract::create(ContractFormats::make('pks') + [
            'contract_number'        => '001/PKS/KNC-MDC/XI/2026',
            'client_name_full'       => 'PT Media Data Contoh (MDC)',
            'client_address'         => 'Jl. Klien No. 2',
            'client_signatory_name'  => 'Sari Contoh',
            'client_signatory_title' => 'Direktur Utama',
            'client_legal_basis'     => 'Akta Pendirian No. 2 dan SK Kemenkumham No. AHU-0002',
            'project_description'    => 'pengelolaan media sosial dan konten website contoh.id',
            'effective_date'         => '2026-11-01',
            'expiration_date'        => '2027-11-01',
            'duration_months'        => 12,
            'payment_type'           => 'monthly',
            'total_value'            => 66_000_000,
            'down_payment_percent'   => 0,
            'installment_months'     => 12,
        ]);
        $category = $contract->items()->create(['type' => 'sow', 'item' => 'Pengelolaan Media Sosial', 'description' => 'Akun: Instagram @contoh', 'is_category_header' => true, 'sort_order' => 1]);
        $contract->items()->create(['type' => 'sow', 'item' => '15 Feed Image/Carousel', 'description' => 'Diposting di Instagram', 'parent_id' => $category->id, 'sort_order' => 1]);

        $html = app(ContractTemplateService::class)->generate($contract->fresh());
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '</td>', '</th>'], ' ', $html)), ENT_QUOTES));

        foreach ([
            'Nomor: 001/PKS/KNC-MDC/XI/2026',
            'dibuat pada hari Minggu 1 November 2026, di Kota Contoh, oleh dan antara:',
            '1. PT Kreasi Nusa Contoh, perseroan terbatas',
            'diwakili oleh Budi Contoh selaku Direktur Utama, yang berwenang bertindak untuk dan atas nama perseroan tersebut berdasarkan Akta Pendirian No. 1 dan SK Kemenkumham No. AHU-0001',
            'berdasarkan Akta Pendirian No. 2 dan SK Kemenkumham No. AHU-0002. Selanjutnya disebut "Pihak Kedua"',
            'Deskripsi proyek: pengelolaan media sosial dan konten website contoh.id',
            'Jangka waktu layanan adalah 12 (dua belas) bulan, dimulai pada 1 November 2026 dan berakhir pada 1 November 2027',
            'biaya tetap sebesar Rp5.500.000 per bulan, total Rp66.000.000 untuk 12 bulan',
            'Biaya Jasa Belum termasuk PPN. PPN [11%/sesuai ketentuan yang berlaku] ditambahkan pada Invoice.',
            'Para Pihak menempuh mediasi lembaga mediasi yang disepakati',
            'diselesaikan melalui (A) Pengadilan Negeri Kota Contoh / (B) arbitrase BANI di Jakarta',
            'Pasal 12 Pemberitahuan dan Komunikasi Komunikasi operasional sehari-hari',
            'Bank: Bank Contoh; No. Rekening: 000 111 222; Atas nama: PT Kreasi Nusa Contoh',
            'Pasal 13 Ketentuan Lain-lain',
            'Demikian Perjanjian ini dibuat dan ditandatangani',
            'Lampiran 1 Ruang Lingkup Pekerjaan',
            '1. Pengelolaan Media Sosial Akun: Instagram @contoh',
            '1.1 15 Feed Image/Carousel Diposting di Instagram',
            '2. Pengecualian (tidak termasuk dalam Biaya Jasa)',
            'Lampiran 2 Rincian Biaya Jasa',
            'Tabel A. Biaya Jasa selama 12 bulan',
            'Biaya Tetap per Bulan',
            '1 Biaya Jasa per Bulan (12x) Rp5.500.000 Setiap tanggal 25, dibayar di muka Rp66.000.000',
            'TOTAL Rp66.000.000',
            'Catatan: Jumlah di atas belum termasuk PPN.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        $this->assertStringNotContainsString('Biaya yang perlu dibayarkan oleh Pihak Kedua kepada Pihak Pertama', $text, 'Keterangan bawaan Tabel A disembunyikan di format PKS.');
        $this->assertStringNotContainsString('("Tanggal Mulai")', $text, 'Teks mengikuti draft.');
        $this->assertStringNotContainsString('Setiap pemberitahuan resmi', $text, 'Pasal 12 mengikuti draft (2 ayat).');
        $this->assertSame(0, preg_match('/\{[a-z_]+\}/', $html), 'Semua penanda terisi.');
        $this->assertMatchesRegularExpression('/<strong>PIHAK PERTAMA<\/strong>.*?<strong>Budi Contoh<\/strong><\/p>\s*<p style="margin: 0;">Direktur Utama<\/p>/s', $html, 'Nama tebal dan jabatan di bawah tanda tangan.');
        $this->assertStringContainsString('class="lampiran lampiran-continue"', $html, 'Lampiran 2 menyambung Lampiran 1.');
    }

    public function test_angka_di_setiap_pasal_mengikuti_data_kontrak(): void
    {
        $contract = Contract::create(ContractFormats::make('pks') + [
            'contract_number'         => '002/PKS/TES',
            'client_name_full'        => 'PT Ikut Data',
            'effective_date'          => '2026-12-15',
            'expiration_date'         => '2027-06-15',
            'duration_months'         => 6,
            'payment_type'            => 'monthly',
            'total_value'             => 48_000_000,
            'down_payment_percent'    => 50, // bawaan kolom; "Cicilan per Bulan" tidak memakai DP
            'installment_months'      => 6,
            'payment_due_day'         => 10,
            'termination_notice_days' => 14,
        ]);

        $html = app(ContractTemplateService::class)->generate($contract->fresh());
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '</td>', '</th>'], ' ', $html)), ENT_QUOTES));

        foreach ([
            'Jangka waktu layanan adalah 6 (enam) bulan, dimulai pada 15 Desember 2026 dan berakhir pada 15 Juni 2027',
            'biaya tetap sebesar Rp8.000.000 per bulan, total Rp48.000.000 untuk 6 bulan',
            'paling lambat setiap tanggal 10 sebelum Bulan Layanan berikutnya dimulai',
            'Jika tanggal 10 jatuh pada hari bukan Hari Kerja',
            'tidak memperbaikinya dalam 14 (empat belas) Hari Kalender sejak menerima surat peringatan',
            'Tabel A. Biaya Jasa selama 6 bulan',
            '1 Biaya Jasa per Bulan (6x) Rp8.000.000 Setiap tanggal 10, dibayar di muka Rp48.000.000',
            'TOTAL Rp48.000.000',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        $this->assertStringNotContainsString('tanggal 25', $text);
        $this->assertStringNotContainsString('Rp4.000.000', $text, 'DP 50% tersimpan tidak memotong cicilan per bulan.');
    }

    public function test_peringatan_bila_baris_biaya_tidak_sama_dengan_total(): void
    {
        $contract = Contract::create(ContractFormats::make('pks') + [
            'contract_number' => 'TES/' . Str::random(6), 'client_name_full' => 'PT Cek Total', 'effective_date' => now(),
            'payment_type' => 'monthly', 'total_value' => 66_000_000, 'installment_months' => 12, 'duration_months' => 12,
        ]);
        $contract->feeItems()->create(['type' => 'fee', 'item' => 'Biaya Jasa', 'amount' => 5_500_000, 'sort_order' => 1]);

        Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
            ->assertSee('Jumlah baris Tabel A Rp 5.500.000 tidak sama dengan Total Nilai Kontrak Rp 66.000.000')
            ->callFormComponentAction('fee_items', 'fillFeeRowsFromPayment')
            ->assertDontSee('tidak sama dengan Total Nilai Kontrak');
    }

    public function test_kontrak_baru_memakai_format_bawaan_settings(): void
    {
        $this->settings(['contract_default_format' => 'pks']);

        $page = Livewire::test(CreateContract::class);

        $this->assertSame('Nomor: {nomor_kontrak}', $page->get('data.layout.number_line'));
        $this->assertCount(13, $page->get('data.articles'));
        $this->assertSame('Biaya Tetap per Bulan', $page->get('data.table_a_columns.estimate'));

        $this->settings(['contract_default_format' => 'standar']);
        $this->assertSame('Perjanjian Kerja Sama', Livewire::test(CreateContract::class)->get('data.document_title'));
        $this->assertNull(Livewire::test(CreateContract::class)->get('data.layout.number_line'));
    }

    public function test_pakai_format_di_kontrak_yang_ada(): void
    {
        $contract = Contract::create(['contract_number' => 'TES/' . Str::random(6), 'client_name_full' => 'PT Uji Format', 'effective_date' => now()]);

        Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
            ->callFormComponentAction('document_title', 'applyContractFormat', data: ['format' => 'pks'])
            ->assertSet('data.layout.signature_first_label', 'PIHAK PERTAMA')
            ->assertSet('data.table_a_title', 'Tabel A. Biaya Jasa selama {durasi} bulan')
            ->call('save')
            ->assertHasNoFormErrors();

        $contract->refresh();
        $this->assertCount(13, $contract->articles);
        $this->assertTrue($contract->layout['scope_appendix']);
        $this->assertSame('PT Uji Format', $contract->client_name_full, 'Data client tidak berubah.');
    }

    public function test_simpan_kontrak_lama_tidak_mengubah_tampilan_lampiran(): void
    {
        $contract = Contract::create(['contract_number' => 'TES/' . Str::random(6), 'client_name_full' => 'PT Lama', 'effective_date' => now(), 'duration_months' => 6]);

        Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])->call('save')->assertHasNoFormErrors();

        $html = app(ContractTemplateService::class)->generate($contract->fresh());
        $this->assertStringContainsString('Biaya yang perlu dibayarkan oleh Pihak Kedua kepada Pihak Pertama selama 6 bulan', $html);
        $this->assertStringContainsString('<strong>Pihak Pertama,</strong>', $html);
        $this->assertStringNotContainsString('class="lampiran lampiran-continue"', $html);
    }

    public function test_format_nomor_kontrak(): void
    {
        $this->settings(['inv_company_name' => 'PT Sinergi Citra Contoh', 'contract_number_format' => '{no}/PKS/{kode_agensi}-{kode_klien}/{bulan_romawi}/{tahun}']);
        Contract::create(['contract_number' => '041/PKS/LAMA', 'client_name_full' => 'PT Lama', 'effective_date' => now()]);

        $this->assertSame('042/PKS/SCC-CMD/XI/2026', Contract::generateContractNumber('PT Citra Meta Data (CMD)', now()->setDate(2026, 11, 1)));
        $this->assertSame('MDC', Contract::companyCode('CV. Maju Data Cemerlang'));
        $this->assertSame('042/SPK/1126', Contract::generateContractNumber(null, now()->setDate(2026, 11, 1), Contract::DEFAULT_NUMBER_FORMAT), 'Format bawaan sama dengan sebelumnya.');

        // Nomor otomatis di form ikut nama client; yang diketik sendiri tidak diubah.
        $page = Livewire::test(CreateContract::class)->set('data.client_name_full', 'PT Baru Sekali (PBS)');
        $this->assertStringContainsString('-PBS/', $page->get('data.contract_number'));
        $page->set('data.contract_number', 'NOMOR-SAYA')->set('data.client_name_full', 'PT Lain (PLN)');
        $this->assertSame('NOMOR-SAYA', $page->get('data.contract_number'));
    }

    public function test_terbilang(): void
    {
        $this->assertSame('12 (dua belas)', Terbilang::withNumber(12));
        $this->assertSame(['sebelas', 'tiga puluh', 'seratus dua puluh lima', 'seribu lima ratus', 'enam puluh enam juta'],
            array_map([Terbilang::class, 'make'], [11, 30, 125, 1500, 66_000_000]));
    }

    private function settings(array $values): void
    {
        $settings = app(GeneralSettings::class);

        foreach ($values as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function superAdmin(): User
    {
        $user = User::forceCreate([
            'username'          => 'pk_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => 'Pks',
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
