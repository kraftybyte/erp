<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RefreshInstagramTokens extends Command
{
    protected $signature   = 'instagram:refresh-tokens {--force : Refresh semua token meski belum mau expired}';
    protected $description = 'Auto-refresh Instagram long-lived tokens sebelum expired';

    public function handle(): void
    {
        $accounts = DB::table('social_accounts')
            ->where('platform', 'instagram')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get(['id', 'username', 'access_token', 'token_expires_at', 'last_synced_at']);

        if ($accounts->isEmpty()) {
            $this->info('Tidak ada akun Instagram aktif.');
            return;
        }

        foreach ($accounts as $account) {
            $this->refreshToken($account);
        }

        $this->info('Selesai refresh token.');
    }

    private function refreshToken(object $account): void
    {
        $label = "[{$account->id}] {$account->username}";

        // Decrypt token pakai DB value langsung
        try {
            $currentToken = Crypt::decryptString($account->access_token);
        } catch (\Exception $e) {
            $this->error("{$label}: Gagal decrypt token — skip.");
            Log::warning("instagram:refresh-tokens decrypt failed [{$account->id}]");
            return;
        }

        // Cek apakah perlu refresh
        if (!$this->option('force') && !$this->needsRefresh($account)) {
            $this->line("{$label}: Token masih fresh, skip.");
            return;
        }

        // Hit Instagram refresh endpoint
        $response = Http::get('https://graph.instagram.com/refresh_access_token', [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $currentToken,
        ]);

        $data = $response->json();

        if (!$response->successful() || isset($data['error'])) {
            $errMsg = $data['error']['message'] ?? 'Unknown error';
            $this->error("{$label}: Gagal refresh — {$errMsg}");
            Log::error("instagram:refresh-tokens failed [{$account->id}]: {$errMsg}");

            DB::table('social_accounts')
                ->where('id', $account->id)
                ->update(['last_sync_error' => "Token refresh failed: {$errMsg}"]);
            return;
        }

        $newToken  = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 5183944;
        $expiresAt = now()->addSeconds($expiresIn)->toDateTimeString();

        DB::table('social_accounts')
            ->where('id', $account->id)
            ->update([
                'access_token'     => Crypt::encryptString($newToken),
                'token_expires_at' => $expiresAt,
                'last_sync_error'  => null,
                'updated_at'       => now()->toDateTimeString(),
            ]);

        $this->info("{$label}: Token direfresh. Expired: {$expiresAt}");
        Log::info("instagram:refresh-tokens success [{$account->id}]: expires {$expiresAt}");
    }

    private function needsRefresh(object $account): bool
    {
        if (!empty($account->token_expires_at)) {
            return \Carbon\Carbon::parse($account->token_expires_at)->diffInDays(now()) <= 30;
        }

        if (!empty($account->last_synced_at)) {
            return \Carbon\Carbon::parse($account->last_synced_at)->diffInDays(now()) >= 30;
        }

        return true;
    }
}