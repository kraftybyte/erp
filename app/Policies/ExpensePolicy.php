<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Expense;

class ExpensePolicy
{
    protected function isAdminOrRole(User $user, array $roles = []): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        if (!empty($roles) && $user->hasRole($roles)) {
            return true;
        }
        return false;
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']) || $user->can('view_any_expense');
    }

    public function view(User $user, Expense $expense): bool
    {
        if ($this->isAdminOrRole($user, ['Finance', 'Director'])) {
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
        if ($this->isAdminOrRole($user, ['Finance', 'Director'])) {
            return true;
        }
        // Pemilik hanya selama masih Pending: setelah disetujui nominalnya tidak boleh berubah.
        return $user->id === $expense->user_id && $expense->status === 'Pending' && $user->can('update_expense');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function replicate(User $user, Expense $expense): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function reorder(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }
}
