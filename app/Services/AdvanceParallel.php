<?php

namespace App\Services;

use App\Models\Advance;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Advance bayar paralel (petty cash), Settings → General → Persetujuan.
 *
 * Karyawan belanja dulu, lalu struknya masuk sebagai realisasi advance project
 * yang sudah Disetujui, tanpa menunggu advance dibayar. Finance membayar kapan
 * saja, sekaligus atau bertahap, sampai yang dibayar sama dengan yang
 * direalisasi; advance lalu bisa diselesaikan tanpa pengembalian sisa.
 *
 * Advance yang sudah berjalan dengan alur ini ditandai pay_parallel dan tetap
 * memakai aturannya walau setting dimatikan. Advance baru ditandai sejak dibuat
 * selama setting menyala; advance lama ikut ditandai saat realisasi pertamanya
 * masuk sebelum dibayar atau saat dibayar bertahap.
 */
class AdvanceParallel
{
    /** Status advance yang sedang direalisasi. */
    public const REALIZING = [
        Advance::STATUS_DIBAYARKAN,
        Advance::STATUS_REALIZING,
        Advance::STATUS_REALIZED_USER,
        Advance::STATUS_READY_TO_CLOSE,
    ];

    public static function enabled(): bool
    {
        try {
            return (bool) app(GeneralSettings::class)->advance_parallel_payment;
        } catch (MissingSettings) {
            return false; // Setting belum dimigrasi: alur lama (bayar di depan).
        }
    }

    /** Advance ini memakai, atau boleh mulai memakai, bayar paralel. */
    public static function allows(Advance $advance): bool
    {
        return (bool) $advance->pay_parallel || self::enabled();
    }

    /**
     * Status yang boleh direalisasi dan dikelola di halaman Realisasi: setelah
     * dibayar, atau sejak Disetujui untuk bayar paralel.
     *
     * @return list<string>
     */
    public static function openStatuses(Advance $advance): array
    {
        return self::allows($advance) ? [Advance::STATUS_DISETUJUI, ...self::REALIZING] : self::REALIZING;
    }

    public static function isOpen(Advance $advance): bool
    {
        return in_array($advance->status, self::openStatuses($advance), true);
    }

    /**
     * Batasi $query ke advance berstatus $statuses, ditambah yang Disetujui bila
     * boleh direalisasi sebelum dibayar.
     */
    public static function whereRealizable(Builder $query, array $statuses): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereIn('status', $statuses)
            ->orWhere(fn (Builder $query) => $query
                ->where('status', Advance::STATUS_DISETUJUI)
                ->when(! self::enabled(), fn (Builder $query) => $query->where('pay_parallel', true))));
    }

    /**
     * Advance yang masih bisa dibayar: Disetujui, atau bayar paralel yang
     * sedang direalisasi dan masih punya sisa yang boleh dibayar.
     */
    public static function wherePayable(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('status', Advance::STATUS_DISETUJUI)
            ->orWhere(fn (Builder $query) => $query
                ->whereIn('status', self::REALIZING)
                ->when(! self::enabled(), fn (Builder $query) => $query->where('pay_parallel', true))));
    }

    /**
     * Batas total pembayaran: nominal yang disetujui, atau total realisasi bila
     * kelebihannya sudah disetujui Finance.
     */
    public static function cap(Advance $advance): float
    {
        $approved = (float) $advance->amount;

        return $advance->overrun_approved_by ? max($approved, $advance->realizedTotal()) : $approved;
    }

    /** Yang masih boleh dibayarkan sekarang (batas − yang sudah dibayar). */
    public static function payable(Advance $advance): float
    {
        return max(round(self::cap($advance) - (float) $advance->paid, 2), 0.0);
    }

    /**
     * Realisasi yang belum dibayar ke pengaju (dalam batas yang disetujui):
     * utang perusahaan ke karyawan.
     */
    public static function owed(Advance $advance): float
    {
        if (! $advance->pay_parallel) {
            return 0.0;
        }

        return max(round(min($advance->realizedTotal(), self::cap($advance)) - (float) $advance->paid, 2), 0.0);
    }

    /**
     * Usulan nominal pembayaran: realisasi yang belum dibayar, atau sisa yang
     * disetujui bila realisasinya belum melebihi yang sudah dibayar.
     */
    public static function suggested(Advance $advance): float
    {
        $owed = self::owed($advance);

        return $owed > 0 ? $owed : self::payable($advance);
    }
}
