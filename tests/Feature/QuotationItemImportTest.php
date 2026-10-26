<?php

namespace Tests\Feature;

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

        $this->assertSame('Work Preparation, Planning and Management', $prep['item']);
        $this->assertSame(['WORK PREPARATION'], $prep['description_lines'], 'Judul dokumen di atas baris NO tidak ikut.');

        $this->assertEquals([2, 28_000_000, 56_000_000], [$column['qty'], $column['price'], $column['total']]);

        // Judul bagian bertingkat + baris spesifikasi di bawah item.
        $this->assertSame(['PARTITION · RIGHT PARTS', 'Hollow 4x4 + 9mm gypsum'], $gypsum['description_lines']);
        $this->assertSame(['PARTITION · REAR PARTS', 'plywood 9mm Fin.Kertasive'], $door['description_lines']);

        // QTY × DUR.
        $this->assertEquals([12, 3_000_000, 36_000_000], [$rent['qty'], $rent['price'], $rent['total']]);
        $this->assertContains('1 SET × 12 Month', $rent['description_lines']);

        // Harga × qty tidak sama dengan total di file: total yang dipakai, sebagai satu paket.
        $this->assertEquals([1, 10_000_000, 10_000_000], [$fee['qty'], $fee['price'], $fee['total']]);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Electrical Fee', $result['warnings'][0]);
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

        $result = app(QuotationItemImport::class)->parse($path);

        $this->assertCount(2, $result['items']);
        $this->assertSame(['Rangka besi + cetak'], $result['items'][0]['description_lines']);
        $this->assertEquals(25_000_000, $result['items'][0]['total']);
        $this->assertEquals(1_750_000.5, $result['items'][1]['price']);
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
            ->set('mountedFormComponentActionsData.0.group', false)
            ->call('callMountedFormComponentAction')
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['subtotal' => 187_000_000, 'total_vat' => 20_570_000, 'grand_total' => 207_570_000]);

        $this->assertCount(6, $component->get('data.items'));

        $client = 'PT Import ' . Str::upper(Str::random(5));
        $component->fillForm(['nama_perusahaan' => $client])->call('create')->assertHasNoFormErrors();

        $quotation = Quotation::where('nama_perusahaan', $client)->sole();
        $this->assertCount(6, $quotation->items);
        $this->assertEquals(207_570_000, $quotation->grand_total);
        $this->assertSame('Rent — 12 Months', $quotation->items->firstWhere('qty', 12)->item);
        $this->assertStringContainsString('PARTITION · RIGHT PARTS<br>Hollow 4x4 + 9mm gypsum', $quotation->items->firstWhere('price', 35_000_000)->description);
    }

    public function test_tombol_import_per_kategori_secara_default(): void
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
            ->call('callMountedFormComponentAction')
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['grand_total' => 187_000_000]);

        $items = array_values($component->get('data.items'));
        $this->assertSame(['WORK PREPARATION', 'PARTITION', 'RENT AND OPERATIONAL COST'], array_column($items, 'item'));
        $this->assertEquals(45_000_000, $items[1]['total']);
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

        return $path;
    }
}
