<?php

namespace App\Console\Commands;

use App\Helpers\Money;
use App\Models\Payslip;
use App\Services\Payroll;
use App\Services\Telegram\ManagementAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Slip gaji otomatis (dijalankan setiap hari, lihat Kernel). Hanya bekerja
 * pada tanggal yang diatur di Settings → General → Gaji, kecuali --paksa.
 * Dicek dulu: slip dibuat sebagai draft dan Finance diberi tahu. Tidak dicek:
 * slip langsung final dan dikirim ke karyawan.
 */
class GeneratePayslips extends Command
{
    protected $signature = 'gaji:buat
        {--periode= : Bulan slip, YYYY-MM (default bulan ini)}
        {--paksa : Jalankan walau bukan tanggalnya atau otomatis dimatikan}
        {--dry-run : Tampilkan siapa yang akan dibuatkan slip tanpa membuatnya}';

    protected $description = 'Buat slip gaji bulanan dari komponen gaji karyawan';

    public function handle(Payroll $payroll, ManagementAlerts $alerts): int
    {
        $settings = Payroll::settings();
        $today    = CarbonImmutable::today();
        $day      = min($settings['generate_day'], $today->daysInMonth);

        // Tanggalnya terlewat (cron mati, situs maintenance): susul selama bulan ini belum ada slip sama sekali.
        $due = $today->day === $day
            || ($today->day > $day && ! Payslip::whereDate('period', $today->startOfMonth()->toDateString())->exists());

        if (! $this->option('paksa') && (! $settings['auto_generate'] || ! $due)) {
            $this->line($settings['auto_generate'] ? "Bukan tanggalnya (setiap tanggal {$day})." : 'Slip otomatis dimatikan di Settings → General → Gaji.');

            return self::SUCCESS;
        }

        $period = $this->option('periode') ? CarbonImmutable::createFromFormat('!Y-m', $this->option('periode'))->startOfMonth() : $today->startOfMonth();
        $label  = $period->locale('id')->translatedFormat('F Y');

        if ($this->option('dry-run')) {
            $payroll->employees()->each(fn ($user) => $this->line("• {$user->firstname} {$user->lastname}"));

            return self::SUCCESS;
        }

        $created = $payroll->generate($period);

        if ($created->isEmpty()) {
            $this->info("Tidak ada slip baru untuk {$label}.");

            return self::SUCCESS;
        }

        if (! $settings['review']) {
            $created->each(fn (Payslip $slip) => $payroll->finalize($slip, null));
            $this->info("{$created->count()} slip {$label} dibuat, difinalkan, dan dikirim.");

            return self::SUCCESS;
        }

        $alerts->toManagers(
            "🧾 <b>Draft slip gaji {$label}</b>\n{$created->count()} karyawan · total take home pay <b>" . Money::rupiah($created->sum('net')) . "</b>\n\nPeriksa dan tambah penyesuaian (lembur, bonus, potongan), lalu Final &amp; kirim.",
            [],
            '/admin/payslips'
        );

        $this->info("{$created->count()} slip draft {$label} dibuat; Finance diberi tahu.");

        return self::SUCCESS;
    }
}
