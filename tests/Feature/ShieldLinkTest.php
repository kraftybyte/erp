<?php

namespace Tests\Feature;

use App\Filament\Pages\Faktur;
use App\Filament\Pages\PwaSettings;
use App\Filament\Resources\BankMutationResource;
use App\Filament\Resources\HrReportResource;
use App\Filament\Resources\PayslipResource;
use App\Filament\Resources\PerformanceIndicatorResource;
use App\Models\User;
use App\Services\Payroll;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Halaman yang dulu dibatasi lewat nama peran di kode kini mengikuti izin
 * Shield (Settings → Roles): peran baru tanpa izin ditolak, dan diberi izin
 * di Shield langsung bisa masuk.
 */
class ShieldLinkTest extends TestCase
{
    use DatabaseTransactions;

    private const PAGES = [
        'page_Faktur'                     => 'faktur',
        'page_PwaSettings'                => 'pwa',
        'view_any_bank::mutation'         => 'mutasi',
        'view_any_payslip'                => 'slip',
        'view_any_hr::report'             => 'hr',
        'view_any_performance::indicator' => 'kpi',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_akses_halaman_mengikuti_izin_shield(): void
    {
        $role = Role::create(['name' => 'Tes Shield ' . Str::random(6), 'guard_name' => 'web']);
        $user = $this->user($role);
        $this->actingAs($user);

        foreach ($this->urls() as $permission => $url) {
            $this->get($url)->assertForbidden();
        }

        $role->givePermissionTo(array_keys(self::PAGES));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->urls() as $permission => $url) {
            $this->get($url)->assertSuccessful();
        }

        // Melihat slip sendiri tidak sama dengan mengelola gaji.
        $this->assertFalse(Payroll::canManage($user->fresh()));
        $role->givePermissionTo('update_payslip');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue(Payroll::canManage($user->fresh()));
    }

    public function test_finance_tetap_punya_akses_setelah_pindah_ke_shield(): void
    {
        $finance = $this->user(Role::findByName('Finance'));

        $this->actingAs($finance);
        $this->get(Faktur::getUrl())->assertSuccessful();
        $this->get(BankMutationResource::getUrl())->assertSuccessful();
        $this->get(PayslipResource::getUrl())->assertSuccessful();
        $this->assertTrue(Payroll::canManage($finance));
    }

    public function test_staff_melihat_slip_sendiri_tapi_tidak_faktur(): void
    {
        $staff = $this->user(Role::findByName('Staff'));

        $this->actingAs($staff);
        $this->get(Faktur::getUrl())->assertForbidden();
        $this->get(PayslipResource::getUrl())->assertSuccessful();
        $this->get(PwaSettings::getUrl())->assertSuccessful();
        $this->assertFalse(Payroll::canManage($staff));
    }

    /**
     * @return array<string, string>
     */
    private function urls(): array
    {
        return [
            'page_Faktur'                     => Faktur::getUrl(),
            'page_PwaSettings'                => PwaSettings::getUrl(),
            'view_any_bank::mutation'         => BankMutationResource::getUrl(),
            'view_any_payslip'                => PayslipResource::getUrl(),
            'view_any_hr::report'             => HrReportResource::getUrl(),
            'view_any_performance::indicator' => PerformanceIndicatorResource::getUrl(),
        ];
    }

    private function user(Role $role): User
    {
        $user = User::forceCreate([
            'username'          => 'sh_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => 'Shield',
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'is_active'         => true,
            'push_enabled'      => false,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
