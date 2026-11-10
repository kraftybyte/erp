<?php

namespace App\Policies;

use App\Models\BankMutation;
use App\Models\User;

/**
 * Riwayat mutasi rekening (Finance → Mutasi Rekening), lewat izin Shield
 * (Roles → Bank Mutation). Hanya dilihat: mutasi dicatat lewat /mutasi di bot
 * keuangan, tidak dibuat atau diubah dari ERP.
 */
class BankMutationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_bank::mutation');
    }

    public function view(User $user, BankMutation $mutation): bool
    {
        return $user->can('view_bank::mutation');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BankMutation $mutation): bool
    {
        return false;
    }

    public function delete(User $user, BankMutation $mutation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
