<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\User;
use App\Services\RealisasiService;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pengingat terjadwal dari bot: antrean harian untuk Director/Finance dan
 * pengingat realisasi advance ke karyawan.
 */
class ScheduledRemindersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        IntegrationSettings::fake([
            'gemini_api_key'          => null,
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '111:PENGAJUAN',
            'telegram_webhook_secret' => 'rahasia-bot-pengajuan',
            'finance_bot_token'       => '222:FINANCE',
            'finance_webhook_secret'  => 'rahasia-bot-finance',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_antrean_harian_dikirim_ke_finance_dengan_tombol(): void
    {
        $finance = $this->user('Finance', '990000505');
        Expense::create(['user_id' => $this->user(null, null)->id, 'submission_date' => today(), 'category' => 'Transport', 'keterangan_expense' => 'Grab', 'amount' => 123_457, 'paid' => 0, 'status' => 'Pending']);

        $this->artisan('finance:antrean')->assertSuccessful();

        $digest = $this->sent('222:FINANCE')->first(fn ($message) => $message['chat_id'] == $finance->telegram_chat_id && str_contains($message['text'], 'Antrean Finance hari ini'));
        $this->assertNotNull($digest);
        $this->assertStringContainsString('Menunggu persetujuan', $digest['text']);
        $this->assertStringContainsString('apv:list:0', json_encode($digest['reply_markup']));
    }

    public function test_pengingat_realisasi_hanya_untuk_advance_yang_lama_belum_direalisasi(): void
    {
        $budi   = $this->user(null, '990000606');
        $lama   = $this->advance($budi, now()->subDays(10));
        $baru   = $this->advance($budi, now()->subDays(2));
        $aktif  = $this->advance($budi, now()->subDays(12));
        app(RealisasiService::class)->createBatch($aktif, ['uploaded_by' => $budi->id], [['name' => 'Sewa', 'quantity' => 1, 'price' => 100_000]]);

        $this->artisan('advance:pengingat-realisasi')->assertSuccessful();

        $reminder = $this->sent('111:PENGAJUAN')->first(fn ($message) => $message['chat_id'] == $budi->telegram_chat_id && str_contains($message['text'], 'Pengingat realisasi advance'));
        $this->assertNotNull($reminder);
        $this->assertStringContainsString("#{$lama->id}", $reminder['text']);
        $this->assertStringNotContainsString("#{$baru->id} ", $reminder['text'], 'Baru cair 2 hari: belum diingatkan.');
        $this->assertStringNotContainsString("#{$aktif->id} ", $reminder['text'], 'Ada realisasi minggu ini: belum diingatkan.');
        $this->assertStringContainsString("realisasi #{$lama->id}", $reminder['text']);
    }

    private function advance(User $owner, $paidAt): Advance
    {
        return Advance::create(['user_id' => $owner->id, 'submission_date' => $paidAt, 'category' => 'Project Support', 'keterangan_advance' => 'Sewa alat ' . Str::random(4), 'amount' => 500_000, 'paid' => 500_000, 'paid_at' => $paidAt, 'status' => Advance::STATUS_DIBAYARKAN]);
    }

    private function user(?string $role, ?string $chatId): User
    {
        $user = User::forceCreate([
            'username'           => 'rem_' . Str::lower(Str::random(8)),
            'firstname'          => 'Tes',
            'lastname'           => $role ?? 'Karyawan',
            'email'              => Str::lower(Str::random(10)) . '@example.test',
            'password'           => bcrypt('rahasia'),
            'is_active'          => true,
            'push_enabled'       => false,
            'telegram_chat_id'   => $chatId,
            'telegram_linked_at' => $chatId ? now() : null,
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function sent(string $token)
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), "bot{$token}/sendMessage"))
            ->map(fn (Request $request) => $request->data())
            ->values();
    }
}
