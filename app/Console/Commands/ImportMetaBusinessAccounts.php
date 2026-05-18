<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\MetaAdAccount;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportMetaBusinessAccounts extends Command
{
    protected $signature = 'meta:import-accounts
                            {--business-id= : Meta Business Manager ID}
                            {--token= : User Access Token}
                            {--deal-id= : ID deal/klien default jika tidak ditemukan}';

    protected $description = 'Import semua Ad Accounts, Facebook Pages, dan Instagram dari Meta Business Manager';

    private string $baseUrl = 'https://graph.facebook.com/v19.0';
    private string $token = '';
    private string $businessId = '';

    public function handle(): void
    {
        $this->token      = (string) ($this->option('token') ?? config('services.meta.default_token', ''));
        $this->businessId = (string) ($this->option('business-id') ?? config('services.meta.business_id', ''));

        if (!$this->token || !$this->businessId) {
            $this->error('Token dan Business ID wajib diisi!');
            $this->line('Jalankan: php artisan meta:import-accounts --business-id=XXXX --token=XXXX');
            return;
        }

        $this->info('🚀 Import Meta Business Accounts dimulai...');
        $this->newLine();

        $this->importAdAccounts();
        $this->newLine();
        $this->importPages();

        $this->newLine();
        $this->info('✅ Import selesai!');
        $this->line('Cek dashboard: Meta Ads Accounts & Social Accounts');
    }

    // ─────────────────────────────────────────
    // IMPORT AD ACCOUNTS
    // ─────────────────────────────────────────
    private function importAdAccounts(): void
    {
        $this->info('📢 Mengambil Ad Accounts dari Business Manager...');

        $response = Http::get("{$this->baseUrl}/{$this->businessId}/owned_ad_accounts", [
            'fields'       => 'id,name,account_status,currency,business',
            'access_token' => $this->token,
            'limit'        => 100,
        ]);

        if ($response->failed() || isset($response->json()['error'])) {
            $this->error('Gagal ambil Ad Accounts: ' . $response->body());
            return;
        }

        $accounts = $response->json()['data'] ?? [];

        if (empty($accounts)) {
            $this->line('  Tidak ada Ad Account ditemukan.');
            return;
        }

        $this->line("  Ditemukan " . count($accounts) . " Ad Account.");

        $table = [];

        foreach ($accounts as $account) {
            // Cari deal berdasarkan nama akun, kalau tidak ada buat placeholder
            $deal = $this->findOrCreateDeal($account['name']);

            $existing = MetaAdAccount::where('ad_account_id', $account['id'])->first();

            if ($existing) {
                $existing->update([
                    'account_name' => $account['name'],
                    'is_active'    => $account['account_status'] === 1,
                ]);
                $status = '🔄 Updated';
            } else {
                MetaAdAccount::create([
                    'client_id'      => $deal->id,
                    'ad_account_id'  => $account['id'],
                    'account_name'   => $account['name'],
                    'access_token'   => $this->token,
                    'is_active'      => $account['account_status'] === 1,
                ]);
                $status = '✅ Imported';
            }

            $table[] = [
                $status,
                $account['id'],
                $account['name'],
                $account['account_status'] === 1 ? 'Active' : 'Inactive',
                $deal->nama_perusahaan,
            ];
        }

        $this->table(
            ['Status', 'Ad Account ID', 'Nama', 'Status Akun', 'Klien'],
            $table
        );
    }

    // ─────────────────────────────────────────
    // IMPORT FACEBOOK PAGES + INSTAGRAM
    // ─────────────────────────────────────────
    private function importPages(): void
    {
        $this->info('📄 Mengambil Facebook Pages dari Business Manager...');

        $response = Http::get("{$this->baseUrl}/{$this->businessId}/owned_pages", [
            'fields'       => 'id,name,access_token,fan_count,followers_count,instagram_business_account',
            'access_token' => $this->token,
            'limit'        => 100,
        ]);

        if ($response->failed() || isset($response->json()['error'])) {
            $this->error('Gagal ambil Pages: ' . $response->body());
            return;
        }

        $pages = $response->json()['data'] ?? [];

        if (empty($pages)) {
            $this->line('  Tidak ada Facebook Page ditemukan.');
            return;
        }

        $this->line("  Ditemukan " . count($pages) . " Facebook Page.");

        $table = [];

        foreach ($pages as $page) {
            $deal      = $this->findOrCreateDeal($page['name']);
            $pageToken = $page['access_token'] ?? $this->token;

            // Exchange page token ke long-lived
            $longLivedToken = $this->exchangeToLongLived($pageToken);

            // Import Facebook Page
            $fbStatus = $this->importFacebookPage($page, $deal, $longLivedToken);
            $table[] = ['FB ' . $fbStatus, $page['id'], $page['name'], $deal->nama_perusahaan];

            // Import Instagram jika ada
            if (isset($page['instagram_business_account']['id'])) {
                $igId     = $page['instagram_business_account']['id'];
                $igStatus = $this->importInstagramAccount($igId, $page['name'], $deal, $longLivedToken);
                $table[] = ['IG ' . $igStatus, $igId, $page['name'] . ' (Instagram)', $deal->nama_perusahaan];
            }
        }

        $this->table(
            ['Status', 'Account ID', 'Nama', 'Klien'],
            $table
        );
    }

    private function importFacebookPage(array $page, Deal $deal, string $token): string
    {
        $existing = SocialAccount::where('platform', 'facebook')
            ->where('platform_account_id', $page['id'])
            ->first();

        $data = [
            'client_id'           => $deal->id,
            'platform'            => 'facebook',
            'platform_account_id' => $page['id'],
            'page_name'           => $page['name'],
            'access_token'        => $token,
            'fan_count'           => $page['fan_count'] ?? 0,
            'followers_count'     => $page['followers_count'] ?? 0,
            'token_expires_at'    => now()->addDays(60),
            'is_active'           => true,
        ];

        if ($existing) {
            $existing->update($data);
            return '🔄 Updated';
        }

        SocialAccount::create($data);
        return '✅ Imported';
    }

    private function importInstagramAccount(string $igId, string $pageName, Deal $deal, string $token): string
    {
        // Ambil info Instagram
        $igResponse = Http::get("{$this->baseUrl}/{$igId}", [
            'fields'       => 'id,username,followers_count',
            'access_token' => $token,
        ]);

        $igData = $igResponse->json();

        $existing = SocialAccount::where('platform', 'instagram')
            ->where('platform_account_id', $igId)
            ->first();

        $data = [
            'client_id'           => $deal->id,
            'platform'            => 'instagram',
            'platform_account_id' => $igId,
            'username'            => $igData['username'] ?? null,
            'page_name'           => $pageName,
            'access_token'        => $token,
            'followers_count'     => $igData['followers_count'] ?? 0,
            'token_expires_at'    => now()->addDays(60),
            'is_active'           => true,
        ];

        if ($existing) {
            $existing->update($data);
            return '🔄 Updated';
        }

        SocialAccount::create($data);
        return '✅ Imported';
    }

    // ─────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────
    private function findOrCreateDeal(string $name): Deal
    {
        // Cari deal yang namanya mirip
        $deal = Deal::where('nama_perusahaan', 'like', '%' . $name . '%')->first();

        if ($deal) {
            return $deal;
        }

        // Kalau ada --deal-id, pakai itu sebagai default
        if ($this->option('deal-id')) {
            $deal = Deal::find($this->option('deal-id'));
            if ($deal) return $deal;
        }

        // Kalau tidak ketemu, buat deal baru sebagai placeholder
        return Deal::create([
            'nama_perusahaan' => $name,
            'nama_pic'        => '-',
            'email'           => strtolower(str_replace(' ', '', $name)) . '@placeholder.com',
            'telephone'       => '-',
            'source'          => 'meta_import',
            'alamat'          => '-',
            'status'          => 'active',
        ]);
    }

    private function exchangeToLongLived(string $token): string
    {
        $response = Http::get("{$this->baseUrl}/oauth/access_token", [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.meta.app_id'),
            'client_secret'     => config('services.meta.app_secret'),
            'fb_exchange_token' => $token,
        ]);

        $data = $response->json();

        return $data['access_token'] ?? $token;
    }
}