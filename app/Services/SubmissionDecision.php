<?php

namespace App\Services;

use App\Exceptions\DecisionException;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menyetujui atau menolak expense/advance yang masih Pending, dari bot
 * laporan keuangan (tombol di notifikasi). Perubahan statusnya sama dengan
 * tombol Approve/Reject di ERP, jadi notifikasi ke pengaju tetap dikirim
 * observer-nya.
 *
 * Baris pengajuan dikunci lalu statusnya dibaca ulang: dua orang yang
 * menekan tombol bersamaan, atau tombol lama di chat lain, tidak membuat
 * keputusan ganda.
 */
class SubmissionDecision
{
    public const ROLES = ['Director', 'Finance', 'super_admin'];

    /**
     * @throws DecisionException
     */
    public function approve(string $type, int $id, User $by): Expense|Advance
    {
        return $this->decide($type, $id, $by, ['status' => 'Disetujui'] + ($type === 'expense' ? ['approved_by' => $by->id] : []));
    }

    /**
     * @throws DecisionException
     */
    public function reject(string $type, int $id, User $by, string $reason): Expense|Advance
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new DecisionException('Alasan penolakan minimal 3 huruf.');
        }

        return $this->decide($type, $id, $by, ['status' => 'Ditolak', 'rejection_reason' => mb_substr($reason, 0, 1000)]);
    }

    /**
     * Pengajuan yang masih menunggu keputusan, terlama di depan.
     *
     * @return \Illuminate\Support\Collection<int, Expense|Advance>
     */
    public function pending(): \Illuminate\Support\Collection
    {
        return Expense::with('user')->where('status', 'Pending')->get()
            ->concat(Advance::with('user')->where('status', Advance::STATUS_PENDING)->get())
            ->sortBy('created_at')
            ->values();
    }

    private function decide(string $type, int $id, User $by, array $changes): Expense|Advance
    {
        if (! $by->hasRole(self::ROLES)) {
            throw new DecisionException('Hanya Director dan Finance yang bisa menyetujui atau menolak pengajuan.');
        }

        $model = match ($type) {
            'expense' => Expense::class,
            'advance' => Advance::class,
            default   => throw new DecisionException('Jenis pengajuan tidak dikenal.'),
        };

        return DB::transaction(function () use ($model, $id, $changes) {
            $record = $model::lockForUpdate()->find($id);

            if (! $record) {
                throw new DecisionException('Pengajuan tidak ditemukan (mungkin sudah dihapus).');
            }

            if ($record->status !== 'Pending') {
                throw new DecisionException("Pengajuan ini sudah diproses: {$record->status}.");
            }

            $record->update($changes);

            return $record;
        });
    }
}
