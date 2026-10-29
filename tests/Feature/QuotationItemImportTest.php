<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\QuotationResource\Pages\CreateQuotation;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ItemImportTemplate;
use App\Services\QuotationItemImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Item quotation dari file budget (format NO | ITEMS | PRICE/QTY | QTY | UNIT |
 * DUR | UNIT | TOTAL | USD RATE | IN TOTAL USD).
 */
class QuotationItemImportTest extends TestCase
{
    use DatabaseTransactions;

    private array $paths = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->paths);

        parent::tearDown();
    }

    public function test_membaca_format_budget(): void
    {
        $result = app(QuotationItemImport::class)->parse($this->budget());

        $this->assertCount(6, $result['items']);
        $this->assertEquals(187_000_000, $result['total']);
        $this->assertEquals(187_000_000, $result['expected_total']);

        [$prep, $column, $gypsum, $door, $rent, $fee] = $result['items'];

        // Judul dokumen di atas baris NO tidak ikut menjadi kategori.
        $this->assertSame(['Work Preparation, Planning and Management', 'WORK PREPARATION', null], [$prep['name'], $prep['section'], $prep['subsection']]);
        $this->assertEquals([2, 28_000_000, 56_000_000], [$column['qty'], $column['price'], $column['total']]);

        // Kategori bertingkat + baris spesifikasi di bawah item.
        $this->assertSame(['PARTITION', 'RIGHT PARTS', ['Hollow 4x4 + 9mm gypsum']], [$gypsum['section'], $gypsum['subsection'], $gypsum['details']]);
        $this->assertSame(['REAR PARTS', ['plywood 9mm Fin.Kertasive']], [$door['subsection'], $door['details']]);

        // QTY × DUR.
        $this->assertEquals([12, 3_000_000, 36_000_000], [$rent['qty'], $rent['price'], $rent['total']]);

        // Harga × qty tidak sama dengan total di file: total yang dipakai, sebagai satu paket.
        $this->assertEquals([1, 10_000_000, 10_000_000], [$fee['qty'], $fee['price'], $fee['total']]);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Electrical Fee', $result['warnings'][0]);
    }

    public function test_setiap_baris_satu_item_dengan_kategori_sebagai_nama(): void
    {
        $import = app(QuotationItemImport::class);
        $items  = $import->lines($import->parse($this->budget())['items']);

        $this->assertSame('WORK PREPARATION', $items[0]['item']);
        $this->assertSame(['Work Preparation, Planning and Management'], $items[0]['description_lines']);

        $this->assertSame('PARTITION · RIGHT PARTS', $items[2]['item']);
        $this->assertSame('Single-sided gypsum partition<br>Hollow 4x4 + 9mm gypsum', $items[2]['description']);

        $this->assertSame('RENT AND OPERATIONAL COST', $items[4]['item']);
        $this->assertSame(['Rent — 12 Months', '1 SET × 12 Month'], $items[4]['description_lines']);
        $this->assertEquals([12, 3_000_000], [$items[4]['qty'], $items[4]['price']]);
    }

    public function test_satu_item_per_kategori_dengan_rincian_di_deskripsi(): void
    {
        $import = app(QuotationItemImport::class);
        $items  = $import->grouped($import->parse($this->budget())['items']);

        $this->assertSame(['WORK PREPARATION', 'PARTITION', 'RENT AND OPERATIONAL COST'], array_column($items, 'item'));
        $this->assertEquals([96_000_000, 45_000_000, 46_000_000], array_column($items, 'price'), 'Harga = sub total kategori.');
        $this->assertEquals([1, 1, 1], array_column($items, 'qty'));

        $this->assertSame([
            '- Work Preparation, Planning and Management',
            '- Stainless steel hairline-finish column (2 SET)',
        ], $items[0]['description_lines']);

        $this->assertSame(
            '<strong>RIGHT PARTS</strong><br>- Single-sided gypsum partition — Hollow 4x4 + 9mm gypsum'
            . '<br><strong>REAR PARTS</strong><br>- Concealed storage door 100x225 cm — plywood 9mm Fin.Kertasive',
            $items[1]['description']
        );

        $this->assertContains('- Rent — 12 Months (1 SET × 12 Month)', $items[2]['description_lines']);
    }

    public function test_sub_total_yang_tidak_cocok_diperingatkan(): void
    {
        $result = app(QuotationItemImport::class)->parse($this->budget(subtotal: 99_000_000));

        $this->assertNotEmpty(array_filter($result['warnings'], fn ($w) => str_contains($w, 'Sub total WORK PREPARATION')));
    }

    public function test_csv_sederhana_dengan_angka_indonesia(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'quo-') . '.csv';
        $this->paths[] = $path;
        file_put_contents($path, implode("\n", [
            'Item;Deskripsi;Qty;Harga',
            'Booth 3x3;Rangka besi + cetak;2;"Rp 12.500.000"',
            'Instalasi;;1;"1.750.000,50"',
        ]));

        $import = app(QuotationItemImport::class);
        $items  = $import->lines($import->parse($path)['items']);

        // Tanpa kategori: nama item dari barisnya sendiri.
        $this->assertCount(2, $items);
        $this->assertSame('Booth 3x3', $items[0]['item']);
        $this->assertSame(['Rangka besi + cetak'], $items[0]['description_lines']);
        $this->assertEquals(25_000_000, $items[0]['total']);
        $this->assertEquals(1_750_000.5, $items[1]['price']);
    }

    public function test_tabel_nama_barang_dengan_keterangan_berpoin(): void
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['No', 'Nama Barang', 'Keterangan', 'Kts.', '@Harga', 'Total Harga'],
            [1, 'Sandwich 60x70x15 cm 2 sisi', "• ACP SEVENHR 4mm biru + abu\n• HOLO 2x2 + 3x3 finish cat", 1, 5_000_000, 5_000_000],
            [2, 'Signboard 250x100x2.5 cm', "• ACP SEVENHR 4mm biru + abu\n• finish cutting akrilik 3mm", 38, 5_250_000, null],
            // Label TOTAL di kolom @Harga, bukan di kolom nama.
            [null, null, null, null, 'TOTAL', null],
        ], null, 'A1', true);
        $sheet->setCellValue('F3', '=D3*E3');
        $sheet->setCellValue('F4', '=SUM(F2:F3)');

        $path = tempnam(sys_get_temp_dir(), 'quo-') . '.xlsx';
        $this->paths[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $import = app(QuotationItemImport::class);
        $result = $import->parse($path);

        $this->assertCount(2, $result['items'], 'Baris TOTAL bukan item.');
        $this->assertEquals(204_500_000, $result['total']);
        $this->assertEquals(204_500_000, $result['expected_total']);
        $this->assertSame([], $result['warnings']);

        $items = $import->lines($result['items']);
        $this->assertSame('Sandwich 60x70x15 cm 2 sisi', $items[0]['item']);
        $this->assertSame(['ACP SEVENHR 4mm biru + abu', 'HOLO 2x2 + 3x3 finish cat'], $items[0]['description_lines'], 'Satu baris per poin, tanpa tanda poin.');
        $this->assertEquals([38, 5_250_000, 199_500_000], [$items[1]['qty'], $items[1]['price'], $items[1]['total']]);
    }

    public function test_format_harga_jual_dengan_penanda_dan_sub_bagian(): void
    {
        $import = app(QuotationItemImport::class);
        $result = $import->parse($this->hargaJual());

        $this->assertEquals(167_157_000, $result['total']);
        $this->assertEquals(167_157_000, $result['expected_total']);
        $this->assertSame([
            '"Logo KT&G" tidak punya harga di file; diimpor dengan harga Rp 0. Isi harganya atau hapus barisnya.',
            '"Lightbox Launge": Rp 8.568.385 × 1 = Rp 8.568.385, tapi total di file Rp 14.157.000. Dipakai Rp 14.157.000 (qty 1).',
            'Tidak diimpor (tanpa harga, di luar tabel item): "Pekerjaan Renovasi", "Lingkup pekerjaan antara lain :".',
        ], $result['warnings'], 'Sub total per bagian cocok; judul di atas tabel dilaporkan.');

        $items = $import->lines($result['items']);

        $this->assertSame([
            'Persiapan Pekerjaan', 'Persiapan Pekerjaan',
            'Pekerjaan Shopfront/Area Depan', 'Pekerjaan Shopfront/Area Depan', 'Pekerjaan Shopfront/Area Depan',
            'Pekerjaan Partisi · Sisi Kanan', 'Pekerjaan Partisi · Sisi Kiri',
        ], array_column($items, 'item'));

        // Harga "By Owner": tetap jadi item, harga 0, keterangannya ikut.
        $this->assertSame(['Listrik & air kerja', 'By Owner'], $items[1]['description_lines']);
        $this->assertEquals([1, 0, 0], [$items[1]['qty'], $items[1]['price'], $items[1]['total']]);

        // Penanda "1" tidak menjadi nama; baris spesifikasi masuk ke item di atasnya.
        $this->assertSame(['Facade Logo', 'Hollow 4x4 t=1,2 Fin. ACP Seven Outdoor Silver'], $items[2]['description_lines']);

        // Satuan ikut tercatat.
        $this->assertSame(['Kolom Stainless Hairline', '2 ls'], $items[3]['description_lines'], 'Baris bernomor tanpa harga di bawahnya tidak menempel ke item ini.');
        $this->assertEquals([2, 18_000_000, 36_000_000], [$items[3]['qty'], $items[3]['price'], $items[3]['total']]);

        // Item bernomor tanpa harga tetap item sendiri, harga 0.
        $this->assertSame(['Logo KT&G'], $items[4]['description_lines']);
        $this->assertEquals([1, 0, 0], [$items[4]['qty'], $items[4]['price'], $items[4]['total']]);
    }

    public function test_sub_total_di_kolom_no_yang_tidak_cocok_diperingatkan(): void
    {
        $result = app(QuotationItemImport::class)->parse($this->hargaJual(shopfrontTotal: 90_000_000));

        $this->assertContains('Total Harga Pekerjaan Shopfront di file Rp 90.000.000, jumlah itemnya Rp 91.000.000.', $result['warnings']);
    }

    public function test_template_import_bisa_diunduh_dan_contohnya_terbaca(): void
    {
        $this->actingAs($this->importer());

        $response = $this->get(route('import-template.items', 'quotation'))->assertOk()->assertDownload('Template Import Item Quotation.xlsx');

        $path  = $response->baseResponse->getFile()->getPathname();
        $book  = IOFactory::load($path);
        $this->assertSame(['Item Quotation', 'Contoh', 'Petunjuk'], $book->getSheetNames());
        $this->assertSame('Item Quotation', $book->getActiveSheet()->getTitle(), 'Sheet isian yang dibaca importer.');
        $this->assertSame(ItemImportTemplate::HEADERS, $book->getActiveSheet()->rangeToArray('A1:F1')[0]);

        // Sheet contoh, bila diimpor, menghasilkan item yang sama persis dengan isinya.
        $book->setActiveSheetIndexByName('Contoh');
        $contoh = tempnam(sys_get_temp_dir(), 'quo-') . '.xlsx';
        $this->paths[] = $contoh;
        (new Xlsx($book))->save($contoh);
        $book->disconnectWorksheets();
        @unlink($path);

        $import = app(QuotationItemImport::class);
        $result = $import->parse($contoh);
        $this->assertSame([], $result['warnings']);
        $this->assertEquals([137_000_000, 137_000_000], [$result['total'], $result['expected_total']]);

        $items = $import->lines($result['items']);
        $this->assertSame(['Persiapan Pekerjaan', 'Persiapan Pekerjaan', 'Pekerjaan Shopfront', 'Pekerjaan Furniture', 'Pekerjaan Elektrikal'], array_column($items, 'item'));
        $this->assertSame(['Listrik & air kerja', 'By Owner'], $items[1]['description_lines']);
        $this->assertEquals(0, $items[1]['price']);
        $this->assertSame(['Facade Logo', 'Hollow 4x4 t=1,2 Fin. ACP Seven Outdoor Silver'], $items[2]['description_lines']);
        $this->assertSame(['Sofa 3 seater', '2 Unit'], $items[3]['description_lines']);
        $this->assertEquals([2, 13_000_000, 26_000_000], [$items[3]['qty'], $items[3]['price'], $items[3]['total']]);
    }

    public function test_template_invoice_mengingatkan_harga_penuh_untuk_dp(): void
    {
        $this->actingAs($this->importer());

        $response = $this->get(route('import-template.items', 'invoice'))->assertOk()->assertDownload('Template Import Item Invoice.xlsx');

        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        @unlink($path);

        $this->assertSame(['Item Invoice', 'Contoh', 'Petunjuk'], $book->getSheetNames());
        $this->assertSame(ItemImportTemplate::HEADERS, $book->getActiveSheet()->rangeToArray('A1:F1')[0]);

        $guide = implode("\n", array_merge(...$book->getSheetByName('Petunjuk')->toArray()));
        $this->assertStringContainsString('Untuk invoice DP tetap isi harga penuh', $guide);
        $this->assertStringContainsString('Isi di sheet "Item Invoice"', $guide);
        $book->disconnectWorksheets();

        $this->get('/import-template/kontrak')->assertNotFound();
    }

    public function test_jendela_import_menautkan_template_masing_masing(): void
    {
        $this->actingAs($this->importer());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateQuotation::class)
            ->call('mountFormComponentAction', 'itemsSection', 'importItems')
            ->assertSee('Unduh template Excel')
            ->assertSee(route('import-template.items', 'quotation'));

        Livewire::test(CreateInvoice::class)
            ->call('mountFormComponentAction', 'itemsSection', 'importItems')
            ->assertSee(route('import-template.items', 'invoice'));
    }

    private function importer(): User
    {
        $user = User::forceCreate([
            'username'     => 'tpl_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Template',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_tombol_import_mengisi_form_quotation(): void
    {
        $user = User::forceCreate([
            'username'     => 'quoimp_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Import',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $file = UploadedFile::fake()->createWithContent('budget.xlsx', file_get_contents($this->budget()));

        // Seperti browser: tombol ada di header Section "Items" (key itemsSection).
        $component = Livewire::test(CreateQuotation::class)
            ->call('mountFormComponentAction', 'itemsSection', 'importItems')
            ->set('mountedFormComponentActionsData.0.file', $file)
            ->set('mountedFormComponentActionsData.0.vat_percentage', 11)
            ->call('callMountedFormComponentAction')
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['subtotal' => 187_000_000, 'total_vat' => 20_570_000, 'grand_total' => 207_570_000]);

        $this->assertCount(6, $component->get('data.items'));

        $client = 'PT Import ' . Str::upper(Str::random(5));
        $component->fillForm(['nama_perusahaan' => $client])->call('create')->assertHasNoFormErrors();

        $quotation = Quotation::where('nama_perusahaan', $client)->sole();
        $this->assertCount(6, $quotation->items);
        $this->assertEquals(207_570_000, $quotation->grand_total);
        $rent = $quotation->items->firstWhere('qty', 12);
        $this->assertSame('RENT AND OPERATIONAL COST', $rent->item);
        $this->assertStringContainsString('Rent — 12 Months', $rent->description);

        $gypsum = $quotation->items->firstWhere('price', 35_000_000);
        $this->assertSame('PARTITION · RIGHT PARTS', $gypsum->item);
        $this->assertSame('Single-sided gypsum partition<br>Hollow 4x4 + 9mm gypsum', $gypsum->description);
    }

    public function test_tombol_import_bisa_satu_item_per_kategori(): void
    {
        $user = User::forceCreate([
            'username'     => 'quoimp_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Import',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(CreateQuotation::class)
            ->call('mountFormComponentAction', 'itemsSection', 'importItems')
            ->set('mountedFormComponentActionsData.0.file', UploadedFile::fake()->createWithContent('budget.xlsx', file_get_contents($this->budget())))
            ->set('mountedFormComponentActionsData.0.layout', 'category')
            ->call('callMountedFormComponentAction')
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['grand_total' => 187_000_000]);

        $items = array_values($component->get('data.items'));
        $this->assertSame(['WORK PREPARATION', 'PARTITION', 'RENT AND OPERATIONAL COST'], array_column($items, 'item'));
        $this->assertEquals(45_000_000, $items[1]['total']);
    }

    public function test_tombol_import_juga_ada_di_form_invoice(): void
    {
        $user = User::forceCreate([
            'username'     => 'invimp_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Import',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(CreateInvoice::class)
            ->set('data.type_payment', 'dp_50')
            ->call('mountFormComponentAction', 'itemsSection', 'importItems')
            ->set('mountedFormComponentActionsData.0.file', UploadedFile::fake()->createWithContent('budget.xlsx', file_get_contents($this->budget())))
            ->call('callMountedFormComponentAction')
            ->assertHasNoFormComponentActionErrors()
            // Harga penuh walau invoice DP: DP-nya dihitung dari Total × 50%.
            ->assertFormSet(['subtotal' => 187_000_000, 'grand_total' => 187_000_000]);

        $items = array_values($component->get('data.invoicesItems'));
        $this->assertCount(6, $items);
        $this->assertSame('WORK PREPARATION', $items[0]['item']);
        $this->assertEquals(40_000_000, $items[0]['original_price']);
        $this->assertEquals([12, 3_000_000], [$items[4]['qty'], $items[4]['price']]);

        $client = 'PT Invoice Import ' . Str::upper(Str::random(5));
        $component->fillForm(['nama_perusahaan' => $client])->call('create')->assertHasNoFormErrors();

        $invoice = \App\Models\Invoice::where('nama_perusahaan', $client)->sole();
        $this->assertSame($user->id, $invoice->user_id, 'Pembuat invoice tercatat (dulu kosong sehingga simpan gagal).');
        $this->assertCount(6, $invoice->invoicesItems);
        $this->assertEquals(187_000_000, $invoice->grand_total);
        $this->assertEquals(93_500_000, $invoice->amountDue(), 'Amount Due DP 50% dari nilai penuh.');
    }

    /**
     * Format "Harga Jual": NO | JENIS PEKERJAAN (B:D digabung, dengan penanda
     * A / 1 / - di depan) | QTY | Satuan | Harga Satuan | Total Harga; sub total
     * berlabel di kolom NO.
     */
    private function hargaJual(int $shopfrontTotal = 91_000_000): string
    {
        $rows = [
            [null, 'Pekerjaan Renovasi'],
            [null, 'Lingkup pekerjaan antara lain :'],
            [null, ' - ', 'Persiapan Pekerjaan'],
            [null, null, ' - ', 'Mobilisasi dan akomodasi', 1, 'ls', 40_000_000, 40_000_000],
            [null, null, ' - ', 'Listrik & air kerja', 1, 'ls', 'By Owner', 'By Owner'],
            [' ', null, null, null, null, null, null, 40_000_000],
            [],
            [null, 'A', 'Pekerjaan Shopfront/Area Depan'],
            [null, null, 1, 'Facade Logo', 1, 'ls', 55_000_000, 55_000_000],
            [null, null, null, ' - Hollow 4x4 t=1,2 Fin. ACP Seven Outdoor Silver'],
            [null, null, 2, 'Kolom Stainless Hairline', 2, 'ls', 18_000_000, 36_000_000],
            // Harganya dihapus di file, barisnya tetap.
            [null, null, 3, 'Logo KT&G', 1, 'ls'],
            ['Total Harga Pekerjaan Shopfront ', null, null, null, null, null, null, $shopfrontTotal],
            [],
            [null, 'C', 'Pekerjaan Partisi'],
            [null, null, null, 'Sisi Kanan'],
            [null, null, 1, 'Video tron P2.5', 1, 'ls ', 22_000_000, 22_000_000],
            [],
            [null, null, null, 'Sisi Kiri'],
            [null, null, 1, 'Lightbox Launge', 1, 'ls', 8_568_385, 14_157_000],
            ['Total Harga Pekerjaan Partisi', null, null, null, null, null, null, 36_157_000],
            ['Grand Total', null, null, null, null, null, null, 167_157_000],
        ];

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['NO', 'JENIS PEKERJAAN', null, null, 'QTY', 'Satuan', 'Harga Satuan', 'Total Harga'], null, 'A2');
        $sheet->mergeCells('B2:D2');
        $sheet->fromArray($rows, null, 'A4', true);

        $path = tempnam(sys_get_temp_dir(), 'quo-') . '.xlsx';
        $this->paths[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /**
     * File seperti budget asli: judul kolom digabung di atas kolom "Rp" dan
     * angkanya, kolom USD, judul dokumen, bagian bertingkat, spesifikasi, DUR.
     */
    private function budget(int $subtotal = 96_000_000): string
    {
        $rows = [
            [null, 'FLAGSHIP STORE'],
            [1, 'Main Flagship Store (Ex Labahkoe 60m2)'],
            [null, 'WORK PREPARATION'],
            [null, 'Work Preparation, Planning and Management', 'Rp', 40_000_000, 1, 'SET', 1, 'SET', 'Rp', 40_000_000, 17_900, 2_235],
            [null, 'Stainless steel hairline-finish column', 'Rp', 28_000_000, 2, 'SET', 1, 'SET', 'Rp', 56_000_000, 17_900, 3_128],
            [null, 'SUB TOTAL', null, null, null, null, null, null, 'RP', $subtotal, 17_900, 5_363],
            [null, 'PARTITION'],
            [null, 'RIGHT PARTS'],
            [null, 'Single-sided gypsum partition', 'Rp', 35_000_000, 1, 'SET', 1, 'SET', 'Rp', 35_000_000, 17_900, 1_955],
            [null, 'Hollow 4x4 + 9mm gypsum', null, null, 1, 'SET', 1, 'SET', 'Rp', '-', 17_900, 0],
            [null, 'REAR PARTS', null, null, null, null, 1, 'SET'],
            [null, 'Concealed storage door 100x225 cm', 'Rp', 10_000_000, 1, 'SET', 1, 'SET', 'Rp', 10_000_000, 17_900, 559],
            [null, '- plywood 9mm Fin.Kertasive'],
            [null, 'SUB TOTAL', null, null, null, null, null, null, 'RP', 45_000_000, 17_900, 2_514],
            [null, 'RENT AND OPERATIONAL COST'],
            [null, 'Rent — 12 Months', 'Rp', 3_000_000, 1, 'SET', 12, 'Month', 'Rp', 36_000_000, 18_058, 1_994],
            [null, 'Electrical Fee', 'Rp', 100_000_000, 1, 'SET', 12, 'Month', 'Rp', 10_000_000, 18_058, 554],
            [null, 'SUB TOTAL', null, null, null, null, null, null, 'RP', 46_000_000, 18_058, 2_547],
            [null, 'GRAND TOTAL', null, null, null, null, null, null, 'RP', 187_000_000, 18_058, 10_447],
        ];

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['NO', 'ITEMS', 'PRICE/QTY', null, 'QTY', 'UNIT', 'DUR', 'UNIT', 'TOTAL', null, 'USD RATE', 'IN TOTAL USD'], null, 'A1');
        $sheet->mergeCells('C1:D1');
        $sheet->mergeCells('I1:J1');
        $sheet->fromArray($rows, null, 'A2', true);

        $path = tempnam(sys_get_temp_dir(), 'quo-') . '.xlsx';
        $this->paths[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
