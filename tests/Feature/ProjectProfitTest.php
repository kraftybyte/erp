<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Models\Advance;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectProfit;
use App\Services\RealisasiService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Laba per project: uang masuk dari invoice dikurangi expense yang dibayarkan
 * dan advance yang dicairkan.
 */
class ProjectProfitTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = $this->user('super_admin');
        $this->deal = $this->deal();
    }

    public function test_laba_dari_uang_masuk_dikurangi_biaya_yang_sudah_keluar(): void
    {
        $project = $this->project();

        $this->invoice(['grand_total' => 12_000_000, 'paid' => 10_000_000, 'status' => 'partial']);
        // Tidak dihitung: dibatalkan, proforma, dan invoice client lain.
        $this->invoice(['grand_total' => 5_000_000, 'paid' => 5_000_000, 'status' => 'cancelled']);
        $this->invoice(['type' => 'estimate', 'grand_total' => 9_000_000, 'status' => 'draft']);
        $this->invoice(['grand_total' => 7_000_000, 'paid' => 7_000_000, 'status' => 'paid'], $this->deal());

        $this->expense($project, 'Selesai', 2_000_000);
        $this->expense($project, 'Pending', 1_000_000);   // belum dibayar: menunggu
        $this->expense($project, 'Ditolak', 4_000_000);   // tidak dihitung sama sekali

        // Dicairkan 3 juta, direalisasi 2,5 juta, sisanya belum kembali: 3 juta keluar.
        $open = $this->advance($project, Advance::STATUS_DIBAYARKAN, 3_000_000);
        app(RealisasiService::class)->createBatch($open, ['uploaded_by' => $this->user->id], [['name' => 'Cetak', 'quantity' => 1, 'price' => 2_500_000]]);
        // Overrun yang disetujui: realisasi 1,2 juta dari advance 1 juta.
        $over = $this->advance($project, Advance::STATUS_DIBAYARKAN, 1_000_000);
        app(RealisasiService::class)->createBatch($over, ['uploaded_by' => $this->user->id], [['name' => 'Kayu', 'quantity' => 1, 'price' => 1_200_000]]);
        $this->advance($project, Advance::STATUS_PENDING, 8_000_000);

        $summary = app(ProjectProfit::class)->summaries()[$project->id];

        $this->assertEquals(10_000_000, $summary['received']);
        $this->assertEquals(2_000_000, $summary['outstanding']);
        $this->assertEquals(2_000_000, $summary['expense']);
        $this->assertEquals(1_000_000, $summary['expense_pending']);
        $this->assertEquals(4_200_000, $summary['advance']);
        $this->assertEquals(6_200_000, $summary['cost']);
        $this->assertEquals(3_800_000, $summary['profit']);
        $this->assertEquals(38.0, $summary['margin']);
    }

    public function test_rangkaian_dp_dan_pelunasan_tidak_dihitung_dua_kali(): void
    {
        // Invoice DP menyimpan harga penuh; pelunasannya juga.
        $dp      = $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 20_000_000, 'paid' => 10_000_000, 'status' => 'paid']);
        $final   = $this->invoice(['type_payment' => 'final_payment', 'parent_invoice_id' => $dp->id, 'grand_total' => 20_000_000, 'status' => 'draft']);
        $project = $this->project(['invoice_id' => $dp->id]);
        $other   = $this->project();
        $this->invoice(['grand_total' => 3_000_000, 'paid' => 3_000_000, 'status' => 'paid']);

        $summaries = app(ProjectProfit::class)->summaries();

        $this->assertEquals(10_000_000, $summaries[$project->id]['received']);
        $this->assertEquals(10_000_000, $summaries[$project->id]['outstanding'], 'Sisa dari harga penuh, bukan 20 juta lagi.');
        $this->assertEquals(3_000_000, $summaries[$other->id]['received'], 'Invoice client yang bukan milik project lain.');

        $final->update(['paid' => 10_000_000, 'status' => 'paid']);

        $summary = app(ProjectProfit::class)->summaries()[$project->id];
        $this->assertEquals(20_000_000, $summary['received']);
        $this->assertEquals(0, $summary['outstanding']);
    }

    public function test_client_dengan_beberapa_project_tanpa_invoice_tidak_dibagi_asal(): void
    {
        $first  = $this->project();
        $second = $this->project();
        $paid   = $this->invoice(['grand_total' => 6_000_000, 'paid' => 6_000_000, 'status' => 'paid']);
        $this->expense($first, 'Selesai', 1_000_000);

        $summary = app(ProjectProfit::class)->summaries()[$first->id];
        $this->assertNull($summary['received']);
        $this->assertNull($summary['profit']);
        $this->assertSame(2, $summary['shared']);
        $this->assertEquals(1_000_000, $summary['cost'], 'Biaya tetap terlihat.');

        // Setelah invoicenya dipilih di form project, keduanya terhitung.
        Livewire::actingAs($this->user)
            ->test(EditProject::class, ['record' => $first->getRouteKey()])
            ->assertFormFieldExists('invoice_id')
            ->fillForm(['invoice_id' => $paid->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $summaries = app(ProjectProfit::class)->summaries();
        $this->assertEquals(6_000_000, $summaries[$first->id]['received']);
        $this->assertEquals(5_000_000, $summaries[$first->id]['profit']);
        $this->assertEquals(0, $summaries[$second->id]['received']);
        $this->assertSame(0, $summaries[$second->id]['shared']);
    }

    public function test_kolom_laba_di_daftar_project_dan_bisa_diurutkan(): void
    {
        $rugi  = $this->project();
        $untung = $this->project(['invoice_id' => $this->invoice(['grand_total' => 9_000_000, 'paid' => 9_000_000, 'status' => 'paid'])->id]);
        $this->expense($rugi, 'Selesai', 2_000_000);

        $page = Livewire::actingAs($this->user)
            ->test(ListProjects::class)
            ->assertTableColumnVisible('laba')
            ->assertTableColumnStateSet('laba', 9_000_000, $untung)
            ->assertTableColumnStateSet('laba', -2_000_000, $rugi)
            ->assertSee('Margin 100,0%')
            ->sortTable('laba');

        $ids = $page->instance()->getTableRecords()->modelKeys();
        $this->assertLessThan(array_search($untung->id, $ids), array_search($rugi->id, $ids), 'Rugi lebih dulu saat diurutkan naik.');
    }

    public function test_angka_keuangan_hanya_untuk_director_finance_dan_super_admin(): void
    {
        $project = $this->project();
        $this->expense($project, 'Selesai', 2_000_000);

        Livewire::actingAs($this->user('Staff'))
            ->test(ListProjects::class)
            ->assertTableColumnHidden('laba')
            ->assertTableColumnHidden('uang_masuk')
            ->assertTableColumnHidden('biaya');

        $this->actingAs($this->user('Staff'));
        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertDontSee('Keuangan')
            ->assertDontSee('Rp 2.000.000');

        $this->actingAs($this->user('Finance'));
        $this->assertTrue(ProjectProfit::visibleTo(auth()->user()));
    }

    public function test_tab_keuangan_merinci_invoice_expense_dan_advance(): void
    {
        $invoice = $this->invoice(['grand_total' => 15_000_000, 'paid' => 15_000_000, 'status' => 'paid']);
        $project = $this->project(['invoice_id' => $invoice->id]);
        $this->expense($project, 'Selesai', 2_000_000, 'Sewa genset');
        $this->expense($project, 'Pending', 500_000, 'Konsumsi kru');
        $this->advance($project, Advance::STATUS_DIBAYARKAN, 3_000_000, 'Beli material');

        $this->actingAs($this->user);

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertSee('Keuangan')
            ->assertSee($invoice->uuid)
            ->assertSee('Sewa genset')
            ->assertSee('Konsumsi kru')
            ->assertSee('Beli material')
            ->assertSee('Rp 15.000.000')
            ->assertSee('Rp 5.000.000')    // biaya: expense 2 juta + advance 3 juta
            ->assertSee('Rp 10.000.000')   // laba
            ->assertSee('Margin 66,7%')
            ->assertSee('Expense yang masih menunggu (Rp 500.000) belum dihitung.');
    }

    // =================================================================
    // Helper
    // =================================================================

    private function project(array $attributes = []): Project
    {
        return Project::create($attributes + [
            'nama_project'     => 'Booth Event',
            'deal_id'          => $this->deal->id,
            'nama_perusahaan'  => $this->deal->nama_perusahaan,
            'nama_pic'         => 'Budi',
            'alamat'           => 'Jl. Tes',
            'detail_project'   => 'Tes laba',
            'timeline_mulai'   => today(),
            'timeline_selesai' => today()->addMonth(),
            'status'           => 'in_progress',
        ]);
    }

    private function deal(): Deal
    {
        return Deal::forceCreate([
            'nama_perusahaan' => 'PT Laba ' . Str::upper(Str::random(5)),
            'nama_pic'        => 'Budi',
            'alamat'          => 'Jl. Tes',
        ]);
    }

    private function invoice(array $attributes, ?Deal $deal = null): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => 'TES/' . Str::upper(Str::random(8)),
            'user_id'         => $this->user->id,
            'deal_id'         => ($deal ?? $this->deal)->id,
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'nama_perusahaan' => ($deal ?? $this->deal)->nama_perusahaan,
            'date'            => today(),
            'paid'            => 0,
        ]);
    }

    private function expense(Project $project, string $status, int $amount, string $note = 'Tes'): Expense
    {
        return Expense::create([
            'user_id'            => $this->user->id,
            'submission_date'    => today(),
            'project_id'         => $project->id,
            'category'           => 'Operasional',
            'keterangan_expense' => $note,
            'amount'             => $amount,
            'paid'               => in_array($status, ['Selesai', 'Dibayarkan'], true) ? $amount : 0,
            'status'             => $status,
        ]);
    }

    private function advance(Project $project, string $status, int $amount, string $note = 'Tes'): Advance
    {
        return Advance::create([
            'user_id'            => $this->user->id,
            'submission_date'    => today(),
            'project_id'         => $project->id,
            'category'           => 'Project Support',
            'keterangan_advance' => $note,
            'amount'             => $amount,
            'paid'               => $status === Advance::STATUS_PENDING ? 0 : $amount,
            'status'             => $status,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'laba_' . Str::lower(Str::random(8)),
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
