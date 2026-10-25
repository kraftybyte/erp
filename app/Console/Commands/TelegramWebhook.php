<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramWebhookManager;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Padanan tombol di Settings → Integrasi, untuk dipakai lewat SSH.
 */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {action=set : set | info | delete} {--finance : bot laporan keuangan, bukan bot pengajuan}';

    protected $description = 'Daftarkan, cek, atau cabut webhook bot Telegram';

    public function handle(TelegramWebhookManager $webhook): int
    {
        $webhook = $this->option('finance') ? TelegramWebhookManager::forFinance() : $webhook;
        $action  = $this->argument('action');

        if (! in_array($action, ['set', 'info', 'delete'], true)) {
            $this->error('Pilihan yang tersedia: set, info, delete.');

            return self::FAILURE;
        }

        try {
            if ($action === 'delete') {
                $webhook->remove();
                $this->info('Webhook dicabut. Bot berhenti menerima pesan.');

                return self::SUCCESS;
            }

            if ($action === 'set') {
                $this->info('Webhook terdaftar: ' . $webhook->register());
            }

            $status = $webhook->status();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['', ''], [
            ['Bot', $status['bot']],
            ['Webhook', $status['webhook'] ?? '(belum didaftarkan)'],
            ['Update tertunda', $status['pending']],
            ['Error terakhir', $status['last_error'] ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
