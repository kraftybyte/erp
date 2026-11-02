<?php

namespace Tests\Feature;

use App\Filament\Pages\Faktur;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FakturMatcher;
use App\Settings\GeneralSettings;
use App\Settings\IntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Upload faktur di halaman Faktur: dibaca Gemini (di-fake), dicarikan
 * pasangannya, lalu disimpan ke invoice / expense yang dipilih.
 * Data memakai Maret 2001 supaya tidak tercampur data lokal.
 */
class FakturUploadTest extends TestCase
{
    use DatabaseTransactions;

    private const BULAN = '2001-03';

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        IntegrationSettings::fake(['gemini_api_key' => 'gemini-tes', 'gemini_model' => 'gemini-3.5-flash-lite']);

        $this->actingAs($this->user('Finance'));
    }

    public function test_faktur_pajak_keluaran_dipasangkan_ke_invoice_dan_nomornya_terisi(): void
    {
        $invoice = $this->invoice('PT Nestory Indonesia', 10_000_000, '2001-03-05');
        $this->invoice('PT Lain Sekali', 4_000_000, '2001-03-06');
        // Nominalnya sama dengan PPN, tapi faktur yang penjualnya kita bukan faktur masukan.
        $this->expense('Sewa lighting', 1_100_000, '2001-03-05');

        $this->fakeGemini($doc = [
            'jenis'        => 'faktur_pajak',
            'nomor_faktur' => '04002500012345678',
            'tanggal'      => '2001-03-05',
            'penjual'      => app(GeneralSettings::class)->inv_company_name,
            'pembeli'      => 'PT NESTORY INDONESIA',
            'dpp'          => 10_000_000,
            'ppn'          => 1_100_000,
            'total'        => 11_100_000,
        ]);

        $this->assertSame(["invoice:{$invoice->id}"], array_keys(app(FakturMatcher::class)->candidates(app(FakturMatcher::class)->read(self::PDF, 'application/pdf'))));

        $page = $this->upload([UploadedFile::fake()->createWithContent('faktur-nestory.pdf', self::PDF)]);
        $row  = array_values($page->get('mountedActionsData.0.rows'))[0];
        $this->assertSame("invoice:{$invoice->id}", $row['target'], 'Pasangan yang cocok sudah dipilihkan.');

        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('1 faktur tersimpan');

        $invoice->refresh();
        $this->assertSame('04002500012345678', $invoice->faktur);
        $this->assertStringStartsWith('faktur-pajak/', $invoice->faktur_file);
        Storage::disk('public')->assertExists($invoice->faktur_file);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Salinan sementara dihapus.');
    }

    public function test_faktur_pembelian_dipasangkan_ke_expense_yang_belum_ada_nota(): void
    {
        $dekat = $this->expense('Bensin ke lokasi', 150_000, '2001-03-10');
        $jauh  = $this->expense('Bensin bulan lalu', 150_000, '2001-02-01');
        $this->expense('Parkir', 20_000, '2001-03-10');

        $this->fakeGemini([
            'jenis' => 'pembelian', 'nomor_faktur' => null, 'tanggal' => '2001-03-10',
            'penjual' => 'SPBU Pertamina', 'pembeli' => null, 'dpp' => null, 'ppn' => null, 'total' => 150_000,
        ]);

        $page = $this->upload([UploadedFile::fake()->image('struk-bensin.jpg')]);
        $row  = array_values($page->get('mountedActionsData.0.rows'))[0];

        $this->assertSame("expense:{$dekat->id}", $row['target'], 'Tanggal paling dekat diutamakan.');
        $this->assertArrayHasKey("expense:{$jauh->id}", $row['options']['Paling cocok']);

        $page->callMountedAction()->assertHasNoActionErrors();

        $this->assertStringStartsWith('expense/invoice/', $dekat->fresh()->attachment);
        Storage::disk('public')->assertExists($dekat->fresh()->attachment);
        $this->assertNull($jauh->fresh()->attachment);
    }

    public function test_tanpa_nominal_yang_cocok_pasangannya_dipilih_sendiri(): void
    {
        $expense = $this->expense('Konsumsi rapat', 275_000, '2001-03-12');

        $this->fakeGemini([
            'jenis' => 'pembelian', 'nomor_faktur' => null, 'tanggal' => '2001-03-12',
            'penjual' => 'Warung Sederhana', 'pembeli' => null, 'dpp' => null, 'ppn' => null, 'total' => 999_000,
        ]);

        $page = $this->upload([UploadedFile::fake()->image('nota.jpg')])
            ->assertSee('Tidak ada yang nominalnya cocok');
        $key = array_key_first($page->get('mountedActionsData.0.rows'));

        $this->assertNull($page->get("mountedActionsData.0.rows.{$key}.target"));
        $this->assertArrayHasKey("expense:{$expense->id}", $page->get("mountedActionsData.0.rows.{$key}.options")['Expense & realisasi (faktur pembelian)']);

        $page->set("mountedActionsData.0.rows.{$key}.target", "expense:{$expense->id}")
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertNotNull($expense->fresh()->attachment);
    }

    public function test_tanpa_gemini_file_tetap_bisa_dipasangkan_sendiri(): void
    {
        // Kosong di Settings dan di cadangan .env (IntegrationSettings::resolve).
        IntegrationSettings::fake(['gemini_api_key' => null]);
        config(['services.gemini.key' => null]);
        Http::fake();
        $expense = $this->expense('Konsumsi rapat', 275_000, '2001-03-12');

        $page = $this->upload([UploadedFile::fake()->image('nota.jpg')])->assertSee('Isi file tidak terbaca otomatis');
        $key  = array_key_first($page->get('mountedActionsData.0.rows'));

        $page->set("mountedActionsData.0.rows.{$key}.target", "expense:{$expense->id}")->callMountedAction();

        $this->assertNotNull($expense->fresh()->attachment);
        Http::assertNothingSent();
    }

    public function test_file_yang_dilewati_tidak_disimpan(): void
    {
        $expense = $this->expense('Bensin ke lokasi', 150_000, '2001-03-10');
        $this->fakeGemini(['jenis' => 'pembelian', 'tanggal' => '2001-03-10', 'total' => 150_000]);

        $page = $this->upload([UploadedFile::fake()->image('struk.jpg')]);
        $key  = array_key_first($page->get('mountedActionsData.0.rows'));

        $page->set("mountedActionsData.0.rows.{$key}.target", null)
            ->callMountedAction()
            ->assertNotified('0 faktur tersimpan, 1 dilewati');

        $this->assertNull($expense->fresh()->attachment);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_nomor_faktur_yang_sudah_ada_tidak_ditimpa(): void
    {
        $invoice = $this->invoice('PT Nestory Indonesia', 10_000_000, '2001-03-05', faktur: '04002500099999999');
        $result  = app(FakturMatcher::class)->attach("invoice:{$invoice->id}", self::PDF, 'faktur.pdf', '04002500012345678');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('berbeda', $result['note']);
        $this->assertSame('04002500099999999', $invoice->fresh()->faktur);
        $this->assertNotNull($invoice->fresh()->faktur_file);
    }

    public function test_file_bukan_gambar_atau_pdf_tidak_diterima(): void
    {
        $this->fakeGemini(['jenis' => 'pembelian', 'total' => 150_000]);

        // Upload palsu Livewire di tes menilai jenis file dari namanya; isinya
        // diperiksa FakturMatcher::keep sebelum apa pun disimpan.
        Livewire::test(Faktur::class)
            ->set('bulan', self::BULAN)
            ->mountAction('uploadFaktur')
            ->set('mountedActionsData.0.files', [UploadedFile::fake()->createWithContent('faktur.pdf', '<?php system($_GET["c"]); ?>')])
            ->call('dispatchFormEvent', 'wizard::nextStep', 'mountedActionsData.0', 0)
            ->assertNotified('faktur.pdf bukan gambar atau PDF, dilewati.');

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_path_sementara_tidak_bisa_diarahkan_ke_file_lain(): void
    {
        $expense = $this->expense('Bensin ke lokasi', 150_000, '2001-03-10');
        Storage::disk('local')->put('rahasia.txt', 'APP_KEY=base64:rahasia');

        foreach (['../../.env', 'rahasia.txt', 'faktur-upload/../rahasia.txt'] as $path) {
            $result = app(FakturMatcher::class)->attachKept("expense:{$expense->id}", $path, 'x.pdf');

            $this->assertFalse($result['ok'], $path);
        }

        $this->assertNull($expense->fresh()->attachment);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function upload(array $files)
    {
        return Livewire::test(Faktur::class)
            ->set('bulan', self::BULAN)
            ->mountAction('uploadFaktur')
            ->set('mountedActionsData.0.files', $files)
            ->call('dispatchFormEvent', 'wizard::nextStep', 'mountedActionsData.0', 0)
            ->assertHasNoActionErrors();
    }

    private function fakeGemini(array $doc): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($doc + [
                    'jenis' => null, 'nomor_faktur' => null, 'tanggal' => null, 'penjual' => null,
                    'pembeli' => null, 'dpp' => null, 'ppn' => null, 'total' => null,
                ])]]]]],
            ]),
        ]);
    }

    private function invoice(string $client, int $price, string $date, ?string $faktur = null): Invoice
    {
        $invoice = Invoice::create([
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'pending',
            'nama_perusahaan' => $client,
            'nama_pic'        => 'Budi',
            'date'            => $date,
            'due_date'        => $date,
            'paid'            => 0,
            'currency_id'     => 66,
            'faktur'          => $faktur,
        ]);

        $invoice->invoicesItems()->create([
            'item' => 'Jasa', 'qty' => 1, 'price' => $price, 'original_price' => $price,
            'discount' => 0, 'vat' => 0, 'vat_percentage' => 11, 'total' => $price,
        ]);

        return $invoice;
    }

    private function expense(string $keterangan, int $amount, string $date): Expense
    {
        return Expense::forceCreate([
            'user_id'            => auth()->id(),
            'submission_date'    => $date,
            'keterangan_expense' => $keterangan,
            'category'           => 'Transport',
            'amount'             => $amount,
            'status'             => 'Disetujui',
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'fkup_' . Str::lower(Str::random(8)),
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
