<?php

namespace Tests\Feature;

use App\Filament\Pages\Faktur;
use App\Http\Controllers\FakturController;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Realisasi;
use App\Models\RealisasiFile;
use App\Models\RealisasiItem;
use App\Models\User;
use App\Services\FakturCollector;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * Halaman Faktur: Keluaran (invoice ber-PPN) dan Masukan (nota Expense dan
 * struk Realisasi). Data memakai Maret 2001 supaya tidak tercampur data lokal.
 */
class FakturTest extends TestCase
{
    use DatabaseTransactions;

    private const BULAN = '2001-03';

    private int $hari = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_keluaran_menghitung_dpp_dan_ppn_dari_porsi_yang_ditagih(): void
    {
        $this->actingAs($this->user('Finance'));

        // Item tanpa PPN tidak masuk DPP; diskon mengurangi DPP.
        $full = $this->invoice([['price' => 1_000_000, 'discount' => 100_000, 'vat_percentage' => 11], ['price' => 500_000, 'vat_percentage' => 0]], [
            'faktur' => '04002500012345678',
        ]);
        $dp      = $this->invoice([['price' => 10_000_000, 'vat_percentage' => 11]], ['type_payment' => 'dp_30']);
        $final   = $this->invoice([['price' => 10_000_000, 'vat_percentage' => 11]], ['type_payment' => 'final_payment', 'parent_invoice_id' => $dp->id]);
        $lama    = $this->invoice([['price' => 10_000_000, 'vat_percentage' => 11]], ['type_payment' => 'final_payment', 'notes' => "DP: 50% - Rp 5.000.000\n"]);
        $entah   = $this->invoice([['price' => 2_000_000, 'vat_percentage' => 11]], ['type_payment' => 'final_payment', 'faktur' => '-']);
        $tanpaPpn = $this->invoice([['price' => 3_000_000, 'vat_percentage' => 0]]);
        $batal   = $this->invoice([['price' => 3_000_000, 'vat_percentage' => 11]], ['status' => 'cancelled']);
        $proforma = $this->invoice([['price' => 3_000_000, 'vat_percentage' => 11]], ['type' => 'estimate']);

        $rows = app(FakturCollector::class)->keluaran(FakturController::period(self::BULAN))->keyBy(fn ($row) => $row['invoice']->id);

        $this->assertEquals([$full->id, $dp->id, $final->id, $lama->id, $entah->id], $rows->keys()->all());

        $this->assertEquals([900_000, 99_000], [$rows[$full->id]['dpp'], $rows[$full->id]['ppn']]);
        $this->assertSame('04002500012345678', $rows[$full->id]['faktur']);

        $this->assertSame('DP 30%', $rows[$dp->id]['kind']);
        $this->assertEquals([3_000_000, 330_000], [$rows[$dp->id]['dpp'], $rows[$dp->id]['ppn']]);
        $this->assertSame('Pelunasan 70%', $rows[$final->id]['kind']);
        $this->assertEquals([7_000_000, 770_000], [$rows[$final->id]['dpp'], $rows[$final->id]['ppn']], 'DP + pelunasan = PPN satu pekerjaan, tidak dua kali.');
        $this->assertEquals(550_000, $rows[$lama->id]['ppn'], 'Invoice lama: persen DP dari catatan, sama dengan PDF.');

        $this->assertEquals(220_000, $rows[$entah->id]['ppn']);
        $this->assertNotNull($rows[$entah->id]['note']);
        $this->assertNull($rows[$entah->id]['faktur'], '"-" bukan nomor faktur.');
    }

