<?php

namespace Tests\Feature;

use App\Filament\Pages\Setting\ManageIntegrations;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\Telegram\TelegramWebhookManager;
use App\Settings\IntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Settings → Integrasi: sumber kunci Gemini & Telegram, pendaftaran webhook,
 * dan halaman Filament-nya.
 */
class IntegrationSettingsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['app.url' => 'https://erp.test']);
    }

    public function test_isian_settings_diutamakan_dan_yang_kosong_memakai_env(): void
    {
        config(['services.gemini.key' => 'dari-env', 'services.telegram.bot_token' => 'env:TOKEN']);

        $this->fakeSettings(['telegram_bot_token' => 'settings:TOKEN', 'gemini_api_key' => null]);

        $this->assertSame('settings:TOKEN', IntegrationSettings::telegramBotToken());
        $this->assertSame('dari-env', IntegrationSettings::geminiApiKey());
    }

    public function test_daftar_webhook_menyimpan_secret_yang_juga_diterima_webhook(): void
    {
        $this->fakeSettings(['telegram_bot_token' => '123:TES', 'telegram_webhook_secret' => null]);
        $this->fakeTelegram();

        $url = app(TelegramWebhookManager::class)->register();

        $secret = IntegrationSettings::telegramWebhookSecret();
        $this->assertSame('https://erp.test/api/telegram/webhook', $url);
        $this->assertSame(64, strlen($secret));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === $url
            && $request['secret_token'] === $secret);

        // Webhook menerima secret yang baru saja didaftarkan.
        $this->postJson('/api/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => $secret])
            ->assertOk();
    }

    public function test_daftar_webhook_ditolak_bila_app_url_bukan_https(): void
    {
        $this->fakeSettings(['telegram_bot_token' => '123:TES']);
        $this->fakeTelegram();
        config(['app.url' => 'http://localhost']);

        // Bukan try + fail(): exception dari fail() juga turunan RuntimeException.
        $error = null;
        try {
            app(TelegramWebhookManager::class)->register();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        $this->assertStringContainsString('HTTPS', (string) $error);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook'));
    }

    public function test_tes_koneksi_gemini_menjelaskan_penyebab_gagal(): void
    {
        Http::fake([
            '*gemini-habis*' => Http::response(['error' => ['message' => 'Your prepayment credits are depleted.']], 402),
            '*gemini-salah*' => Http::response(['error' => ['message' => 'models/gemini-salah is not found']], 404),
            '*'              => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'OK']]]]]]),
        ]);

        $gemini = app(GeminiService::class);

        $this->assertStringContainsString('Saldo', $gemini->testConnection('key', 'gemini-habis'));
        $this->assertStringContainsString('tidak ditemukan', $gemini->testConnection('key', 'gemini-salah'));
        $this->assertNull($gemini->testConnection('key', 'gemini-3.5-flash-lite'));
    }

    public function test_halaman_tidak_mengirim_kunci_ke_browser_dan_isian_kosong_tidak_menghapus(): void
    {
        $this->fakeSettings(['gemini_api_key' => 'kunci-tiruan-tes-1234', 'telegram_bot_token' => null]);
        $this->fakeTelegram();

        Livewire::actingAs($this->admin())
            ->test(ManageIntegrations::class)
            ->assertSet('data.gemini_api_key', null)
            ->assertDontSee('kunci-tiruan-tes-1234')
            ->assertSee('…1234')
            ->set('data.gemini_model', 'gemini-3.5-flash')
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(IntegrationSettings::class);
        $this->assertSame('kunci-tiruan-tes-1234', $settings->gemini_api_key);
        $this->assertSame('gemini-3.5-flash', $settings->gemini_model);
    }

    public function test_menyimpan_token_baru_langsung_mendaftarkan_webhook(): void
    {
        $this->fakeSettings(['telegram_bot_token' => null]);
        config(['services.telegram.bot_token' => null]);
        $this->fakeTelegram();

        Livewire::actingAs($this->admin())
            ->test(ManageIntegrations::class)
            ->set('data.telegram_bot_token', '999:BARU')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.telegram_bot_token', null);

        $this->assertSame('999:BARU', app(IntegrationSettings::class)->telegram_bot_token);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bot999:BARU/setWebhook'));
    }

    public function test_hanya_admin_yang_bisa_membuka_halaman(): void
    {
        $this->fakeSettings([]);

        $staff = $this->user();

        $this->actingAs($staff);
        $this->assertFalse(ManageIntegrations::canAccess());

        $this->actingAs($this->admin());
        $this->assertTrue(ManageIntegrations::canAccess());
    }

    // =================================================================
    // Helper
    // =================================================================

    private function fakeSettings(array $values): void
    {
        IntegrationSettings::fake($values + [
            'gemini_api_key'          => null,
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => null,
            'telegram_webhook_secret' => null,
        ]);
    }

    private function fakeTelegram(): void
    {
        Http::fake([
            'api.telegram.org/*/getMe'          => Http::response(['ok' => true, 'result' => ['username' => 'erp_tes_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]]),
            'api.telegram.org/*'                => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    private function user(): User
    {
        return User::forceCreate([
            'username'          => 'int_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => 'Integrasi',
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'email_verified_at' => now(),
            'password'          => bcrypt('rahasia'),
            'is_active'         => true,
            'push_enabled'      => false,
        ]);
    }

    private function admin(): User
    {
        return tap($this->user())->assignRole('super_admin');
    }
}
