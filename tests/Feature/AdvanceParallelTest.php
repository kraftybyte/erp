<?php

namespace Tests\Feature;

use App\Exceptions\PayoutException;
use App\Filament\Resources\AdvanceResource\Pages\RealisasiAdvance;
use App\Models\Advance;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AdvanceParallel;
use App\Services\BankStatement\MutationMatcher;
use App\Services\FinanceOverview;
use App\Services\Payout;
use App\Services\RealisasiService;
use App\Services\Telegram\OpenAdvances;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Advance bayar paralel (petty cash): realisasi boleh sebelum advance dibayar,
 * pembayaran boleh bertahap sampai sama dengan realisasi, dan advance selesai
 * tanpa pengembalian sisa. Tanpa setting, alurnya tetap bayar di depan.
 */
class AdvanceParallelTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private User $finance;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::fake();

        $this->owner   = $this->user('Staff');
        $this->finance = $this->user('Finance');
        $this->account = PaymentAccount::create(['name' => 'Kas ' . Str::random(4), 'bank_name' => 'BCA', 'account_number' => (string) random_int(1_000_000_000, 9_999_999_999), 'account_holder' => 'Tes', 'initial_balance' => 50_000_000, 'is_active' => true]);
    }

    public function test_realisasi_dulu_lalu_dibayar_bertahap_sampai_selesai(): void
    {
        $this->parallel(true);
        $advance = $this->advance(5_000_000);
        $this->assertTrue($advance->pay_parallel, 'Advance baru ditandai selama setting menyala.');

        // Struk masuk sebelum ada pembayaran.
        $this->assertTrue($advance->canRealisasi());
        $this->batch($advance, 1_200_000);

        $advance->refresh();
        $this->assertSame(Advance::STATUS_REALIZED_USER, $advance->status);
        $evaluation = app(RealisasiService::class)->evaluateStatus($advance);
        $this->assertSame('unpaid', $evaluation['status']);
        $this->assertEqualsWithDelta(1_200_000, $evaluation['unpaid'], 1);
        $this->assertEqualsWithDelta(1_200_000, AdvanceParallel::suggested($advance), 1);
        $this->assertFalse(app(RealisasiService::class)->isReadyToClose($advance));

        // Dibayar bertahap: status realisasinya tidak berubah, paid menjumlahkan.
        $this->pay($advance, 1_000_000);
        $this->pay($advance, 200_000);

        $advance->refresh();
        $this->assertSame(Advance::STATUS_REALIZED_USER, $advance->status);
        $this->assertEqualsWithDelta(1_200_000, (float) $advance->paid, 1);
        $this->assertSame(2, Transaction::where('reference_type', 'advance')->where('reference_id', $advance->id)->where('type', 'out')->count());
        $this->assertSame('exact_match', app(RealisasiService::class)->evaluateStatus($advance)['status']);

        // Tidak boleh melebihi yang disetujui.
        try {
            $this->pay($advance, 4_000_000);
            $this->fail('Pembayaran melebihi yang disetujui harus ditolak.');
        } catch (PayoutException $e) {
            $this->assertStringContainsString('Rp 3.800.000', $e->getMessage());
        }

        $this->actingAs($this->finance);
        app(RealisasiService::class)->finishAdvance($advance->fresh());
        $this->assertSame(Advance::STATUS_SELESAI, $advance->fresh()->status);
    }

    public function test_tanpa_setting_tetap_bayar_di_depan(): void
    {
        $this->parallel(false);
        $advance = $this->advance(2_000_000);

        $this->assertFalse($advance->pay_parallel);
        $this->assertFalse($advance->canRealisasi());

        try {
            $this->batch($advance, 500_000);
            $this->fail('Realisasi sebelum dibayar harus ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(RealisasiService::ERROR_ADVANCE_NOT_PAYABLE, $e->getMessage());
        }

        $this->pay($advance, 2_000_000);
        $this->assertSame(Advance::STATUS_DIBAYARKAN, $advance->fresh()->status);

        $this->expectExceptionMessage('Pengajuan ini sudah dibayarkan.');
        $this->pay($advance, 100_000);
    }

    public function test_advance_lama_ikut_bayar_paralel_dan_tetap_berlaku_setelah_setting_dimatikan(): void
    {
        $this->parallel(false);
        $advance = $this->advance(3_000_000);

        $this->parallel(true);
        $this->batch($advance, 700_000);
        $this->assertTrue($advance->fresh()->pay_parallel, 'Realisasi sebelum dibayar menandai advance lama.');

        // Setting dimatikan: advance yang sudah berjalan tetap bisa dibayar dan diselesaikan.
        $this->parallel(false);
        $this->pay($advance, 700_000);

        $this->assertSame('exact_match', app(RealisasiService::class)->evaluateStatus($advance->fresh())['status']);
    }

    public function test_kelebihan_dari_yang_disetujui_perlu_persetujuan_lalu_dibayar(): void
    {
        $this->parallel(true);
        $advance = $this->advance(1_000_000);
        $this->batch($advance, 1_300_000);

        $advance->refresh();
        $evaluation = app(RealisasiService::class)->evaluateStatus($advance);
        $this->assertSame('over_run', $evaluation['status']);
        $this->assertEqualsWithDelta(300_000, $evaluation['overrun'], 1);
        $this->assertFalse($advance->canRealisasi(), 'Realisasi berikutnya ditahan sampai kelebihannya disetujui.');
        $this->assertEqualsWithDelta(1_000_000, AdvanceParallel::payable($advance), 1);

        $this->actingAs($this->finance);
        app(RealisasiService::class)->approveOverrun($advance);

        $advance->refresh();
        $this->assertEqualsWithDelta(1_300_000, AdvanceParallel::payable($advance), 1);
        $this->assertSame('unpaid', app(RealisasiService::class)->evaluateStatus($advance)['status']);

        $this->pay($advance, 1_300_000);
        $this->assertTrue(app(RealisasiService::class)->isReadyToClose($advance->fresh()));
    }

    public function test_laporan_memisahkan_realisasi_yang_belum_dibayar(): void
    {
        $before = app(FinanceOverview::class)->advances();

        $this->parallel(true);
        $advance = $this->advance(4_000_000);
        $this->batch($advance, 800_000);

        $after = app(FinanceOverview::class)->advances();

        $this->assertEqualsWithDelta(800_000, $after['owed']['amount'] - $before['owed']['amount'], 1);
        $this->assertSame(0, $after['disbursed']['count'] - $before['disbursed']['count'], 'Belum dibayar: bukan dicairkan.');
        $this->assertSame(1, $after['approved']['count'] - $before['approved']['count']);
        $this->assertEqualsWithDelta(0, $after['overrun']['amount'] - $before['overrun']['amount'], 1, 'Belum dibayar bukan kelebihan realisasi.');
        $this->assertEqualsWithDelta(0, $after['outstanding']['amount'] - $before['outstanding']['amount'], 1);

        // Setelah dibayar sebagian: masuk dicairkan sebesar yang dibayar.
        $this->pay($advance, 500_000);
        $paid = app(FinanceOverview::class)->advances();
        $this->assertEqualsWithDelta(500_000, $paid['disbursed']['amount'] - $before['disbursed']['amount'], 1);
        $this->assertEqualsWithDelta(300_000, $paid['owed']['amount'] - $before['owed']['amount'], 1);
    }

    public function test_bot_dan_mutasi_mengenali_advance_bayar_paralel(): void
    {
        $this->parallel(true);
        $advance = $this->advance(2_500_000);

        // Disetujui dan belum dibayar: sudah bisa dipilih untuk realisasi di bot.
        $this->assertTrue(app(OpenAdvances::class)->forUser($this->owner)->contains('id', $advance->id));

        $this->batch($advance, 600_000);
        $this->pay($advance, 100_000);

        // Transfer ke karyawan sebesar realisasi yang belum dibayar.
        $payee = MutationMatcher::payees()->first(fn ($payee) => $payee instanceof Advance && $payee->id === $advance->id);
        $this->assertNotNull($payee);
        $this->assertEqualsWithDelta(500_000, MutationMatcher::payeeAmount($payee), 1);
        $this->assertEqualsWithDelta(2_400_000, MutationMatcher::payeeLimit($payee), 1);

        // Setting mati, advance tanpa tanda: tidak ditawarkan sebelum dibayar.
        $this->parallel(false);
        $legacy = $this->advance(1_000_000);
        $this->assertFalse(app(OpenAdvances::class)->forUser($this->owner)->contains('id', $legacy->id));
    }

    public function test_halaman_realisasi_finance_membayar_dan_hapus_batch_kembali_ke_disetujui(): void
    {
        $this->parallel(true);
        $advance = $this->advance(3_000_000);
        $this->batch($advance, 900_000);

        $this->actingAs($this->finance);
        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
            ->assertActionVisible('pay')
            ->assertActionHidden('finish')
            ->assertSee('Belum dibayar')
            ->callAction('pay', ['paid_at' => today()->toDateString(), 'amount' => 900_000, 'payment_account_id' => $this->account->id])
            ->assertHasNoActionErrors()
            ->assertActionHidden('pay')
            ->assertActionVisible('finish');

        $this->assertEqualsWithDelta(900_000, (float) $advance->fresh()->paid, 1);

        // Advance lain: batch terakhir dihapus sebelum dibayar → kembali Disetujui.
        $other = $this->advance(1_000_000);
        $batch = $this->batch($other, 400_000);
        app(RealisasiService::class)->deleteBatch($batch);
        $this->assertSame(Advance::STATUS_DISETUJUI, $other->fresh()->status);
    }

    private function parallel(bool $on): void
    {
        $settings = app(GeneralSettings::class);
        $settings->advance_parallel_payment = $on;
        $settings->save();
    }

    private function advance(int $amount): Advance
    {
        return Advance::create([
            'user_id'            => $this->owner->id,
            'submission_date'    => today(),
            'category'           => 'Project Support',
            'keterangan_advance' => 'Petty cash ' . Str::random(4),
            'amount'             => $amount,
            'status'             => Advance::STATUS_DISETUJUI,
        ])->fresh();
    }

    private function batch(Advance $advance, int $price): \App\Models\Realisasi
    {
        return app(RealisasiService::class)->createBatch($advance->fresh(), ['uploaded_by' => $this->owner->id], [['name' => 'Belanja', 'quantity' => 1, 'price' => $price]]);
    }

    private function pay(Advance $advance, int $amount): void
    {
        app(Payout::class)->advance($advance->fresh(), $amount, $this->account, $this->finance);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'ap_' . Str::lower(Str::random(8)),
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
}
