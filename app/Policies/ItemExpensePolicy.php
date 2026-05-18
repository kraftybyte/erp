<?php

namespace App\Policies;

use Illuminate\Auth\Access\Response;
use App\Models\ItemExpense;
use App\Models\User;

class ItemExpensePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('view-any ItemExpense');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ItemExpense $itemexpense): bool
    {
        return $user->checkPermissionTo('view ItemExpense');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->checkPermissionTo('create ItemExpense');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ItemExpense $itemexpense): bool
    {
        return $user->checkPermissionTo('update ItemExpense');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ItemExpense $itemexpense): bool
    {
        return $user->checkPermissionTo('delete ItemExpense');
    }

    /**
     * Determine whether the user can delete any models.
     */
    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo('delete-any ItemExpense');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ItemExpense $itemexpense): bool
    {
        return $user->checkPermissionTo('restore ItemExpense');
    }

    /**
     * Determine whether the user can restore any models.
     */
    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo('restore-any ItemExpense');
    }

    /**
     * Determine whether the user can replicate the model.
     */
    public function replicate(User $user, ItemExpense $itemexpense): bool
    {
        return $user->checkPermissionTo('replicate ItemExpense');
    }

    /**
     * Determine whether the user can reorder the models.
     */
    public function reorder(User $user): bool
    {
        return $user->checkPermissionTo('reorder ItemExpense');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ItemExpense $itemexpense): bool
    {
        return $user->checkPermissionTo('force-delete ItemExpense');
    }

    /**
     * Determine whether the user can permanently delete any models.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->checkPermissionTo('force-delete-any ItemExpense');
    }
}
