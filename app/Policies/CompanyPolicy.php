<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Perusahaan & PIC adalah bagian dari data Client, jadi memakai izin Client
 * (deal) yang sudah diatur di Shield — tidak perlu izin baru per role.
 */
class CompanyPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_deal');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can('view_deal');
    }

    public function create(User $user): bool
    {
        return $user->can('create_deal');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can('update_deal');
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can('delete_deal');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_deal');
    }

    public function restore(User $user, Company $company): bool
    {
        return $user->can('restore_deal');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_deal');
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
