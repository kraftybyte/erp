<?php

namespace App\Policies;

use App\Models\Payslip;
use App\Models\User;

/**
 * Slip gaji, lewat izin Shield (Roles → Payslip).
 *
 * - view_any_payslip / view_payslip: membuka menu Slip Gaji dan melihat slip
 *   sendiri yang sudah final (semua karyawan).
 * - create_payslip: Buat slip.
 * - update_payslip: kelola gaji semua karyawan (lihat semua slip, ubah draft,
 *   final & kirim, tandai dibayar, komponen gaji di HR → Employee).
 * - delete_payslip / delete_any_payslip: hapus slip draft.
 */
class PayslipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_payslip');
    }

    public function view(User $user, Payslip $payslip): bool
    {
        return $user->can('update_payslip')
            || ($user->can('view_payslip') && $payslip->user_id === $user->id && ! $payslip->isDraft());
    }

    public function create(User $user): bool
    {
        return $user->can('create_payslip');
    }

    public function update(User $user, Payslip $payslip): bool
    {
        return $user->can('update_payslip');
    }

    public function delete(User $user, Payslip $payslip): bool
    {
        return $user->can('delete_payslip') && $payslip->isDraft();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_payslip');
    }

    public function restore(User $user, Payslip $payslip): bool
    {
        return false;
    }

    public function forceDelete(User $user, Payslip $payslip): bool
    {
        return false;
    }

    public function replicate(User $user, Payslip $payslip): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
