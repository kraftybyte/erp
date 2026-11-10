<?php

namespace App\Services;

use App\Exceptions\PayoutException;
use App\Helpers\Money;
use App\Models\PaymentAccount;
use App\Models\PayrollComponent;
use App\Models\Payslip;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Settings\GeneralSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Gaji bulanan.
 *
 * 1. Setiap karyawan punya komponen tetap (Employee → Gaji): pendapatan dan
 *    potongan, termasuk PPh 21 dan BPJS yang ditulis sebagai potongan.
 * 2. generate() membuat slip draft per bulan dari komponen itu. Finance bisa
 *    menambah penyesuaian bulan itu (lembur, bonus, potongan sekali).
 * 3. finalize() mengunci slip dan mengirim PDF-nya ke Telegram karyawan.
 * 4. pay() mencatat pembayaran sebagai uang keluar kategori Gaji di rekening.
 *
 * Jadwal dan alurnya diatur di Settings → General → Gaji (lihat settings()).
 */
class Payroll
{
    /**
     * Boleh melihat dan mengelola gaji semua karyawan: izin Shield
     * update_payslip (Roles → Payslip), super_admin selalu boleh.
     */
    public static function canManage(?User $user): bool
    {
        return (bool) $user?->can('update_payslip');
    }

    /**
     * @return array{auto_generate: bool, generate_day: int, review: bool, send_telegram: bool, signature_name: ?string, signature_title: ?string, slip_note: ?string}
     */
    public static function settings(): array
    {
        try {
            $general = app(GeneralSettings::class);

            return [
                'auto_generate'   => (bool) $general->payroll_auto_generate,
                'generate_day'    => min(max((int) $general->payroll_generate_day, 1), 31),
                'review'          => (bool) $general->payroll_review,
                'send_telegram'   => (bool) $general->payroll_send_telegram,
                'signature_name'  => $general->payroll_signature_name ?: $general->inv_signature_name,
                'signature_title' => $general->payroll_signature_title,
                'slip_note'       => $general->payroll_slip_note,
            ];
        } catch (MissingSettings) {
            return ['auto_generate' => false, 'generate_day' => 25, 'review' => true, 'send_telegram' => true, 'signature_name' => null, 'signature_title' => 'HRD', 'slip_note' => null];
        }
    }

    /**
     * Karyawan yang digaji: akun aktif dan punya komponen gaji.
     *
     * @return Collection<int, User>
     */
    public function employees(): Collection
    {
        return User::with('payrollComponents')
            ->where('is_active', true)
            ->whereHas('payrollComponents')
            ->orderBy('firstname')
            ->get();
    }

    /**
     * Slip draft bulan $period untuk setiap karyawan yang belum punya slip
     * bulan itu. Slip yang sudah ada tidak disentuh.
     *
     * @return Collection<int, Payslip> slip yang baru dibuat
     */
    public function generate(CarbonInterface $period): Collection
    {
        $period  = CarbonImmutable::parse($period)->startOfMonth();
        $created = collect();

        foreach ($this->employees() as $employee) {
            if (Payslip::where('user_id', $employee->id)->whereDate('period', $period->toDateString())->exists()) {
                continue;
            }

            $created->push(DB::transaction(function () use ($employee, $period) {
                $slip = Payslip::create([
                    'user_id'       => $employee->id,
                    'period'        => $period->toDateString(),
                    'status'        => Payslip::DRAFT,
                    'employee_name' => trim("{$employee->firstname} {$employee->lastname}") ?: (string) $employee->username,
                    'employee_nik'  => $employee->nik,
                    'position'      => $employee->position,
                    'division'      => $employee->division,
                    'npwp'          => $employee->npwp,
                ]);

                $this->copyComponents($slip, $employee);
                $slip->recalculate();

                return $slip;
            }));
        }

        return $created;
    }

    /**
     * Ganti baris komponen tetap di slip draft dengan komponen karyawan saat ini;
     * penyesuaian bulan itu tetap.
     */
    public function refresh(Payslip $slip): void
    {
        $this->ensureDraft($slip);

        DB::transaction(function () use ($slip) {
            $slip->items()->where('from_component', true)->delete();
            // Komponen tetap di atas, penyesuaian bulan itu di bawahnya.
            $slip->items()->where('from_component', false)->increment('sort', 100);

            if ($slip->user) {
                $this->copyComponents($slip, $slip->user->load('payrollComponents'));
            }

            $slip->recalculate();
        });
    }

