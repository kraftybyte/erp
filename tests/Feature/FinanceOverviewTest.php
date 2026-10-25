<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\FinanceOverview;
use App\Services\RealisasiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Angka bot laporan keuangan. Suite berjalan di database nyata yang sudah
 * berisi data, jadi setiap tes membandingkan selisih sebelum dan sesudah
 * datanya sendiri dibuat.
 */
class FinanceOverviewTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'username'     => 'fin_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Finance',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);

        $this->project = Project::create(['nama_project' => 'Tes Laporan', 'nama_perusahaan' => 'PT Laporan', 'status' => 'in_progress']);
    }

    public function test_expense_dikelompokkan_menurut_status_dan_yang_ditolak_tidak_dihitung(): void
    {
        $before = $this->overview()->expenses();

        $this->expense('Pending', 100_000);
        // Data lama: kolom paid sudah terisi walau baru disetujui — tetap "belum dibayar".
        $this->expense('Disetujui', 200_000, paid: 200_000);
        $this->expense('Selesai', 300_000, paid: 300_000);
        $this->expense('Ditolak', 50_000);

        $after = $this->overview()->expenses();

        $this->assertDelta(100_000, 1, $before['pending'], $after['pending']);
        $this->assertDelta(200_000, 1, $before['approved'], $after['approved']);
        $this->assertDelta(300_000, 1, $before['paid'], $after['paid']);
        $this->assertDelta(50_000, 1, $before['rejected'], $after['rejected']);
        $this->assertDelta(600_000, 3, $before['total'], $after['total'], 'Yang ditolak tidak masuk total.');
    }

    public function test_advance_menghitung_sisa_yang_belum_dipertanggungjawabkan(): void
    {
        $before = $this->overview()->advances();

        $open = $this->advance(Advance::STATUS_DIBAYARKAN, 1_000_000);
        app(RealisasiService::class)->createBatch($open, ['uploaded_by' => $this->user->id], [['name' => 'Lighting', 'quantity' => 1, 'price' => 400_000]]);

        // Advance yang sudah selesai tidak lagi punya sisa terbuka.
        $this->advance(Advance::STATUS_SELESAI, 500_000);
        $this->advance(Advance::STATUS_PENDING, 700_000);

        $after = $this->overview()->advances();

        $this->assertDelta(1_500_000, 2, $before['disbursed'], $after['disbursed']);
        $this->assertDelta(700_000, 1, $before['pending'], $after['pending']);
        $this->assertEqualsWithDelta(400_000, $after['realized'] - $before['realized'], 0.01);
        $this->assertDelta(600_000, 1, $before['outstanding'], $after['outstanding']);
    }

    public function test_invoice_dp_dan_pelunasan_tidak_dihitung_dobel(): void
    {
        $before = $this->overview()->invoices();

        // Project 10 juta: DP 20% (2 juta, sudah dibayar) + pelunasan 8 juta.
        // Invoice DP menyimpan nilai penuh di grand_total.
        $dp = $this->invoice(['type_payment' => 'dp_20', 'grand_total' => 10_000_000, 'paid' => 2_000_000, 'status' => 'paid']);
        $this->invoice([
            'type_payment'      => 'final_payment',
            'grand_total'       => 8_000_000,
            'paid'              => 0,
            'status'            => 'pending',
            'parent_invoice_id' => $dp->id,
            'due_date'          => today()->subDays(5),
        ]);
        $this->invoice(['type_payment' => 'full_payment', 'grand_total' => 1_000_000, 'status' => 'draft']);
        $this->invoice(['type_payment' => 'full_payment', 'grand_total' => 3_000_000, 'status' => 'cancelled']);
        // Proforma bukan tagihan.
        $this->invoice(['type' => 'estimate', 'type_payment' => 'full_payment', 'grand_total' => 9_000_000, 'status' => 'pending']);

        $after = $this->overview()->invoices();

        $this->assertDelta(10_000_000, 2, $before['billed'], $after['billed'], 'Ditagihkan 10 juta, bukan 18 juta.');
        $this->assertEqualsWithDelta(2_000_000, $after['paid']['amount'] - $before['paid']['amount'], 0.01);
        $this->assertDelta(8_000_000, 1, $before['outstanding'], $after['outstanding']);
        $this->assertDelta(8_000_000, 1, $before['overdue'], $after['overdue']);
        $this->assertDelta(1_000_000, 1, $before['draft'], $after['draft']);
        $this->assertSame(1, $after['cancelled']['count'] - $before['cancelled']['count']);
    }

    public function test_nilai_pekerjaan_dp_pelunasan_dan_yang_belum_ditagih(): void
    {
        $before = $this->overview()->invoiceBreakdown();

        // Project A 10 juta: DP 20% dibayar + pelunasan 8 juta belum dibayar.
        $dp = $this->invoice(['type_payment' => 'dp_20', 'grand_total' => 10_000_000, 'paid' => 2_000_000, 'status' => 'paid']);
        $this->invoice(['type_payment' => 'final_payment', 'grand_total' => 8_000_000, 'status' => 'pending', 'parent_invoice_id' => $dp->id]);

        // Project B 6 juta: DP 50% dibayar, invoice pelunasan belum dibuat.
        $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 6_000_000, 'paid' => 3_000_000, 'status' => 'paid', 'nama_perusahaan' => 'PT Belum Ditagih']);

        $after = $this->overview()->invoiceBreakdown();
        $delta = fn (string ...$path) => data_get($after, implode('.', $path)) - data_get($before, implode('.', $path));

        $this->assertEqualsWithDelta(16_000_000, $delta('contract_value'), 0.01, 'Nilai pekerjaan 10 + 6 juta, tanpa dobel.');
        $this->assertEqualsWithDelta(5_000_000, $delta('paid'), 0.01);
        $this->assertEqualsWithDelta(8_000_000, $delta('unpaid_billed', 'amount'), 0.01);
        $this->assertEqualsWithDelta(3_000_000, $delta('unbilled', 'amount'), 0.01, 'Sisa DP project B belum ditagih.');
        $this->assertSame(1, $delta('unbilled', 'count'));

        $this->assertEqualsWithDelta(5_000_000, $delta('kinds', 'dp', 'billed'), 0.01, 'DP 2 juta + 3 juta.');
        $this->assertEqualsWithDelta(5_000_000, $delta('kinds', 'dp', 'paid'), 0.01);
        $this->assertEqualsWithDelta(8_000_000, $delta('kinds', 'final', 'unpaid'), 0.01);
    }

    public function test_pelunasan_lama_tanpa_tautan_dipasangkan_ke_dp_klien_yang_sama(): void
    {
        $before = $this->overview()->invoiceBreakdown();
        $klien  = 'PT Pasangan ' . Str::random(6);

        // Invoice lama: pelunasan dibuat sebelum ada kolom parent_invoice_id.
        $this->invoice(['type_payment' => 'dp_30', 'grand_total' => 10_000_000, 'paid' => 3_000_000, 'status' => 'paid', 'nama_perusahaan' => $klien, 'date' => today()->subDays(30)]);
        $this->invoice(['type_payment' => 'final_payment', 'grand_total' => 7_000_000, 'status' => 'pending', 'nama_perusahaan' => $klien, 'date' => today()->subDays(5)]);

        $after = $this->overview()->invoiceBreakdown();

        $this->assertEqualsWithDelta(10_000_000, $after['contract_value'] - $before['contract_value'], 0.01, 'Bukan 17 juta.');
        $this->assertEqualsWithDelta(0, $after['unbilled']['amount'] - $before['unbilled']['amount'], 0.01, 'Sisanya sudah ditagih lewat pelunasan.');
        $this->assertSame($before['unlinked_finals'], $after['unlinked_finals']);
    }

    public function test_pelunasan_yang_ambigu_dilaporkan_bukan_ditebak(): void
    {
        $before = $this->overview()->invoiceBreakdown();
        $klien  = 'PT Ambigu ' . Str::random(6);

        $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 4_000_000, 'paid' => 2_000_000, 'status' => 'paid', 'nama_perusahaan' => $klien]);
        $this->invoice(['type_payment' => 'dp_50', 'grand_total' => 6_000_000, 'paid' => 3_000_000, 'status' => 'paid', 'nama_perusahaan' => $klien]);
        $this->invoice(['type_payment' => 'final_payment', 'grand_total' => 2_000_000, 'status' => 'pending', 'nama_perusahaan' => $klien]);

        $after = $this->overview()->invoiceBreakdown();

        $this->assertSame(1, $after['unlinked_finals'] - $before['unlinked_finals']);
    }

    public function test_expense_dan_advance_dirinci_per_kategori_dan_project(): void
    {
        $label   = 'PT Laporan | Tes Laporan';
        $find    = fn ($rows, string $label) => $rows->firstWhere('label', $label) ?? ['count' => 0, 'amount' => 0.0, 'disbursed' => 0.0, 'realized' => 0.0, 'outstanding' => 0.0];
        $catBefore = $find($this->overview()->expenseByCategory(), 'Software');

        $this->expense('Selesai', 150_000, paid: 150_000, category: 'Software');
        $this->expense('Pending', 50_000, category: 'Software');
        $this->expense('Ditolak', 999_000, category: 'Software');

        $open = $this->advance(Advance::STATUS_DIBAYARKAN, 1_000_000);
        app(RealisasiService::class)->createBatch($open, ['uploaded_by' => $this->user->id], [['name' => 'Kamera', 'quantity' => 1, 'price' => 250_000]]);

        $category = $find($this->overview()->expenseByCategory(), 'Software');
        $this->assertSame(2, $category['count'] - $catBefore['count'], 'Yang ditolak tidak dihitung.');
        $this->assertEqualsWithDelta(200_000, $category['amount'] - $catBefore['amount'], 0.01);

        $expenseProject = $find($this->overview()->expenseByProject(), $label);
        $this->assertSame(2, $expenseProject['count']);

        $advanceProject = $find($this->overview()->advanceByProject(), $label);
        $this->assertEqualsWithDelta(1_000_000, $advanceProject['disbursed'], 0.01);
        $this->assertEqualsWithDelta(250_000, $advanceProject['realized'], 0.01);
        $this->assertEqualsWithDelta(750_000, $advanceProject['outstanding'], 0.01);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function overview(): FinanceOverview
    {
        return app(FinanceOverview::class);
    }

    private function assertDelta(float $amount, int $count, array $before, array $after, string $message = ''): void
    {
        $this->assertEqualsWithDelta($amount, $after['amount'] - $before['amount'], 0.01, $message);
        $this->assertSame($count, $after['count'] - $before['count'], $message);
    }

    private function expense(string $status, int $amount, int $paid = 0, string $category = 'Transport'): Expense
    {
        return Expense::create([
            'user_id'            => $this->user->id,
            'submission_date'    => today(),
            'project_id'         => $this->project->id,
            'category'           => $category,
            'keterangan_expense' => "Tes {$status}",
            'amount'             => $amount,
            'paid'               => $paid,
            'status'             => $status,
        ]);
    }

    private function advance(string $status, int $amount): Advance
    {
        return Advance::create([
            'user_id'            => $this->user->id,
            'submission_date'    => today(),
            'project_id'         => $this->project->id,
            'category'           => 'Project Support',
            'keterangan_advance' => "Tes {$status}",
            'amount'             => $amount,
            'paid'               => $status === Advance::STATUS_PENDING ? 0 : $amount,
            'status'             => $status,
        ]);
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => 'TES/' . Str::upper(Str::random(8)),
            'user_id'         => $this->user->id,
            'type'            => 'sale',
            'nama_perusahaan' => 'PT Laporan',
            'paid'            => 0,
        ]);
    }
}
