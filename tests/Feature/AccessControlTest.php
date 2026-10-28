<?php

namespace Tests\Feature;

use App\Filament\Resources\AdvanceResource\Pages\RealisasiAdvance;
use App\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Advance;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\RealisasiItem;
use App\Models\User;
use App\Services\RealisasiService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Temuan "Tinggi" audit 27 Okt 2026, kelompok hak akses.
 */
class AccessControlTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // =================================================================
    // Halaman realisasi advance
    // =================================================================

    public function test_realisasi_advance_orang_lain_tidak_bisa_dibuka(): void
    {
        $owner   = $this->user('Staff');
        $advance = $this->advanceOf($owner, Advance::STATUS_DIBAYARKAN);

        $this->actingAs($this->user('Staff'));

        Livewire::test(RealisasiAdvance::class, ['record' => $advance])->assertForbidden();
    }

    public function test_item_realisasi_hanya_bisa_diubah_dari_advance_sendiri_dan_sebelum_selesai(): void
    {
        $owner = $this->user('Staff');
        $this->actingAs($owner);

        $mine   = $this->advanceOf($owner, Advance::STATUS_DIBAYARKAN, withItem: 'Lampu');
        $others = $this->advanceOf($this->user('Staff'), Advance::STATUS_DIBAYARKAN, withItem: 'Kamera');

        $myItem    = RealisasiItem::whereHas('realizasi', fn ($q) => $q->where('advance_id', $mine->id))->sole();
        $otherItem = RealisasiItem::whereHas('realizasi', fn ($q) => $q->where('advance_id', $others->id))->sole();

        $page = Livewire::test(RealisasiAdvance::class, ['record' => $mine]);

        // ID item advance lain tidak tersentuh.
        $page->call('updateItem', $otherItem->id, 'Diubah', null, 1, 1);
        $this->assertSame('Kamera', $otherItem->fresh()->name);

        // Item sendiri boleh diubah selama advance belum ditutup…
        $page->call('updateItem', $myItem->id, 'Lampu LED', null, 1, 100_000);
        $this->assertSame('Lampu LED', $myItem->fresh()->name);

        // …tidak lagi setelah Selesai.
        $mine->update(['status' => Advance::STATUS_SELESAI]);
        $page->call('updateItem', $myItem->id, 'Diubah lagi', null, 1, 1);
        $this->assertSame('Lampu LED', $myItem->fresh()->name);
    }

    // =================================================================
    // Akun super_admin
    // =================================================================

    public function test_pengelola_user_tidak_bisa_mengubah_super_admin_atau_memberi_rolenya(): void
    {
        $role = Role::findOrCreate('Tes Pengelola User ' . Str::random(5), 'web');
        foreach (['view_any_user', 'view_user', 'update_user', 'delete_user'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $manager = $this->user($role->name);
        $admin   = $this->user('super_admin');
        $regular = $this->user('Staff');

        $this->assertFalse(Gate::forUser($manager)->allows('update', $admin));
        $this->assertFalse(Gate::forUser($manager)->allows('delete', $admin));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $regular));

        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $regular->getRouteKey()])
            ->fillForm(['roles' => [Role::findByName('super_admin')->id]])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertFalse($regular->fresh()->hasRole('super_admin'));
    }

    // =================================================================
    // Pengajuan expense / advance
    // =================================================================

    public function test_status_dan_pengaju_expense_ditentukan_server(): void
    {
        $user    = $this->user('super_admin');
        $other   = $this->user('Staff');
        $project = Project::create(['nama_project' => 'Tes Akses', 'nama_perusahaan' => 'PT Akses', 'status' => 'in_progress']);
        $this->actingAs($user);

        $note = 'Tes manipulasi ' . Str::random(6);

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'submission_date'    => today()->toDateString(),
                'project_id'         => $project->id,
                'category'           => 'Transport',
                'keterangan_expense' => $note,
                'amount'             => 150_000,
            ])
            // Seolah browser mengirim field tersembunyi yang dimanipulasi.
            ->set('data.status', 'Disetujui')
            ->set('data.user_id', $other->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $expense = Expense::where('keterangan_expense', $note)->sole();
        $this->assertSame('Pending', $expense->status);
        $this->assertSame($user->id, $expense->user_id);
    }

    public function test_pemilik_hanya_bisa_mengedit_pengajuan_pending(): void
    {
        $owner = $this->user('Staff');
        Role::findByName('Staff')->givePermissionTo(Permission::findOrCreate('update_expense', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $pending  = Expense::create(['user_id' => $owner->id, 'submission_date' => today(), 'category' => 'Transport', 'keterangan_expense' => 'a', 'amount' => 100_000, 'paid' => 0, 'status' => 'Pending']);
        $approved = Expense::create(['user_id' => $owner->id, 'submission_date' => today(), 'category' => 'Transport', 'keterangan_expense' => 'b', 'amount' => 100_000, 'paid' => 0, 'status' => 'Disetujui']);

        $this->assertTrue(Gate::forUser($owner)->allows('update', $pending));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $approved), 'Nominal tidak boleh diubah setelah disetujui.');
        $this->assertTrue(Gate::forUser($this->user('Finance'))->allows('update', $approved));
    }

    // =================================================================
    // Download kontrak
    // =================================================================

    public function test_download_kontrak_hanya_dari_folder_contracts(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('contracts/2026-10/spk.pdf', '%PDF-1.4 kontrak asli');

        $this->actingAs($this->user('super_admin'));

        $traversal = Contract::create(['type' => 'file', 'file_path' => '../../.env', 'contract_number' => 'TES/' . Str::random(4)]);
        $response  = $this->get(route('filament.admin.resources.contracts.download-pdf', $traversal));
        $this->assertStringNotContainsString('APP_KEY', (string) $response->streamedContent() ?: $response->getContent());

        $uploaded = Contract::create(['type' => 'file', 'file_path' => 'contracts/2026-10/spk.pdf', 'contract_number' => 'TES/' . Str::random(4)]);
        $this->assertSame('%PDF-1.4 kontrak asli', $this->get(route('filament.admin.resources.contracts.download-pdf', $uploaded))->streamedContent());
    }

    // =================================================================
    // XSS
    // =================================================================

    public function test_deskripsi_item_quotation_tidak_menjalankan_skrip(): void
    {
        $quotation = new Quotation(['uuid' => '#TES/XSS', 'nama_perusahaan' => 'PT Tes', 'date' => today(), 'due_date' => today()]);
        $quotation->setRelation('items', collect([new QuotationItem([
            'item' => 'Booth', 'qty' => 1, 'price' => 1_000, 'discount' => 0, 'vat_percentage' => 0, 'vat' => 0, 'total' => 1_000,
            'description' => '<b>Rangka besi</b><img src=x onerror="alert(document.cookie)"><script>alert(1)</script>',
        ])]));

        $settings = app(GeneralSettings::class);
        $html = view('pdf.quotation-dynamic', ['quotation' => $quotation, 'settings' => $settings->getQuotationSettings(), 'themeColor' => $settings->quot_theme_color])->render();

        $this->assertStringContainsString('<b>Rangka besi</b>', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'akses_' . Str::lower(Str::random(8)),
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

    private function advanceOf(User $owner, string $status, ?string $withItem = null): Advance
    {
        $advance = Advance::create([
            'user_id'            => $owner->id,
            'submission_date'    => today(),
            'category'           => 'Project Support',
            'keterangan_advance' => 'Tes akses',
            'amount'             => 1_000_000,
            'paid'               => 1_000_000,
            'status'             => $status,
        ]);

        if ($withItem) {
            app(RealisasiService::class)->createBatch($advance, ['uploaded_by' => $owner->id], [['name' => $withItem, 'quantity' => 1, 'price' => 100_000]]);
        }

        return $advance->fresh();
    }
}
