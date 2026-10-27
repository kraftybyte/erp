<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\QuotationResource\Pages\CreateQuotation;
use App\Models\Quotation;
use App\Models\User;
use App\Services\QuotationItemImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
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