    public function test_masukan_mengumpulkan_nota_expense_dan_struk_realisasi_ke_zip(): void
    {
        $user = $this->user('Finance');
        $this->actingAs($user);

        Storage::disk('public')->put('expense/nota-bensin.jpg', 'jpg');
        Storage::disk('public')->put('realisasi/9/struk-kabel.pdf', 'pdf');

        $this->expense($user, 'Bensin ke lokasi', 150_000, 'expense/nota-bensin.jpg');
        $this->expense($user, 'Grab ke kantor klien', 45_000, null);
        $this->expense($user, 'Ditolak', 99_000, 'expense/nota-bensin.jpg', status: 'Ditolak');
        $this->realisasi($user, [['Kabel HDMI', 250_000, 'realisasi/9/struk-kabel.pdf'], ['Parkir', 10_000, null]]);

        $collector = app(FakturCollector::class);
        $rows      = $collector->masukan(FakturController::period(self::BULAN));

        $this->assertSame(['Bensin ke lokasi', 'Grab ke kantor klien', 'Kabel HDMI', 'Parkir'], $rows->pluck('description')->all());
        $this->assertEquals(455_000, $rows->sum('amount'));

        $zip = new ZipArchive();
        $zip->open($path = $collector->zip(FakturController::period(self::BULAN), 'daftar-faktur.xlsx'));
        $isi = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
        $excel = $zip->getFromName('daftar-faktur.xlsx');
        $zip->close();
        unlink($path);

        $this->assertSame([
            'Expense/2001-03-05 Tes Finance - Bensin ke lokasi - 150000.jpg',
            'Realisasi/2001-03-10 Tes Finance - Kabel HDMI - 250000.pdf',
            'daftar-faktur.xlsx',
        ], $isi);

        // Daftar di dalam ZIP menunjuk nama file-nya, dan yang belum ada ditandai.
        file_put_contents($tmp = tempnam(sys_get_temp_dir(), 'tes-') . '.xlsx', $excel);
        $sheet = IOFactory::load($tmp)->getSheetByName('Faktur Masukan');
        unlink($tmp);

        $this->assertSame('Expense/2001-03-05 Tes Finance - Bensin ke lokasi - 150000.jpg', $sheet->getCell('G2')->getValue());
        $this->assertSame('BELUM ADA', $sheet->getCell('G3')->getValue());
    }

    public function test_excel_menyimpan_nomor_faktur_sebagai_teks(): void
    {
        $this->actingAs($this->user('Finance'));
        $this->invoice([['price' => 1_000_000, 'vat_percentage' => 11]], ['faktur' => '04002500012345678']);
        $this->invoice([['price' => 1_000_000, 'vat_percentage' => 11]]);

        $path  = app(FakturCollector::class)->excel(FakturController::period(self::BULAN));
        $sheet = IOFactory::load($path)->getSheetByName('Faktur Keluaran');
        unlink($path);

        $this->assertSame('04002500012345678', $sheet->getCell('G2')->getValue(), 'Nomor 17 digit tidak boleh jadi angka (nol depan hilang).');
        $this->assertSame('BELUM ADA', $sheet->getCell('G3')->getValue());
        $this->assertEquals(220_000, $sheet->getCell('F4')->getValue(), 'Baris total PPN.');
    }

    public function test_nomor_faktur_diisi_langsung_di_halaman(): void
    {
        $this->actingAs($this->user('Finance'));
        $invoice = $this->invoice([['price' => 2_000_000, 'vat_percentage' => 11]]);

        $page = Livewire::test(Faktur::class)
            ->set('bulan', self::BULAN)
            ->assertSee($invoice->uuid)
            ->assertSee('Belum ada nomor faktur')
            ->call('simpanFaktur', $invoice->id, '  04002500012345678 ')
            ->assertNotified();

        $this->assertSame('04002500012345678', $invoice->fresh()->faktur);
        $this->assertStringContainsString('04002500012345678', $invoice->invoiceLogs()->latest('id')->value('log'));

        $page->set('belumAda', true)->assertDontSee($invoice->uuid);

        $page->call('simpanFaktur', $invoice->id, '');
        $this->assertNull($invoice->fresh()->faktur);
    }

