<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Advance;

class AdvancePolicy
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
        return $this->isAdminOrRole($user, ['Finance', 'Director']) || $user->can('view_any_advance');
    }

    public function view(User $user, Advance $advance): bool
    {
        if ($this->isAdminOrRole($user, ['Finance', 'Director'])) {
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
        if ($this->isAdminOrRole($user, ['Finance', 'Director'])) {
            return true;
        }
        // Pemilik hanya selama masih Pending: setelah disetujui nominalnya tidak boleh berubah.
        return $user->id === $advance->user_id && $advance->status === Advance::STATUS_PENDING && $user->can('update_advance');
    }

    public function delete(User $user, Advance $advance): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function forceDelete(User $user, Advance $advance): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function restore(User $user, Advance $advance): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function replicate(User $user, Advance $advance): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }

    public function reorder(User $user): bool
    {
        return $this->isAdminOrRole($user, ['Finance', 'Director']);
    }
}
