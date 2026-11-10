<?php

namespace Tests\Feature;

use App\Exceptions\PayoutException;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\PayslipResource\Pages\ListPayslips;
use App\Models\PaymentAccount;
use App\Models\PayrollComponent;
use App\Models\Payslip;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payroll;
use App\Settings\GeneralSettings;
use App\Settings\IntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gaji: komponen tetap per karyawan, slip draft per bulan dengan penyesuaian,
 * final & kirim ke Telegram, bayar sebagai uang keluar kategori Gaji, jadwal
 * dari Settings, dan akses (karyawan hanya slipnya sendiri yang final).
 */
class PayrollTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'rahasia-webhook-gaji';

    private int $chat = 880_000_000;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-25 07:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        IntegrationSettings::fake([
            'gemini_api_key'          => null,
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '111:PENGAJUAN',
            'telegram_webhook_secret' => self::SECRET,
            'finance_bot_token'       => '222:FINANCE',
            'finance_webhook_secret'  => 'b',
        ]);
        config(['services.gemini.key' => null]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        // Hanya karyawan tes yang punya komponen gaji.
        PayrollComponent::query()->delete();

        $this->account = PaymentAccount::create(['name' => 'Rek Gaji ' . Str::random(4), 'bank_name' => 'BCA', 'account_number' => (string) random_int(1_000_000_000, 9_999_999_999), 'account_holder' => 'Tes', 'initial_balance' => 50_000_000, 'is_active' => true]);
    }

    public function test_slip_draft_dari_komponen_dan_penyesuaian_tetap_saat_dimuat_ulang(): void
    {
        $budi = $this->employee('Budi', [
            ['Gaji pokok', 'earning', 5_000_000],
            ['Tunjangan transport', 'earning', 500_000],
            ['BPJS Kesehatan', 'deduction', 50_000],
            ['PPh 21', 'deduction', 100_000],
        ]);
        $this->employee('Keluar', [['Gaji pokok', 'earning', 4_000_000]], active: false);
        $this->employee('Tanpa', []);

        $created = app(Payroll::class)->generate(Carbon::parse('2026-11-10'));

        $this->assertSame([$budi->id], $created->pluck('user_id')->all(), 'Hanya karyawan aktif yang punya komponen.');
        $slip = $created->first()->fresh('items');
        $this->assertSame('2026-11-01', $slip->period->toDateString());
        $this->assertSame(Payslip::DRAFT, $slip->status);
        $this->assertSame('Budi Tes', $slip->employee_name);
        $this->assertEquals([5_500_000, 150_000, 5_350_000], [(float) $slip->earnings, (float) $slip->deductions, (float) $slip->net]);
        $this->assertSame(['Gaji pokok', 'Tunjangan transport', 'BPJS Kesehatan', 'PPh 21'], $slip->items->pluck('name')->all());

        $this->assertTrue(app(Payroll::class)->generate(Carbon::parse('2026-11-01'))->isEmpty(), 'Bulan yang sama tidak dibuat dua kali.');

        // Penyesuaian bulan ini, lalu gaji pokok naik dan komponen dimuat ulang.
        $slip->items()->create(['name' => 'Lembur 6 jam', 'type' => 'earning', 'amount' => 300_000, 'sort' => 9]);
        $budi->payrollComponents()->where('name', 'Gaji pokok')->update(['amount' => 6_000_000]);
        app(Payroll::class)->refresh($slip);

        $slip->refresh();
        $this->assertSame(['Gaji pokok', 'Tunjangan transport', 'BPJS Kesehatan', 'PPh 21', 'Lembur 6 jam'], $slip->items->pluck('name')->all());
        $this->assertEquals(6_650_000, (float) $slip->net);
    }

    public function test_final_dikirim_ke_telegram_lalu_dibayar_sebagai_uang_keluar(): void
    {
        $budi = $this->employee('Budi', [['Gaji pokok', 'earning', 5_000_000], ['BPJS', 'deduction', 100_000]]);
        $slip = app(Payroll::class)->generate(Carbon::parse('2026-11-01'))->first();

        app(Payroll::class)->finalize($slip, $this->user('Finance'));

        $slip->refresh();
        $this->assertSame(Payslip::FINAL, $slip->status);
        $this->assertNotNull($slip->sent_at);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bot111:PENGAJUAN/sendDocument')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'chat_id' && (string) $part['contents'] === (string) $budi->telegram_chat_id));

        $before = $this->account->fresh()->getAvailableBalance();
        app(Payroll::class)->pay($slip, $this->account, $this->user('Finance'), Carbon::parse('2026-11-25'));

        $slip->refresh();
        $this->assertSame(Payslip::PAID, $slip->status);
        $transaction = Transaction::where('reference_type', 'payslip')->where('reference_id', $slip->id)->sole();
        $this->assertSame(['out', 'payroll', 4_900_000.0], [$transaction->type, $transaction->category, (float) $transaction->amount]);
        $this->assertEqualsWithDelta($before - 4_900_000, $this->account->fresh()->getAvailableBalance(), 1);

        $this->expectException(PayoutException::class);
        app(Payroll::class)->pay($slip, $this->account, null);
    }

    public function test_jadwal_otomatis_mengikuti_settings(): void
    {
        $budi    = $this->employee('Budi', [['Gaji pokok', 'earning', 5_000_000]]);
        $manager = $this->user('Finance', chat: true);

        // Dimatikan: tidak ada yang dibuat.
        $this->settings(auto: false);
        $this->artisan('gaji:buat')->assertSuccessful();
        $this->assertSame(0, Payslip::where('user_id', $budi->id)->count());

        // Menyala, tanggal 25, dicek Finance dulu: draft dan Finance diberi tahu.
        $this->settings(auto: true, review: true);
        $this->artisan('gaji:buat')->assertSuccessful();
        $this->assertSame(Payslip::DRAFT, Payslip::where('user_id', $budi->id)->sole()->status);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sendMessage')
            && ($request['chat_id'] ?? null) == $manager->telegram_chat_id && str_contains((string) $request['text'], 'Draft slip gaji November 2026'));

        // Bukan tanggalnya: tidak jalan.
        $this->travelTo('2026-12-24 07:00:00');
        $this->artisan('gaji:buat')->assertSuccessful();
        $this->assertSame(1, Payslip::where('user_id', $budi->id)->count());

        // Tanpa dicek: langsung final dan dikirim.
        $this->travelTo('2026-12-25 07:00:00');
        $this->settings(auto: true, review: false);
        $this->artisan('gaji:buat')->assertSuccessful();
        $december = Payslip::where('user_id', $budi->id)->whereDate('period', '2026-12-01')->sole();
        $this->assertSame(Payslip::FINAL, $december->status);
        $this->assertNotNull($december->sent_at);
    }

    public function test_karyawan_hanya_melihat_dan_mengunduh_slipnya_sendiri_yang_final(): void
    {
        $budi  = $this->employee('Budi', [['Gaji pokok', 'earning', 5_000_000]]);
        $sari  = $this->employee('Sari', [['Gaji pokok', 'earning', 7_000_000]]);
        $slips = app(Payroll::class)->generate(Carbon::parse('2026-11-01'))->keyBy('user_id');
        $draft = app(Payroll::class)->generate(Carbon::parse('2026-12-01'))->keyBy('user_id');
        app(Payroll::class)->finalize($slips[$budi->id], null);
        app(Payroll::class)->finalize($slips[$sari->id], null);

        $this->actingAs($budi);
        Livewire::test(ListPayslips::class)
            ->assertCanSeeTableRecords([$slips[$budi->id]])
            ->assertCanNotSeeTableRecords([$slips[$sari->id], $draft[$budi->id]])
            ->assertActionHidden('generate');

        $this->get(route('payslip.pdf', $slips[$budi->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('payslip.pdf', $slips[$sari->id]))->assertForbidden();
        $this->get(route('payslip.pdf', $draft[$budi->id]))->assertForbidden();

        $this->actingAs($this->user('Finance'));
        Livewire::test(ListPayslips::class)->assertCanSeeTableRecords([$slips[$sari->id], $draft[$budi->id]]);
        $this->get(route('payslip.pdf', $draft[$budi->id]))->assertOk();
    }

    public function test_finance_mengisi_komponen_gaji_di_form_karyawan(): void
    {
        $employee = $this->employee('Rina', []);
        $this->actingAs($this->user('super_admin'));

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertSee('Komponen gaji')
            ->set('data.payrollComponents', [
                Str::uuid()->toString() => ['name' => 'Gaji pokok', 'type' => 'earning', 'amount' => 6_000_000],
                Str::uuid()->toString() => ['name' => 'BPJS Kesehatan', 'type' => 'deduction', 'amount' => 60_000],
            ])
            ->assertSee('Rp 5.940.000')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Gaji pokok' => 6_000_000.0, 'BPJS Kesehatan' => 60_000.0], $employee->payrollComponents()->get()->mapWithKeys(fn ($c) => [$c->name => (float) $c->amount])->all());
    }

    public function test_karyawan_meminta_slip_lewat_bot(): void
    {
        $budi = $this->employee('Budi', [['Gaji pokok', 'earning', 5_000_000]]);
        app(Payroll::class)->finalize(app(Payroll::class)->generate(Carbon::parse('2026-11-01'))->first(), null);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 990_001,
            'message'   => ['message_id' => 5, 'chat' => ['id' => (int) $budi->telegram_chat_id, 'type' => 'private'], 'from' => ['id' => (int) $budi->telegram_chat_id, 'is_bot' => false, 'first_name' => 'Budi'], 'text' => '/slip'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bot111:PENGAJUAN/sendDocument'));
    }

    private function settings(bool $auto, bool $review = true): void
    {
        $settings = app(GeneralSettings::class);
        $settings->payroll_auto_generate = $auto;
        $settings->payroll_generate_day  = 25;
        $settings->payroll_review        = $review;
        $settings->payroll_send_telegram = true;
        $settings->save();
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int}>  $components
     */
    private function employee(string $name, array $components, bool $active = true): User
    {
        $user = $this->user('Staff', chat: true, name: $name, active: $active);

        foreach ($components as $i => [$label, $type, $amount]) {
            $user->payrollComponents()->create(['name' => $label, 'type' => $type, 'amount' => $amount, 'sort' => $i + 1]);
        }

        return $user;
    }

    private function user(string $role, bool $chat = false, string $name = 'Tes', bool $active = true): User
    {
        $user = User::forceCreate([
            'username'          => 'py_' . Str::lower(Str::random(8)),
            'firstname'         => $name,
            'lastname'          => 'Tes',
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'is_active'         => $active,
            'push_enabled'      => false,
            'email_verified_at' => now(),
            'nik'               => (string) random_int(1_000_000_000_000_000, 9_999_999_999_999_999),
            'phone'             => '6281234567890',
            'address'           => 'Jl. Tes',
            'position'          => 'Staff',
            'division'          => 'Finance',
            'telegram_chat_id'  => $chat ? (string) ++$this->chat : null,
            'telegram_linked_at' => $chat ? now() : null,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