    public function test_nomor_faktur_proforma_tidak_bisa_diisi_dari_halaman(): void
    {
        $this->actingAs($this->user('Finance'));
        $proforma = $this->invoice([['price' => 2_000_000, 'vat_percentage' => 11]], ['type' => 'estimate']);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(Faktur::class)->call('simpanFaktur', $proforma->id, '04002500012345678');
    }

    public function test_staff_tidak_bisa_membuka_halaman_dan_unduhan(): void
    {
        $this->actingAs($this->user('Staff'));

        $this->get(Faktur::getUrl())->assertForbidden();
        $this->get(route('faktur.zip', self::BULAN))->assertForbidden();
        $this->get(route('faktur.excel', self::BULAN))->assertForbidden();
    }

    public function test_director_membuka_halaman_dan_mengunduh(): void
    {
        $this->actingAs($this->user('Director'));

        $this->get(Faktur::getUrl())->assertOk()->assertSee('Keluaran')->assertSee('Masukan');

        $this->get(route('faktur.zip', self::BULAN))
            ->assertOk()
            ->assertDownload('faktur-masukan-' . self::BULAN . '.zip');
        $this->get(route('faktur.excel', self::BULAN))
            ->assertOk()
            ->assertDownload('faktur-' . self::BULAN . '.xlsx');

        $this->get('/faktur/2001-13/zip')->assertNotFound();
    }

    public function test_tab_masukan_menandai_yang_belum_ada_faktur(): void
    {
        $user = $this->user('Finance');
        $this->actingAs($user);
        Storage::disk('public')->put('expense/nota-bensin.jpg', 'jpg');
        $this->expense($user, 'Bensin ke lokasi', 150_000, 'expense/nota-bensin.jpg');
        $this->expense($user, 'Grab ke kantor klien', 45_000, null);

        Livewire::test(Faktur::class)
            ->set('bulan', self::BULAN)
            ->set('tab', 'masukan')
            ->assertSee('Bensin ke lokasi')
            ->assertSee('Grab ke kantor klien')
            ->assertSee('Belum ada')
            ->set('belumAda', true)
            ->assertDontSee('Bensin ke lokasi')
            ->assertSee('Grab ke kantor klien');
    }

