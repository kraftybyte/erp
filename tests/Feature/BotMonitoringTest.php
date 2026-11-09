<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GeminiService;
use App\Services\Telegram\AdminAlerts;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pemantauan bot: pemakaian Gemini per hari, peringatan ke super_admin
 * (Gemini gagal, error bot), dan laporan mingguan.
 */
class BotMonitoringTest extends TestCase
{
    use DatabaseTransactions;

    private int $geminiStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        IntegrationSettings::fake([
            'gemini_api_key'          => 'kunci-tes',
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '111:PENGAJUAN',
            'telegram_webhook_secret' => 'rahasia-bot-pengajuan',
            'finance_bot_token'       => '222:FINANCE',
            'finance_webhook_secret'  => 'rahasia-bot-finance',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return $this->geminiStatus === 200
                    ? Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]], 'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 30]])
                    : Http::response(['error' => ['message' => 'credits depleted']], $this->geminiStatus);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
    }

    public function test_pemakaian_gemini_tercatat_per_hari(): void
    {
        $before = DB::table('ai_usages')->where('date', today()->toDateString())->first();

        app(GeminiService::class)->extractJson('tes', ['type' => 'object']);
        app(GeminiService::class)->extractJson('tes', ['type' => 'object']);

        $after = DB::table('ai_usages')->where('date', today()->toDateString())->first();
        $this->assertSame(($before->calls ?? 0) + 2, (int) $after->calls);
        $this->assertSame(($before->input_tokens ?? 0) + 240, (int) $after->input_tokens);
        $this->assertSame(($before->output_tokens ?? 0) + 60, (int) $after->output_tokens);
    }

    public function test_gemini_gagal_dikabarkan_sekali_ke_super_admin(): void
    {
        Cache::forget('admin-alert:gemini-402');
        $admin              = $this->user('super_admin', '990000707');
        $this->geminiStatus = 402;

        app(GeminiService::class)->extractJson('tes', ['type' => 'object']);
        app(GeminiService::class)->extractJson('tes', ['type' => 'object']);

        $alerts = $this->sentTo($admin)->filter(fn ($text) => str_contains($text, 'Gemini gagal'));
        $this->assertCount(1, $alerts, 'Kegagalan berulang tidak membanjiri chat.');
        $this->assertStringContainsString('Saldo prabayar Gemini habis', $alerts->first());
        $this->assertGreaterThan(0, (int) DB::table('ai_usages')->where('date', today()->toDateString())->value('failures'));
    }

    public function test_error_bot_dikabarkan_ke_super_admin_tanpa_berulang(): void
    {
        $admin   = $this->user('super_admin', '990000808');
        $message = 'Tes error ' . Str::random(8);

        AdminAlerts::exception(new RuntimeException($message), 'bot pengajuan');
        AdminAlerts::exception(new RuntimeException($message), 'bot pengajuan');

        $alerts = $this->sentTo($admin)->filter(fn ($text) => str_contains($text, $message));
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Error di bot pengajuan', $alerts->first());
    }

    public function test_laporan_mingguan_dikirim_ke_director(): void
    {
        $director = $this->user('Director', '990000909');

        $this->artisan('bot:laporan-mingguan')->assertSuccessful();

        $report = $this->sentTo($director)->first(fn ($text) => str_contains($text, 'Laporan mingguan bot'));
        $this->assertNotNull($report);
        $this->assertStringContainsString('Gemini', $report);
        $this->assertStringContainsString('Perkiraan biaya: US$', $report);
    }

    private function user(string $role, string $chatId): User
    {
        $user = User::forceCreate([
            'username'           => 'mon_' . Str::lower(Str::random(8)),
            'firstname'          => 'Tes',
            'lastname'           => $role,
            'email'              => Str::lower(Str::random(10)) . '@example.test',
            'password'           => bcrypt('rahasia'),
            'is_active'          => true,
            'push_enabled'       => false,
            'telegram_chat_id'   => $chatId,
            'telegram_linked_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function sentTo(User $user)
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && ($request->data()['chat_id'] ?? null) == $user->telegram_chat_id)
            ->map(fn (Request $request) => $request->data()['text'])
            ->values();
    }
}
