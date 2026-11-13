<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Advance & Expense: hak pengelola keuangan (lihat semua, ubah setelah
 * disetujui, hapus, realisasi) dulu ditentukan nama peran Finance/Director,
 * kini lewat izin Shield restore_advance / restore_expense, izin yang sama
 * dengan tombol setujui/bayar. Kedua peran itu diberi izinnya supaya tidak
 * ada yang terkunci setelah deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        if (! Schema::hasTable($tables['roles'] ?? 'roles') || ! Schema::hasTable($tables['permissions'] ?? 'permissions')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        $names = ['restore_advance', 'restore_expense'];

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        Role::where('guard_name', $guard)
            ->whereIn('name', ['Finance', 'Director', 'super_admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($names));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin dibiarkan: mencabutnya bisa mengunci peran yang sudah diatur di halaman Roles.
    }
};
