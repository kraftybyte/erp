<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\InstagramService;
use Illuminate\Console\Command;

class SyncInstagramMetrics extends Command
{
    protected $signature = 'instagram:sync-metrics
                            {--account-id= : ID SocialAccount di database (kosong = semua aktif)}
                            {--type=all    : Tipe sync: all | profile | insights | posts}';

    protected $description = 'Sync posts dan metrics dari Instagram Graph API ke database';

    public function handle(InstagramService $service): void
    {
        $accounts = $this->option('account-id')
            ? SocialAccount::where('id', $this->option('account-id'))
                           ->where('platform', 'instagram')
                           ->get()
            : SocialAccount::where('platform', 'instagram')
                           ->where('is_active', true)
                           ->get();

        if ($accounts->isEmpty()) {
            $this->error('Tidak ada akun Instagram ditemukan.');
            return;
        }

        $type = $this->option('type');
        $this->info("🚀 Sync [{$type}] untuk {$accounts->count()} akun Instagram...");
        $this->newLine();

        foreach ($accounts as $account) {
            $this->info("📱 Akun: @{$account->username} (ID: {$account->id})");

            try {
                $result = match ($type) {
                    'profile'  => $service->syncProfile($account),
                    'insights' => $service->syncInsights($account),
                    'posts'    => $service->syncPosts($account),
                    default    => $service->syncAll($account),
                };

                if (!($result['success'] ?? true)) {
                    $this->error("  ❌ Gagal: " . ($result['error'] ?? implode(', ', $result['errors'] ?? ['unknown'])));
                } else {
                    if (!empty($result['errors'] ?? [])) {
                        foreach ($result['errors'] as $err) {
                            $this->warn("  ⚠️  " . $err);
                        }
                    }
                    $this->info("  ✅ Selesai sync @{$account->username}");
                }

            } catch (\Exception $e) {
                $this->error("  ❌ Exception: " . $e->getMessage());
            }

            $this->newLine();
        }

        $this->info('✅ Sync selesai!');
    }
}