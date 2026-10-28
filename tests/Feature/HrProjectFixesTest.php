<?php

namespace Tests\Feature;

use App\Filament\Resources\AbsenceResource\Pages\ListAbsences;
use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\PurchaseRequestResource\Pages\ListPurchaseRequests;
use App\Models\Absence;
use App\Models\Announcement;
use App\Models\Expense;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\User;
use App\Observers\AnnouncementObserver;
use App\Observers\TaskObserver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Temuan "Tinggi" audit 27 Okt 2026, kelompok HR & project.
 */
class HrProjectFixesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_approve_dan_reject_cuti_berhasil_dengan_satu_notifikasi(): void
    {
        $this->actingAs($this->user('super_admin'));
        $employee = $this->user('Staff');

        $approve = $this->absence($employee);
        $reject  = $this->absence($employee);
        $before  = $this->notificationsOf($employee);

        Livewire::test(ListAbsences::class)
            ->callTableAction('approve', $approve)
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $reject, data: ['rejection_reason' => 'Bentrok dengan event klien'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('approved', $approve->fresh()->approval_status);
        $this->assertSame('rejected', $reject->fresh()->approval_status);

        $sent = $this->notificationsOf($employee)->slice($before->count());
        $this->assertCount(2, $sent, 'Satu notifikasi per keputusan, bukan dobel.');
        $this->assertStringContainsString('Bentrok dengan event klien', $sent->implode(' '));
    }

    public function test_approve_dan_reject_purchase_request_tersimpan(): void
    {
        foreach (['Finance', 'Staff'] as $role) {
            Role::findByName($role)->givePermissionTo(Permission::findOrCreate('view_any_purchase::request', 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $requester = $this->user('Staff');
        $approve   = $this->purchaseRequest($requester);
        $reject    = $this->purchaseRequest($requester);
        $others    = $this->purchaseRequest($this->user('Staff'));

        // Pengaju biasa bisa membuka daftar dan hanya melihat miliknya.
        $this->actingAs($requester);
        Livewire::test(ListPurchaseRequests::class)
            ->assertCanSeeTableRecords([$approve, $reject])
            ->assertCanNotSeeTableRecords([$others])
            ->assertTableActionHidden('approve', $approve);

        $this->actingAs($this->user('Finance'));
        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('approve', $approve)
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $reject, data: ['reason' => 'Anggaran habis'])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('approve', $approve->fresh());

        $this->assertSame('approved', $approve->fresh()->status);
        $this->assertSame(['rejected', 'Anggaran habis'], [$reject->fresh()->status, $reject->fresh()->reason]);
        $this->assertSame('Disetujui', PurchaseRequest::statusLabel('approved'));
    }

    public function test_tambah_karyawan_berhasil(): void
    {
        $this->actingAs($this->user('super_admin'));
        $email = 'karyawan.' . Str::lower(Str::random(6)) . '@example.test';

        $form = [
            'nik'       => (string) random_int(1_000_000_000, 9_999_999_999),
            'firstname' => 'Budi',
            'lastname'  => 'Santoso',
            'email'     => $email,
            'phone'     => '6281234567890',
            'address'   => 'Jl. Tes',
            'position'  => 'Staff',
            'division'  => 'Finance',
        ];

        Livewire::test(CreateEmployee::class)->fillForm($form)->call('create')->assertHasNoFormErrors();

        $employee = User::where('email', $email)->sole();
        $this->assertStringStartsWith('karyawan.', $employee->username);
        $this->assertNotEmpty($employee->password);

        // Email yang sama ditolak dengan pesan, bukan error database.
        Livewire::test(CreateEmployee::class)
            ->fillForm(['nik' => (string) random_int(1_000_000_000, 9_999_999_999)] + $form)
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_karyawan_yang_punya_data_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->user('super_admin'));

        $withData = $this->user('Staff');
        $expense  = Expense::create(['user_id' => $withData->id, 'submission_date' => today(), 'category' => 'Transport', 'keterangan_expense' => 'Tes', 'amount' => 50_000, 'paid' => 0, 'status' => 'Pending']);
        $empty    = $this->user('Staff');

        $this->assertFalse($withData->delete(), 'Penjaga di model.');

        Livewire::test(ListEmployees::class)->callTableAction('delete', $withData);
        $this->assertNotNull(User::find($withData->id));
        $this->assertNotNull(Expense::find($expense->id), 'Expense tidak ikut terhapus.');

        Livewire::test(ListEmployees::class)->callTableAction('delete', $empty);
        $this->assertNull(User::find($empty->id), 'User tanpa data tetap bisa dihapus.');
    }

    public function test_perubahan_status_task_memberi_tahu_assignee_sekali(): void
    {
        $this->actingAs($this->user('super_admin'));
        $assignee = $this->user('Staff');

        $task = Task::create(['nama_task' => 'Desain booth', 'status' => 'Process', 'mulai_task' => today(), 'selesai_task' => today()->addWeek(), 'proritas' => 'High']);
        $task->users()->attach($assignee);
        $this->assertSame('process', $task->fresh()->status, 'Status disimpan huruf kecil.');

        $before = $this->notificationsOf($assignee)->count();
        $task->update(['status' => 'Done']);

        $sent = $this->notificationsOf($assignee)->slice($before);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Task Selesai', $sent->first());
        $this->assertSame('done', $task->fresh()->status);

        $this->assertSame(1, $this->observerCount('eloquent.updated: ' . Task::class, TaskObserver::class), 'Observer task terdaftar sekali.');
        $this->assertSame(1, $this->observerCount('eloquent.created: ' . Announcement::class, AnnouncementObserver::class), 'Observer pengumuman terdaftar sekali.');
    }

    // =================================================================
    // Helper
    // =================================================================

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'hr_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => $role,
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function absence(User $user): Absence
    {
        return Absence::create([
            'user_id'         => $user->id,
            'category'        => 'cuti',
            'description'     => 'Tes cuti',
            'start_time'      => today()->addDays(3),
            'end_time'        => today()->addDays(4),
            'approval_status' => 'pending',
        ]);
    }

    private function purchaseRequest(User $user): PurchaseRequest
    {
        return PurchaseRequest::create([
            'item_name'    => 'Printer ' . Str::random(4),
            'quantity'     => 1,
            'price'        => 2_500_000,
            'status'       => 'pending',
            'requested_by' => $user->id,
        ]);
    }

    private function observerCount(string $event, string $observer): int
    {
        return collect(Event::getRawListeners()[$event] ?? [])
            ->filter(fn ($listener) => is_string($listener) && str_starts_with($listener, $observer . '@'))
            ->count();
    }

    /**
     * Isi notifikasi database milik user, urut waktu.
     */
    private function notificationsOf(User $user): \Illuminate\Support\Collection
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('data')
            ->values();
    }
}