    /**
     * Kunci slip (tidak bisa diubah lagi) dan kirim ke karyawan bila diatur.
     */
    public function finalize(Payslip $slip, ?User $by): void
    {
        $this->ensureDraft($slip);
        $slip->recalculate();

        $slip->update(['status' => Payslip::FINAL, 'finalized_at' => now(), 'finalized_by' => $by?->id]);

        if (self::settings()['send_telegram']) {
            $this->send($slip);
        }
    }

    /**
     * Kembalikan slip final yang belum dibayar ke draft (untuk dikoreksi).
     */
    public function reopen(Payslip $slip): void
    {
        if ($slip->status !== Payslip::FINAL) {
            throw new PayoutException('Hanya slip final yang belum dibayar yang bisa dikembalikan ke draft.');
        }

        $slip->update(['status' => Payslip::DRAFT, 'finalized_at' => null, 'finalized_by' => null]);
    }

    /**
     * Kirim PDF slip ke Telegram karyawan (bot pengajuan, tempat akun
     * karyawan terhubung). False bila karyawan belum menghubungkan Telegram.
     */
    public function send(Payslip $slip): bool
    {
        $chatId = $slip->user?->telegram_chat_id;
        $client = app(TelegramClient::class);

        if (! $chatId || ! $client->isConfigured() || $slip->isDraft()) {
            return false;
        }

        $path = storage_path('app/slip-' . Str::random(16) . '.pdf');
        file_put_contents($path, $this->pdf($slip)->output());

        try {
            $sent = $client->sendDocument(
                $chatId,
                $path,
                $slip->fileName(),
                "🧾 <b>Slip gaji {$slip->periodLabel()}</b>\nTake home pay: <b>" . Money::rupiah($slip->net) . '</b>'
            );
        } finally {
            @unlink($path);
        }

        if ($sent) {
            $slip->forceFill(['sent_at' => now()])->save();
        }

        return $sent;
    }

    /**
     * Catat pembayaran gaji: uang keluar kategori Gaji dari $account, slip
     * menjadi Dibayar.
     *
     * @throws PayoutException
     */
    public function pay(Payslip $slip, PaymentAccount $account, ?User $by, ?CarbonInterface $date = null): void
    {
        $date ??= now();

        DB::transaction(function () use ($slip, $account, $by, $date) {
            $slip = Payslip::lockForUpdate()->findOrFail($slip->getKey());

            if ($slip->status !== Payslip::FINAL) {
                throw new PayoutException($slip->status === Payslip::PAID ? 'Slip ini sudah dibayar.' : 'Final-kan slip dulu sebelum dibayar.');
            }

            if ((float) $slip->net <= 0) {
                throw new PayoutException('Take home pay slip ini Rp 0.');
            }

            Transaction::create([
                'payment_account_id' => $account->id,
                'created_by'         => $by?->id,
                'type'               => Transaction::TYPE_OUT,
                'amount'             => $slip->net,
                'description'        => "Gaji {$slip->periodLabel()}: {$slip->employee_name}",
                'reference_type'     => 'payslip',
                'reference_id'       => $slip->id,
                'transaction_date'   => Carbon::parse($date)->toDateString(),
                'category'           => 'payroll',
            ]);

            $account->syncBalanceFromTransactions();

            $slip->update([
                'status'             => Payslip::PAID,
                'paid_at'            => Carbon::parse($date)->toDateString(),
                'paid_by'            => $by?->id,
                'payment_account_id' => $account->id,
            ]);
        });
    }

    public function pdf(Payslip $slip): DomPdf
    {
        $slip->loadMissing('items', 'paymentAccount');
        $general = app(GeneralSettings::class);

        return Pdf::loadView('pdf.payslip', [
            'slip'     => $slip,
            'company'  => $general->getInvoiceSettings(),
            'color'    => $general->inv_theme_color ?: '#f04f29',
            'settings' => self::settings(),
        ])->setPaper('a4');
    }

    private function copyComponents(Payslip $slip, User $employee): void
    {
        $sort = 0;

        foreach ($employee->payrollComponents as $component) {
            $slip->items()->create([
                'name'           => $component->name,
                'type'           => $component->type === PayrollComponent::DEDUCTION ? PayrollComponent::DEDUCTION : PayrollComponent::EARNING,
                'amount'         => $component->amount,
                'sort'           => ++$sort,
                'from_component' => true,
            ]);
        }
    }

    private function ensureDraft(Payslip $slip): void
    {
        if (! $slip->isDraft()) {
            throw new PayoutException('Slip ini sudah final. Kembalikan ke draft dulu untuk mengubahnya.');
        }
    }
}