    public function test_faktur_pembelian_diupload_dari_tab_masukan(): void
    {
        $user = $this->user('Finance');
        $this->actingAs($user);
        $expense = $this->expense($user, 'Grab ke kantor klien', 45_000, null);
        $this->realisasi($user, [['Parkir', 10_000, null]]);
        $item = RealisasiItem::where('name', 'Parkir')->latest('id')->first();

        $page = Livewire::test(Faktur::class)
            ->set('bulan', self::BULAN)
            ->set('tab', 'masukan')
            ->assertSee('Upload')
            ->callAction('upload', data: ['file' => UploadedFile::fake()->image('nota-grab.jpg')], arguments: ['target' => "expense:{$expense->id}"])
            ->assertHasNoActionErrors()
            ->assertNotified('Faktur tersimpan')
            ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('struk-parkir.pdf', "%PDF-1.4\n%%EOF")], arguments: ['target' => "realisasi:{$item->id}"])
            ->assertHasNoActionErrors();

        // Kolom nota yang sama dengan tombol Upload Nota, syarat tombol Bayar.
        $path = $expense->fresh()->attachment;
        $this->assertStringStartsWith('expense/invoice/', $path);
        Storage::disk('public')->assertExists($path);

        $file = $item->files()->sole();
        $this->assertStringStartsWith('realisasi/' . $item->realizasi->advance_id . '/', $file->filepath);
        $this->assertSame(['struk-parkir.pdf', 'application/pdf'], [$file->original_name, $file->mime_type]);
        Storage::disk('public')->assertExists($file->filepath);

        $page->set('belumAda', true)->assertDontSee('Grab ke kantor klien')->assertDontSee('Parkir');
    }

    public function test_upload_faktur_hanya_gambar_atau_pdf_dan_tidak_menimpa_nota(): void
    {
        $user = $this->user('Finance');
        $this->actingAs($user);
        Storage::disk('public')->put('expense/nota-lama.jpg', 'jpg');
        $kosong = $this->expense($user, 'Grab ke kantor klien', 45_000, null);
        $ada    = $this->expense($user, 'Bensin ke lokasi', 150_000, 'expense/nota-lama.jpg');

        // Upload palsu Livewire di tes menilai jenis file dari namanya, jadi yang
        // diuji di sini pemeriksaan isi di Faktur::storeFile.
        Livewire::test(Faktur::class)
            ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('nota.jpg', '<?php system($_GET["c"]); ?>')], arguments: ['target' => "expense:{$kosong->id}"])
            ->assertNotified('File ini bukan gambar atau PDF.');
        $this->assertNull($kosong->fresh()->attachment);
        $this->assertSame(['expense/nota-lama.jpg'], Storage::disk('public')->allFiles(), 'Skrip tidak tersimpan.');

        Livewire::test(Faktur::class)
            ->callAction('upload', data: ['file' => UploadedFile::fake()->image('nota-baru.jpg')], arguments: ['target' => "expense:{$ada->id}"])
            ->assertNotified('Expense ini sudah punya nota.');
        $this->assertSame('expense/nota-lama.jpg', $ada->fresh()->attachment);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function invoice(array $items, array $attributes = []): Invoice
    {
        $invoice = Invoice::create($attributes + [
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'draft',
            'nama_perusahaan' => 'PT Faktur Tes',
            'nama_pic'        => 'Budi',
            'date'            => sprintf('%s-%02d', self::BULAN, ++$this->hari),
            'due_date'        => self::BULAN . '-28',
            'paid'            => 0,
            'currency_id'     => 66,
        ]);

        foreach ($items as $item) {
            $invoice->invoicesItems()->create($item + [
                'item'           => 'Item tes',
                'qty'            => 1,
                'original_price' => $item['price'],
                'discount'       => 0,
                'vat'            => 0,
                'total'          => $item['price'],
            ]);
        }

        return $invoice;
    }

    private function expense(User $user, string $keterangan, int $amount, ?string $attachment, string $status = 'Disetujui'): Expense
    {
        return Expense::forceCreate([
            'user_id'            => $user->id,
            'submission_date'    => self::BULAN . '-05',
            'keterangan_expense' => $keterangan,
            'category'           => 'Transport',
            'amount'             => $amount,
            'attachment'         => $attachment,
            'status'             => $status,
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: ?string}>  $items  nama, harga, file
     */
    private function realisasi(User $user, array $items): void
    {
        $project = Project::create(['nama_project' => 'Booth Faktur', 'nama_perusahaan' => 'PT Faktur Tes', 'status' => 'in_progress']);
        $advance = Advance::create([
            'user_id'            => $user->id,
            'submission_date'    => self::BULAN . '-01',
            'project_id'         => $project->id,
            'keterangan_advance' => 'Advance booth',
            'category'           => 'Project Support',
            'amount'             => 1_000_000,
            'paid'               => 1_000_000,
            'status'             => Advance::STATUS_DIBAYARKAN,
        ]);
        $batch = Realisasi::forceCreate([
            'advance_id'   => $advance->id,
            'batch_number' => 1,
            'uploaded_by'  => $user->id,
            'created_at'   => self::BULAN . '-10 09:00:00',
        ]);

        foreach ($items as [$name, $price, $file]) {
            $item = RealisasiItem::create(['realisasi_id' => $batch->id, 'name' => $name, 'quantity' => 1, 'price' => $price]);

            if ($file) {
                RealisasiFile::create(['realisasi_item_id' => $item->id, 'original_name' => basename($file), 'filepath' => $file, 'filesize' => 3, 'mime_type' => 'application/pdf']);
            }
        }
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'faktur_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => $role,
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'email_verified_at' => now(),
            'is_active'         => true,
            'push_enabled'      => false,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
