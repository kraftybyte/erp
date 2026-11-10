<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Halaman yang dulu dibatasi lewat nama peran di kode kini lewat izin Shield
 * (Settings → Roles), supaya aksesnya bisa diatur per peran dari ERP. Izinnya
 * diberikan ke peran yang selama ini sudah punya akses, jadi tidak ada yang
 * terkunci setelah deploy:
 *
 * - Slip Gaji (payslip): Director & Finance kelola; semua peran lain melihat slip sendiri.
 * - Mutasi Rekening (bank::mutation), Faktur (page_Faktur): Director & Finance.
 * - PWA & Notifications (page_PwaSettings): semua peran (pengaturan notifikasi pribadi).
 * - HR Report (hr::report): Director (dan HR bila ada).
 * - Performance Indicator (performance::indicator): Director & Head.
 *
 * super_admin selalu boleh (gate Shield), tapi ikut diberi supaya terlihat di halaman Roles.
 */
return new class extends Migration
{
    private const PREFIXES = ['view', 'view_any', 'create', 'update', 'restore', 'restore_any', 'replicate', 'reorder', 'delete', 'delete_any', 'force_delete', 'force_delete_any'];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $make  = fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);

        foreach (['payslip', 'bank::mutation', 'hr::report', 'performance::indicator'] as $entity) {
            foreach (self::PREFIXES as $prefix) {
                $make("{$prefix}_{$entity}");
            }
        }

        $make('page_Faktur');
        $make('page_PwaSettings');

        $managePayroll = ['view_any_payslip', 'view_payslip', 'create_payslip', 'update_payslip', 'delete_payslip', 'delete_any_payslip'];
        $grants = [
            'Director' => [...$managePayroll, 'view_any_bank::mutation', 'view_bank::mutation', 'page_Faktur', 'view_any_hr::report', 'view_hr::report', 'view_any_performance::indicator', 'view_performance::indicator'],
            'Finance'  => [...$managePayroll, 'view_any_bank::mutation', 'view_bank::mutation', 'page_Faktur'],
            'HR'       => ['view_any_hr::report', 'view_hr::report'],
            'Head'     => ['view_any_performance::indicator', 'view_performance::indicator'],
        ];

        foreach (Role::where('guard_name', $guard)->get() as $role) {
            $names = $role->name === 'super_admin'
                ? Permission::where('guard_name', $guard)->where(fn ($query) => $query
                    ->where('name', 'like', '%payslip')->orWhere('name', 'like', '%bank::mutation')
                    ->orWhere('name', 'like', '%hr::report')->orWhere('name', 'like', '%performance::indicator')
                    ->orWhereIn('name', ['page_Faktur', 'page_PwaSettings']))->pluck('name')->all()
                : [...($grants[$role->name] ?? []), 'view_any_payslip', 'view_payslip', 'page_PwaSettings'];

            $role->givePermissionTo(array_values(array_unique($names)));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin dibiarkan: mencabutnya bisa mengunci peran yang sudah diatur di halaman Roles.
    }
};
