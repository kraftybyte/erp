<?php

namespace App\Console\Commands;

use App\Helpers\NotifHelper;
use App\Services\MorningBrief;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

/**
 * Kirim ringkasan pagi (lihat MorningBrief) ke Director, Finance, dan
 * super_admin: selalu ke lonceng notifikasi ERP, dan ke Telegram bila akunnya
 * sudah terhubung. Lewat bot laporan keuangan; bot pengajuan bila bot keuangan
 * belum diatur. Tidak mengirim apa pun bila tidak ada yang perlu diingatkan.
 */
class MorningBriefCommand extends Command
{
    protected $signature = 'ringkasan:pagi {--dry-run : Tampilkan pesannya tanpa mengirim}';

    protected $description = 'Kirim ringkasan pagi: invoice jatuh tempo, kontrak berakhir, task, dan pengajuan menunggu';

    public function handle(MorningBrief $brief): int
    {
        $data = $brief->data();

        if ($brief->isEmpty($data)) {
            $this->info('Tidak ada yang perlu diingatkan hari ini.');

            return self::SUCCESS;
        }

        $html = $brief->telegram($data);

        if ($this->option('dry-run')) {
            $this->line(html_entity_decode(strip_tags($html)));

            return self::SUCCESS;
        }

        $finance  = TelegramClient::forFinance();
        $telegram = $finance->isConfigured() ? $finance : app(TelegramClient::class);
        $link     = url('/admin');
        // Telegram menolak tombol URL yang bukan https (mis. saat APP_URL lokal).
        $buttons  = str_starts_with($link, 'https://') ? [[['text' => 'Buka ERP', 'url' => $link]]] : [];
        $sent     = 0;

        foreach ($brief->recipients() as $user) {
            NotifHelper::send($user, 'Ringkasan pagi', $brief->summary($data), 'heroicon-o-sun', 'info', '/admin');

            if ($user->telegram_chat_id && $telegram->sendMessage($user->telegram_chat_id, $html, $buttons)) {
                $sent++;
            }
        }

        $this->info("Ringkasan pagi dikirim; {$sent} lewat Telegram.");

        return self::SUCCESS;
    }
}
