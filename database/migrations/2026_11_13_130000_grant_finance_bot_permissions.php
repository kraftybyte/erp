<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bot laporan keuangan dulu dibatasi nama peran Director/Finance, kini lewat
 * izin Shield:
 * - laporan, /piutang, Excel, analisa: page_AnalyticsDashboard (Financial Analytics);
 * - /bayar dan /mutasi (mencatat uang masuk/keluar): create_bank::mutation;
 * - Setujui/Tolak dan realisasi advance siapa pun: restore_expense / restore_advance,
 *   izin tombol setujui/bayar di ERP (juga diberikan 2026_11_13_120000).
 * Director dan Finance diberi izinnya supaya tidak ada yang terkunci setelah deploy.
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
        $names = ['page_AnalyticsDashboard', 'create_bank::mutation', 'restore_expense', 'restore_advance'];

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        Role::where('guard_name', $guard)
            ->whereIn('name', ['Director', 'Finance'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($names));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin dibiarkan: mencabutnya bisa mengunci peran yang sudah diatur di halaman Roles.
    }
};
