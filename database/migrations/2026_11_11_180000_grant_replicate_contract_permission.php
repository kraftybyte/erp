<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tombol Duplikat kontrak memakai izin Shield replicate_contract (plus
 * create_contract). Peran yang sudah boleh membuat kontrak diberi izin
 * duplikat, supaya tombolnya langsung tersedia untuk mereka; peran lain
 * diatur di Settings → Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard     = config('auth.defaults.guard', 'web');
        $replicate = Permission::firstOrCreate(['name' => 'replicate_contract', 'guard_name' => $guard]);

        Role::where('guard_name', $guard)
            ->where(fn ($query) => $query->where('name', 'super_admin')->orWhereHas('permissions', fn ($q) => $q->where('name', 'create_contract')))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($replicate));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin dibiarkan: mencabutnya bisa mengunci peran yang sudah diatur di halaman Roles.
    }
};
