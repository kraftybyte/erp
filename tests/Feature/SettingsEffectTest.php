<?php

namespace Tests\Feature;

use App\Filament\Pages\Setting\ManageGeneral;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pengaturan yang dulu tidak berpengaruh: prefix nomor invoice/proforma dan
 * status situs. Juga keterangan event yang disalin dari detail task.
 */
class SettingsEffectTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_nomor_invoice_dan_proforma_memakai_prefix_dari_pengaturan(): void
    {
        $period = '/013' . now()->format('ym') . '/';

        $this->settings(['invoice_prefix' => 'SC', 'proforma_prefix' => 'PI']);
        $this->assertStringStartsWith('#SC' . $period, Invoice::generateUuid('sale'));
        $this->assertStringStartsWith('#PI' . $period, Invoice::generateUuid('estimate'));

        $this->settings(['invoice_prefix' => 'inv', 'proforma_prefix' => 'PRO']);
        $this->assertStringStartsWith('#INV' . $period, Invoice::generateUuid('sale'));
        $this->assertStringStartsWith('#PRO' . $period, Invoice::generateUuid('estimate'));

        // Isian kosong atau tidak sah: kembali ke bawaan, bukan nomor rusak.
        $this->settings(['invoice_prefix' => 'A/B', 'proforma_prefix' => '']);
        $this->assertStringStartsWith('#SC' . $period, Invoice::generateUuid('sale'));
        $this->assertStringStartsWith('#PI' . $period, Invoice::generateUuid('estimate'));
    }

    public function test_isian_prefix_divalidasi(): void
    {
        $this->actingAs($this->user('super_admin'));

        // Gagal validasi berhenti sebelum apa pun disimpan.
        Livewire::test(ManageGeneral::class)
            ->fillForm(['invoice_prefix' => 'pi', 'proforma_prefix' => 'PI', 'quotation_prefix' => 'D G'])
            ->call('save')
            ->assertHasFormErrors(['invoice_prefix', 'proforma_prefix', 'quotation_prefix']);

        Livewire::test(ManageGeneral::class)
            ->assertFormFieldExists('proforma_prefix')
            ->assertSee('Contoh nomor: #SC/013' . now()->format('ym') . '/00001');
    }

    public function test_situs_tidak_aktif_hanya_bisa_dibuka_pengelola_settings(): void
    {
        $staff = $this->user('Staff');

        $this->settings(['site_active' => 0]);
        $this->dashboardAs($staff)->assertStatus(503)->assertSee('ERP sedang dalam perbaikan');
        $this->dashboardAs($this->user('super_admin'))->assertOk();

        Permission::findOrCreate('page_ManageGeneral', 'web');
        $role = Role::create(['name' => 'Tes Pengelola ' . Str::random(5), 'guard_name' => 'web']);
        $role->givePermissionTo('page_ManageGeneral');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->dashboardAs($this->user($role->name))->assertOk();

        $this->settings(['site_active' => 1]);
        $this->dashboardAs($staff)->assertOk();
    }

    private function dashboardAs(User $user): \Illuminate\Testing\TestResponse
    {
        // Sesi baru per pengguna: AuthenticateSession mengeluarkan pengguna yang berganti di sesi yang sama.
        $this->flushSession();

        return $this->actingAs($user)->get('/admin');
    }

    public function test_keterangan_event_dari_task_berupa_teks_dan_ikut_diperbarui(): void
    {
        $detail = '<p>Shooting produk di studio &amp; outdoor.</p><ul><li>Bawa lighting</li><li>Model 2 orang</li></ul><p>' . str_repeat('Catatan panjang untuk tim. ', 15) . '</p>';
        $this->assertGreaterThan(255, strlen($detail));

        $task  = Task::create(['nama_task' => 'Shooting katalog', 'status' => 'process', 'mulai_task' => today(), 'selesai_task' => today()->addDay(), 'proritas' => 'High', 'detail_task' => $detail]);
        $event = Event::where('task_id', $task->id)->sole();

        $this->assertStringStartsWith("Shooting produk di studio & outdoor.\n• Bawa lighting\n• Model 2 orang\nCatatan panjang", $event->description);
        $this->assertStringNotContainsString('<', $event->description);

        $task->update(['detail_task' => '<p>Jadwal mundur sore.</p>']);
        $this->assertSame('Jadwal mundur sore.', $event->fresh()->description);
    }

    private function settings(array $values): void
    {
        $settings = app(GeneralSettings::class);

        foreach ($values as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'se_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => 'Pengaturan',
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'email_verified_at' => now(),
            'is_active'         => true,
            'push_enabled'      => false,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
