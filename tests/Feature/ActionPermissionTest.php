<?php

namespace Tests\Feature;

use App\Filament\Resources\AbsenceResource\Pages\CreateAbsence;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\PaymentAccountResource\Pages\ViewPaymentAccount;
use App\Filament\Resources\PaymentAccountResource\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Widgets\TaskTodayWidget;
use App\Models\Absence;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tombol tulis (bayar, ubah status, setoran, role, task, izin) mengikuti izin
 * Shield, bukan sekadar bisa melihat halamannya.
 */
class ActionPermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_peran_lihat_saja_tidak_bisa_membayar_atau_mengubah_status_invoice(): void
    {
        $this->actingAs($this->userWith(['view_any_invoice', 'view_invoice']));
        $invoice = $this->invoice();

        Livewire::test(ListInvoices::class)
            ->assertTableActionHidden('pay', $invoice)
            ->assertTableBulkActionHidden('status');

        $this->actingAs($this->userWith(['view_any_invoice', 'view_invoice', 'update_invoice']));

        Livewire::test(ListInvoices::class)
            ->assertTableActionVisible('pay', $invoice)
            ->assertTableBulkActionVisible('status');
    }

    public function test_setoran_rekening_butuh_izin_ubah_rekening(): void
    {
        $account = PaymentAccount::create(['name' => 'Rek ' . Str::random(4), 'bank_name' => 'BCA', 'account_number' => (string) random_int(1_000_000, 9_999_999), 'account_holder' => 'Tes', 'initial_balance' => 0, 'is_active' => true]);
        $this->actingAs($this->userWith(['view_any_payment::account', 'view_payment::account']));

        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewPaymentAccount::class])
            ->assertTableActionHidden('add_deposit')
            ->assertTableActionHidden('add_withdrawal');
    }

    public function test_role_user_hanya_bisa_diubah_dengan_izin_update_role(): void
    {
        $target = $this->userWith([]);
        $other  = Role::findOrCreate('Tes Peran Lain ' . Str::random(5), 'web');
        $this->actingAs($this->userWith(['view_any_user', 'view_user', 'update_user']));

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['roles' => [$other->id]])
            ->call('save');

        $this->assertFalse($target->fresh()->hasRole($other->name));
    }

    public function test_task_orang_lain_tidak_bisa_ditandai_selesai_dari_widget(): void
    {
        $owner = $this->userWith([]);
        $task  = Task::create(['nama_task' => 'Task orang lain', 'status' => 'process', 'mulai_task' => today(), 'selesai_task' => today()->addDay(), 'proritas' => 'High']);
        $task->users()->attach($owner);

        $this->actingAs($this->userWith([]));

        Livewire::test(TaskTodayWidget::class)
            ->call('openDoneModal', $task->id)
            ->assertSet('showModal', false)
            ->set('modalTaskId', $task->id)
            ->call('confirmDone');

        $this->assertSame('process', $task->fresh()->status);
    }

    public function test_izin_hanya_bisa_diubah_pemilik_selama_pending_dan_pengaju_dari_server(): void
    {
        $owner = $this->userWith(['view_any_absence', 'create_absence', 'update_absence']);
        $other = $this->userWith([]);
        $this->actingAs($owner);

        $note = 'Tes izin ' . Str::random(6);
        Livewire::test(CreateAbsence::class)
            ->fillForm(['category' => 'wfa', 'description' => $note, 'start_time' => today()->toDateString(), 'end_time' => today()->toDateString()])
            ->set('data.user_id', $other->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $absence = Absence::where('description', $note)->sole();
        $this->assertSame($owner->id, (int) $absence->user_id);
        $this->assertTrue(Gate::forUser($owner)->allows('update', $absence));

        $absence->update(['approval_status' => 'approved']);
        $this->assertFalse(Gate::forUser($owner)->allows('update', $absence->fresh()));

        $absence->update(['user_id' => $other->id, 'approval_status' => 'pending']);
        $this->assertFalse(Gate::forUser($owner)->allows('update', $absence->fresh()), 'Izin orang lain tidak bisa dibuka.');
    }

    public function test_pengelola_expense_ditentukan_izin_shield_bukan_nama_peran(): void
    {
        $owner    = $this->userWith([]);
        $approved = Expense::create(['user_id' => $owner->id, 'submission_date' => today(), 'category' => 'Transport', 'keterangan_expense' => 'x', 'amount' => 100_000, 'paid' => 0, 'status' => 'Disetujui']);

        $this->assertFalse(Gate::forUser($this->userWith(['update_expense']))->allows('update', $approved));
        $this->assertTrue(Gate::forUser($this->userWith(['restore_expense']))->allows('update', $approved));

        // Finance tetap boleh setelah deploy: migrasi memberinya izin itu.
        (require base_path('database/migrations/2026_11_13_120000_grant_action_permissions.php'))->up();
        $this->assertTrue(Gate::forUser($this->userWith([], 'Finance'))->allows('update', $approved));
    }

    public function test_diskon_baris_melebihi_harga_tidak_membuat_total_minus(): void
    {
        $this->actingAs($this->userWith([], 'super_admin'));

        $form = Livewire::test(CreateInvoice::class);
        $key  = array_key_first($form->get('data.invoicesItems'));

        $form->set("data.invoicesItems.{$key}.item", 'Booth')
            ->set("data.invoicesItems.{$key}.qty", 1)
            ->set("data.invoicesItems.{$key}.vat_percentage", 0)
            ->set("data.invoicesItems.{$key}.price", 100_000)
            ->set("data.invoicesItems.{$key}.discount", 150_000)
            ->assertSet('data.total_discount', 100_000)
            ->assertSet('data.grand_total', 0);
    }

    public function test_nomor_invoice_diambil_saat_simpan(): void
    {
        $this->actingAs($this->userWith([], 'super_admin'));

        $form    = Livewire::test(CreateInvoice::class);
        $preview = $form->get('data.uuid');
        $key     = array_key_first($form->get('data.invoicesItems'));

        // Orang lain menyimpan invoice dengan nomor yang sama selagi form ini terbuka.
        $this->invoice($preview);

        $form->set('data.nama_perusahaan', 'PT Nomor Bentrok')
            ->set('data.type_payment', 'full_payment')
            ->set("data.invoicesItems.{$key}.item", 'Booth')
            ->set("data.invoicesItems.{$key}.qty", 1)
            ->set("data.invoicesItems.{$key}.vat_percentage", 0)
            ->set("data.invoicesItems.{$key}.price", 1_000_000)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotSame($preview, Invoice::where('nama_perusahaan', 'PT Nomor Bentrok')->latest('id')->value('uuid'));
    }

    // =================================================================
    // Helper
    // =================================================================

    private function userWith(array $permissions, ?string $role = null): User
    {
        $user = User::forceCreate([
            'username'     => 'aksi_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Aksi',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);

        $role ??= Role::findOrCreate('Tes Aksi ' . Str::random(6), 'web')
            ->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), $permissions))
            ->name;
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function invoice(?string $uuid = null): Invoice
    {
        return Invoice::create([
            'uuid'            => $uuid ?? Invoice::generateUuid('sale'),
            'user_id'         => User::query()->value('id'),
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'pending',
            'nama_perusahaan' => 'PT Aksi ' . Str::random(4),
            'date'            => now(),
            'due_date'        => now()->addWeek(),
            'subtotal'        => 1_000_000,
            'grand_total'     => 1_000_000,
            'paid'            => 0,
        ]);
    }
}
