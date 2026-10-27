<?php

namespace Tests\Feature;

use App\Filament\Resources\AdvanceResource\Pages\ListAdvances;
use App\Filament\Resources\AdvanceResource\Pages\RealisasiAdvance;
use App\Filament\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\RealisasiService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Temuan kritis audit 27 Okt 2026: upload realisasi, advance dibayar dua kali,
 * DP terpotong dua kali, dan nominal tersimpan seperseribu.
 */
class CriticalFixesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // =================================================================
    // 1. Upload di halaman realisasi
    // =================================================================

    public function test_bukti_return_berisi_skrip_ditolak_dan_tidak_tersimpan(): void
    {
        $this->actingAs($this->user('super_admin'));
        $advance = $this->paidAdvanceWithLeftover();

        Livewire::test(RealisasiAdvance::class, ['record' => $advance])
            ->set('returnAttachment', UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]); ?>'))
            ->call('processReturn')
            ->assertHasErrors('returnAttachment');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_aturan_file_memeriksa_isi_bukan_nama(): void
    {
        // Upload palsu Livewire di tes menentukan jenis file dari namanya, jadi
        // pemeriksaan isi diuji langsung dengan file sungguhan.
        $rule = (new \ReflectionClassConstant(RealisasiAdvance::class, 'FILE_RULE'))->getValue();
        $file = fn (string $name, string $content) => new UploadedFile(
            tap(tempnam(sys_get_temp_dir(), 'up'), fn ($path) => file_put_contents($path, $content)),
            $name,
            null,
            null,
            true
        );

        $php = $file('bukti.jpg', '<?php system($_GET["c"]); ?>');
        $pdf = $file('nota.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");

        $this->assertTrue(validator(['f' => $php], ['f' => $rule])->fails(), 'Skrip bernama .jpg ditolak.');
        $this->assertFalse(validator(['f' => $pdf], ['f' => $rule])->fails(), 'PDF diterima.');
    }

    public function test_return_hanya_finance_dan_tidak_menyimpan_file_lebih_dulu(): void
    {
        $this->actingAs($this->user('Staff'));
        $advance = $this->paidAdvanceWithLeftover();

        Livewire::test(RealisasiAdvance::class, ['record' => $advance])
            ->set('returnAttachment', UploadedFile::fake()->image('bukti.png'))
            ->call('processReturn');

        $this->assertSame([], Storage::disk('public')->allFiles(), 'Ditolak sebelum file disimpan.');
    }

    public function test_bukti_return_gambar_disimpan_dengan_nama_acak(): void
    {
        $this->actingAs($this->user('Finance'));
        $advance = $this->paidAdvanceWithLeftover();

        Livewire::test(RealisasiAdvance::class, ['record' => $advance])
            ->set('returnAttachment', UploadedFile::fake()->image('bukti.php.png'))
            ->call('processReturn')
            ->assertHasNoErrors();

        $files = Storage::disk('public')->allFiles("returns/{$advance->id}");
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^returns/\d+/[A-Za-z0-9]{40}\.png$#', $files[0]);
    }

    public function test_nota_realisasi_berisi_skrip_ditolak(): void
    {
        $this->actingAs($this->user('super_admin'));
        $advance = $this->advance(Advance::STATUS_DIBAYARKAN, 1_000_000);

        Livewire::test(RealisasiAdvance::class, ['record' => $advance])
            ->set('batchItems', [['name' => 'Lampu', 'description' => '', 'quantity' => 1, 'price' => 100_000]])
            ->set('uploadedFiles', [UploadedFile::fake()->createWithContent('nota.php', '<?php echo 1; ?>')])
            ->call('saveBatch')
            ->assertHasErrors('uploadedFiles.0');

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, $advance->realizasis()->count());
    }

    // =================================================================
    // 2. Advance yang sudah dicairkan tidak bisa di-approve/reject lagi
    // =================================================================

    public function test_approve_dan_reject_hanya_untuk_advance_pending(): void
    {
        $this->actingAs($this->user('super_admin'));

        $pending   = $this->advance(Advance::STATUS_PENDING, 2_000_000);
        $realizing = $this->advance(Advance::STATUS_REALIZED_USER, 5_000_000);
        $paid      = $this->advance(Advance::STATUS_DIBAYARKAN, 5_000_000);

        $page = Livewire::test(ListAdvances::class)
            ->assertTableActionVisible('approve', $pending)
            ->assertTableActionVisible('reject', $pending);

        foreach ([$realizing, $paid] as $advance) {
            $page->assertTableActionHidden('approve', $advance)->assertTableActionHidden('reject', $advance);
        }

        Livewire::test(ListAdvances::class)->callTableAction('approve', $pending);
        $this->assertSame(Advance::STATUS_DISETUJUI, $pending->fresh()->status);

        // Tampilan usang: tombol dibuka saat masih Pending, lalu advance keburu dicairkan.
        $stale = $this->advance(Advance::STATUS_PENDING, 3_000_000);
        Livewire::test(ListAdvances::class)
            ->mountTableAction('approve', $stale)
            ->tap(fn () => $stale->update(['status' => Advance::STATUS_DIBAYARKAN, 'paid' => 3_000_000]))
            ->callMountedTableAction();
        $this->assertSame(Advance::STATUS_DIBAYARKAN, $stale->fresh()->status);
    }

    // =================================================================
    // 3. Invoice DP: harga item dari produk tetap harga penuh
    // =================================================================

    public function test_produk_di_invoice_dp_memakai_harga_penuh(): void
    {
        $this->actingAs($user = $this->user('super_admin'));

        $product = Product::forceCreate([
            'nama_layanan'   => 'Booth Tes ' . Str::upper(Str::random(5)),
            'detail_layanan' => 'Booth 3x3',
            'harga_layanan'  => 5_000_000,
        ]);

        $component = Livewire::test(CreateInvoice::class)->set('data.type_payment', 'dp_50');
        $key       = array_key_first($component->get('data.invoicesItems'));

        $component
            ->set("data.invoicesItems.{$key}.product_id", $product->id)
            ->set("data.invoicesItems.{$key}.qty", 1)
            ->set("data.invoicesItems.{$key}.vat_percentage", 0)
            ->assertSet("data.invoicesItems.{$key}.price", 5_000_000.0)
            ->assertSet("data.invoicesItems.{$key}.total", 5_000_000.0);

        $client = 'PT DP Penuh ' . Str::upper(Str::random(5));
        $component->fillForm(['nama_perusahaan' => $client])->call('create')->assertHasNoFormErrors();

        $invoice = Invoice::where('nama_perusahaan', $client)->sole();
        $this->assertEquals(5_000_000, $invoice->grand_total);
        $this->assertEquals(2_500_000, $invoice->amountDue(), 'DP 50% dari Rp 5 jt, bukan Rp 1,25 jt.');
    }

    // =================================================================
    // 4. Nominal di bawah Rp 1 juta tidak tersimpan seperseribu
    // =================================================================

    public function test_edit_expense_tidak_mengubah_nominal(): void
    {
        $this->actingAs($user = $this->user('super_admin'));

        $project = \App\Models\Project::create(['nama_project' => 'Tes Nominal', 'nama_perusahaan' => 'PT Nominal', 'status' => 'in_progress']);

        $expense = Expense::create([
            'user_id'            => $user->id,
            'project_id'         => $project->id,
            'submission_date'    => today(),
            'category'           => 'Transport',
            'keterangan_expense' => 'Tes nominal',
            'amount'             => 780_490.90,
            'paid'               => 0,
            'status'             => 'Pending',
        ]);

        Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
            ->assertFormSet(['amount' => '780490.9'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(780_490.90, $expense->fresh()->amount);
    }

    public function test_form_bayar_advance_terisi_nominal_tanpa_titik_ribuan(): void
    {
        $this->actingAs($this->user('super_admin'));
        $advance = $this->advance(Advance::STATUS_DISETUJUI, 780_490.90, paid: 0);

        Livewire::test(ListAdvances::class)
            ->mountTableAction('convert_to_pay', $advance)
            ->assertTableActionDataSet(['paid' => '780490.9']);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'krit_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => $role,
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function advance(string $status, float $amount, ?float $paid = null): Advance
    {
        return Advance::create([
            'user_id'            => auth()->id(),
            'submission_date'    => today(),
            'category'           => 'Project Support',
            'keterangan_advance' => "Tes {$status}",
            'amount'             => $amount,
            'paid'               => $paid ?? ($status === Advance::STATUS_PENDING ? 0 : $amount),
            'status'             => $status,
        ]);
    }

    /**
     * Advance Rp 1 jt yang baru direalisasi Rp 600 rb: sisa Rp 400 rb perlu dikembalikan.
     */
    private function paidAdvanceWithLeftover(): Advance
    {
        $advance = $this->advance(Advance::STATUS_DIBAYARKAN, 1_000_000);
        app(RealisasiService::class)->createBatch($advance, ['uploaded_by' => auth()->id()], [['name' => 'Lampu', 'quantity' => 1, 'price' => 600_000]]);

        return $advance->fresh();
    }
}
