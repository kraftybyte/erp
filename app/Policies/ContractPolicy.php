<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Contract;
use Illuminate\Auth\Access\HandlesAuthorization;

class ContractPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_contract');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can('view_contract');
    }

    public function create(User $user): bool
    {
        return $user->can('create_contract');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can('update_contract');
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->can('delete_contract');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_contract');
    }

    public function forceDelete(User $user, Contract $contract): bool
    {
        return $user->can('force_delete_contract');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_contract');
    }

    public function restore(User $user, Contract $contract): bool
    {
        return $user->can('restore_contract');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_contract');
    }

    public function replicate(User $user, Contract $contract): bool
    {
        return $user->can('replicate_contract');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_contract');
    }
}
