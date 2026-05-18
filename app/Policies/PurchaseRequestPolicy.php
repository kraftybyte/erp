<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PurchaseRequest;
use Illuminate\Auth\Access\HandlesAuthorization;

class PurchaseRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_purchase_request');
    }

    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('view_purchase_request');
    }

    public function create(User $user): bool
    {
        return $user->can('create_purchase_request');
    }

    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('update_purchase_request');
    }

    public function delete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('delete_purchase_request');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_purchase_request');
    }

    public function forceDelete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('force_delete_purchase_request');
    }

    public function restore(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('restore_purchase_request');
    }
}
