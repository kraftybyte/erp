<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ResetAllTokens extends Command
{
    protected $signature   = 'instagram:reset-tokens {token? : Token baru, default dari META_DEFAULT_TOKEN di .env}';
    protected $description = 'Re-encrypt semua token social account dengan APP_KEY yang aktif sekarang';

    public function handle(): void
    {
        $newToken = $this->argument('token') ?? env('META_DEFAULT_TOKEN');

        if (!$newToken) {
            $this->error('Token tidak ditemukan. Set META_DEFAULT_TOKEN di .env atau pass sebagai argumen.');
            return;
        }

        $accounts = DB::table('social_accounts')
            ->where('platform', 'instagram')
            ->whereNull('deleted_at')
            ->get(['id', 'username']);

        if ($accounts->isEmpty()) {
            $this->info('Tidak ada akun Instagram.');
            return;
        }

        $this->info("Akan reset token untuk {$accounts->count()} akun...");
        $bar = $this->output->createProgressBar($accounts->count());
        $bar->start();

        $encryptedToken = Crypt::encryptString($newToken);
        $expiresAt      = now()->addDays(60)->toDateTimeString();

        foreach ($accounts as $account) {
            DB::table('social_accounts')
                ->where('id', $account->id)
                ->update([
                    'access_token'     => $encryptedToken,
                    'token_expires_at' => $expiresAt,
                    'last_sync_error'  => null,
                    'sync_status'      => 'pending',
                    'updated_at'       => now()->toDateTimeString(),
                ]);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✅ Semua token berhasil di-reset dengan APP_KEY yang aktif.');
    }
}