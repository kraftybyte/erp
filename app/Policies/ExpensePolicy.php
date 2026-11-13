<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Expense;

class ExpensePolicy
{
    // Pengelola keuangan = izin Shield restore_expense, penanda yang sama dengan tombol setujui/bayar.
    // super_admin lolos lewat Gate::before.
    protected function isFinanceManager(User $user): bool
    {
        return $user->can('restore_expense');
    }

    public function viewAny(User $user): bool
    {
        return $this->isFinanceManager($user) || $user->can('view_any_expense');
    }

    public function view(User $user, Expense $expense): bool
    {
        if ($this->isFinanceManager($user)) {
            return true;
        }
        return $user->id === $expense->user_id && $user->can('view_expense');
    }

    public function create(User $user): bool
    {
        return $user->can('create_expense');
    }

    public function update(User $user, Expense $expense): bool
    {
        if ($this->isFinanceManager($user)) {
            return true;
        }
        // Pemilik hanya selama masih Pending: setelah disetujui nominalnya tidak boleh berubah.
        return $user->id === $expense->user_id && $expense->status === 'Pending' && $user->can('update_expense');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->isFinanceManager($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isFinanceManager($user);
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return $this->isFinanceManager($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->isFinanceManager($user);
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $this->isFinanceManager($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isFinanceManager($user);
    }

    public function replicate(User $user, Expense $expense): bool
    {
        return $this->isFinanceManager($user);
    }

    public function reorder(User $user): bool
    {
        return $this->isFinanceManager($user);
    }
}
