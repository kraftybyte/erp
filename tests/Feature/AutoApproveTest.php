<?php

namespace Tests\Feature;

use App\Filament\Pages\Setting\ManageGeneral;
use App\Filament\Resources\AdvanceResource\Pages\CreateAdvance;
use App\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auto approve expense & advance untuk karyawan tertentu (Settings → General → Persetujuan).
 */
class AutoApproveTest extends TestCase
{
    use DatabaseTransactions;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->project = Project::create(['nama_project' => 'Tes Auto Approve', 'nama_perusahaan' => 'PT Auto', 'status' => 'in_progress']);
    }

    public function test_expense_karyawan_di_daftar_langsung_disetujui(): void
    {
        $finance = $this->user('Finance');
        $trusted = $this->user('super_admin');
        $this->autoApprove($trusted);

        $this->actingAs($trusted);
        $note = 'Tes auto ' . Str::random(6);

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'submission_date'    => today()->toDateString(),
                'project_id'         => $this->project->id,
                'category'           => 'Transport',
                'keterangan_expense' => $note,
                'amount'             => 25_000_000,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Expense terkirim dan langsung disetujui (auto approve)');

        $expense = Expense::where('keterangan_expense', $note)->sole();
        $this->assertSame('Disetujui', $expense->status, 'Tanpa batas nominal.');
        $this->assertEquals(0, $expense->paid, 'Pembayaran tetap lewat Finance.');
        $this->assertStringContainsString('Disetujui Otomatis', $this->lastNotificationOf($finance));
    }

    public function test_karyawan_lain_tetap_menunggu_persetujuan(): void
    {
        $finance = $this->user('Finance');
        $this->autoApprove($this->user('Staff'));

        $this->actingAs($other = $this->user('super_admin'));
        $note = 'Tes biasa ' . Str::random(6);

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'submission_date'    => today()->toDateString(),
                'project_id'         => $this->project->id,
                'category'           => 'Transport',
                'keterangan_expense' => $note,
                'amount'             => 150_000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Pending', Expense::where('keterangan_expense', $note)->value('status'));
        $this->assertStringContainsString('Pengajuan Expense Baru', $this->lastNotificationOf($finance));
    }

    public function test_advance_karyawan_di_daftar_langsung_disetujui_dan_belum_dicairkan(): void
    {
        $trusted = $this->user('super_admin');
        $this->autoApprove($trusted);
        $this->actingAs($trusted);

        $note = 'Advance auto ' . Str::random(6);

        Livewire::test(CreateAdvance::class)
            ->fillForm([
                'submission_date'    => today()->toDateString(),
                'project_id'         => $this->project->id,
                'category'           => 'Project Support',
                'keterangan_advance' => $note,
                'amount'             => 3_000_000,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Advance terkirim dan langsung disetujui (auto approve)');

        $advance = Advance::where('keterangan_advance', $note)->sole();
        $this->assertSame(Advance::STATUS_DISETUJUI, $advance->status);
        $this->assertFalse(Transaction::where('reference_type', 'advance')->where('reference_id', $advance->id)->exists());
    }

    public function test_daftar_karyawan_diatur_di_halaman_setting(): void
    {
        $this->actingAs($this->user('super_admin'));

        Livewire::test(ManageGeneral::class)->assertFormFieldExists('auto_approve_user_ids');
    }

    // =================================================================
    // Helper
    // =================================================================

    private function autoApprove(User $user): void
    {
        $settings = app(GeneralSettings::class);
        // Isian dari form Select berupa string.
        $settings->auto_approve_user_ids = [(string) $user->id];
        $settings->save();
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'auto_' . Str::lower(Str::random(8)),
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

    private function lastNotificationOf(User $user): string
    {
        return (string) DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->latest('created_at')
            ->value('data');
    }
}
