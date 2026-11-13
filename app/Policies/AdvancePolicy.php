<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Advance;

class AdvancePolicy
{
    // Pengelola keuangan = izin Shield restore_advance, penanda yang sama dengan tombol setujui/bayar.
    // super_admin lolos lewat Gate::before.
    protected function isFinanceManager(User $user): bool
    {
        return $user->can('restore_advance');
    }

    public function viewAny(User $user): bool
    {
        return $this->isFinanceManager($user) || $user->can('view_any_advance');
    }

    public function view(User $user, Advance $advance): bool
    {
        if ($this->isFinanceManager($user)) {
            return true;
        }
        return $user->id === $advance->user_id && $user->can('view_advance');
    }

    public function create(User $user): bool
    {
        return $user->can('create_advance');
    }

    public function update(User $user, Advance $advance): bool
    {
        if ($this->isFinanceManager($user)) {
            return true;
        }
        // Pemilik hanya selama masih Pending: setelah disetujui nominalnya tidak boleh berubah.
        return $user->id === $advance->user_id && $advance->status === Advance::STATUS_PENDING && $user->can('update_advance');
    }

    public function delete(User $user, Advance $advance): bool
    {
        return $this->isFinanceManager($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isFinanceManager($user);
    }

    public function forceDelete(User $user, Advance $advance): bool
    {
        return $this->isFinanceManager($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->isFinanceManager($user);
    }

    public function restore(User $user, Advance $advance): bool
    {
        return $this->isFinanceManager($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isFinanceManager($user);
    }

    public function replicate(User $user, Advance $advance): bool
    {
        return $this->isFinanceManager($user);
    }

    public function reorder(User $user): bool
    {
        return $this->isFinanceManager($user);
    }
}
