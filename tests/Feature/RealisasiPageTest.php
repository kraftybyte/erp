<?php

namespace Tests\Feature;

use App\Filament\Resources\AdvanceResource\Pages\RealisasiAdvance;
use App\Filament\Resources\AdvanceResource\Pages\ViewAdvance;
use App\Models\Advance;
use App\Models\Project;
use App\Models\User;
use App\Services\RealisasiService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Halaman realisasi advance: isi yang ditampilkan dan tindakan Finance yang
 * hanya berlaku selama advance masih berjalan.
 */
class RealisasiPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_halaman_menampilkan_project_catatan_qty_dan_nama_file_dengan_benar(): void
    {
        $owner   = $this->user('Staff');
        $this->actingAs($this->user('Finance'));
        $project = Project::create(['nama_project' => 'Video Profile', 'nama_perusahaan' => 'PT Nestory', 'nama_pic' => 'Budi', 'status' => 'in_progress']);
        $advance = $this->advance($owner, 2_000_000, $project);

        app(RealisasiService::class)->createBatch($advance, ['uploaded_by' => $owner->id, 'notes' => 'Shooting hari pertama'], [
            ['name' => 'Sewa lighting', 'quantity' => 2, 'price' => 350_000],
        ])->items()->first()->files()->create([
            'original_name' => 'nota-toko-bangunan-sinar-jaya.pdf', 'filepath' => 'realisasi/x/nota.pdf', 'filesize' => 10, 'mime_type' => 'application/pdf',
        ]);

        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
            ->assertSee('Budi - PT Nestory | Video Profile')   // dulu kosong: $project->name
            ->assertSee('Shooting hari pertama')               // catatan batch dulu tidak tampil
            ->assertSee('2 × Rp 350.000')
            ->assertSeeHtml('class="num qty">2</div>')             // dulu "2.00"
            ->assertSee('nota-toko-bangunan….pdf')
            ->assertSee('Sisa Rp 1.300.000 belum direalisasi');
    }

    public function test_belum_ada_realisasi_tidak_disebut_menunggu_return(): void
    {
        $this->actingAs($this->user('Finance'));
        $advance = $this->advance($this->user('Staff'), 1_500_000);

        Livewire::test(RealisasiAdvance::class, ['record' => $advance])
            ->assertSee('Belum ada batch realisasi')
            ->assertDontSee('Menunggu Return')
            ->assertDontSee('harus dikembalikan');
    }

    public function test_tombol_mengikuti_peran_dan_keadaan(): void
    {
        $owner   = $this->user('Staff');
        $advance = $this->advance($owner, 1_000_000);
        app(RealisasiService::class)->createBatch($advance, ['uploaded_by' => $owner->id], [['name' => 'Bensin', 'quantity' => 1, 'price' => 400_000]]);

        $this->actingAs($owner);
        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
            ->assertActionVisible('addBatch')
            ->assertActionHidden('return')
            ->assertActionHidden('finish')
            ->assertDontSeeHtml('wire:click="deleteBatch(');

        $this->actingAs($this->user('Finance'));
        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
            ->assertActionVisible('return')
            ->assertActionHidden('finish')
            ->assertActionHidden('approveOverrun')
            ->assertSeeHtml('wire:click="deleteBatch(');
    }

    public function test_tindakan_finance_ditolak_pada_advance_yang_sudah_selesai(): void
    {
        $this->actingAs($this->user('Finance'));
        $owner   = $this->user('Staff');
        $advance = $this->advance($owner, 1_000_000);
        app(RealisasiService::class)->createBatch($advance, ['uploaded_by' => $owner->id], [['name' => 'Material', 'quantity' => 1, 'price' => 1_200_000]]);

        $page = Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
            ->assertActionVisible('approveOverrun')
            ->callAction('approveOverrun');
        $this->assertNotNull($advance->fresh()->overrun_approved_by);

        // Menyetujui lagi tidak mengubah status (dulu mengembalikannya ke "Sedang di-Realisasi User").
        $advance->update(['status' => Advance::STATUS_READY_TO_CLOSE]);
        $page->call('approveOverrun');
        $this->assertSame(Advance::STATUS_READY_TO_CLOSE, $advance->fresh()->status);

        $page->callAction('finish');
        $this->assertSame(Advance::STATUS_SELESAI, $advance->fresh()->status);

        // Setelah Selesai: tidak ada tindakan Finance, dan memanggilnya langsung ditolak.
        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
            ->assertActionHidden('approveOverrun')
            ->assertActionHidden('finish')
            ->assertActionHidden('addBatch')
            ->call('deleteBatch', $advance->realizasis()->value('id'));
        $this->assertSame(1, $advance->realizasis()->count(), 'Batch advance yang sudah selesai tidak bisa dihapus.');
    }

    public function test_pengembalian_ditolak_untuk_advance_yang_belum_dicairkan(): void
    {
        $this->actingAs($this->user('Finance'));
        $advance = $this->advance($this->user('Staff'), 1_000_000, status: Advance::STATUS_DISETUJUI);

        Livewire::test(RealisasiAdvance::class, ['record' => $advance])
            ->assertActionHidden('return')
            ->call('processReturn');

        $this->assertSame(Advance::STATUS_DISETUJUI, $advance->fresh()->status);
        $this->assertFalse(\App\Models\Transaction::where('reference_type', 'advance_return')->where('reference_id', $advance->id)->exists());
    }

    public function test_view_advance_menampilkan_semua_batch_dan_jalan_ke_realisasi(): void
    {
        $owner   = $this->user('Staff');
        $this->actingAs($this->user('Finance'));
        $project = Project::create(['nama_project' => 'Video Profile', 'nama_perusahaan' => 'PT Nestory', 'nama_pic' => 'Budi', 'status' => 'in_progress']);
        $advance = $this->advance($owner, 2_000_000, $project);
        $service = app(RealisasiService::class);
        $service->createBatch($advance, ['uploaded_by' => $owner->id], [['name' => 'Sewa lighting', 'quantity' => 2, 'price' => 350_000]]);
        $service->createBatch($advance->fresh(), ['uploaded_by' => $owner->id], [['name' => 'Konsumsi kru', 'quantity' => 1, 'price' => 200_000]]);
        $advance->update(['status' => Advance::STATUS_REALIZED_USER, 'reference_number' => 'TRF-0912']);

        Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
            ->assertOk()
            ->assertSee('Budi - PT Nestory | Video Profile')   // dulu hanya nama perusahaan
            ->assertSee('TRF-0912')                             // dulu selalu "-" (kolom salah)
            ->assertSee('Sewa lighting')
            ->assertSee('Konsumsi kru')                         // batch kedua dulu terpotong
            ->assertSee('Rp 900.000')
            ->assertActionVisible('realisasi');                 // dulu tidak ada untuk status ini

        $pending = $this->advance($owner, 500_000, status: Advance::STATUS_DISETUJUI);
        Livewire::test(ViewAdvance::class, ['record' => $pending->getRouteKey()])
            ->assertSee('Realisasi bisa dicatat setelah advance dicairkan.')
            ->assertActionHidden('realisasi');
    }

    public function test_daftar_advance_menampilkan_ringkasan_realisasi(): void
    {
        $owner   = $this->user('Staff');
        $this->actingAs($this->user('Finance'));
        $advance = $this->advance($owner, 1_000_000);
        app(RealisasiService::class)->createBatch($advance, ['uploaded_by' => $owner->id], [['name' => 'Bensin', 'quantity' => 1, 'price' => 400_000]]);

        Livewire::test(\App\Filament\Resources\AdvanceResource\Pages\ListAdvances::class)
            ->assertOk()
            ->assertSee('Rp 400.000')
            ->assertSee('40% · sisa Rp 600.000')
            ->assertDontSee('Dicairkan Rp 1.000.000');   // sama dengan yang diajukan, tidak diulang
    }

    public function test_tombol_bayarkan_hanya_untuk_advance_yang_disetujui(): void
    {
        $this->actingAs($this->user('super_admin'));
        $owner      = $this->user('Staff');
        $disetujui  = $this->advance($owner, 500_000, status: Advance::STATUS_DISETUJUI);
        $realisasi  = $this->advance($owner, 500_000);
        $realisasi->update(['status' => Advance::STATUS_REALIZED_USER]);

        Livewire::test(\App\Filament\Resources\AdvanceResource\Pages\ListAdvances::class)
            ->assertTableActionVisible('convert_to_pay', $disetujui)
            ->assertTableActionHidden('convert_to_pay', $realisasi)   // dulu tampil walau sudah dibayar
            ->assertTableActionVisible('realisasi', $realisasi);
    }

    public function test_selesaikan_dari_daftar_yang_belum_siap_memberi_pesan_bukan_error(): void
    {
        $this->actingAs($this->user('super_admin'));
        $owner   = $this->user('Staff');
        $advance = $this->advance($owner, 1_000_000);
        app(RealisasiService::class)->createBatch($advance, ['uploaded_by' => $owner->id], [['name' => 'Bensin', 'quantity' => 1, 'price' => 400_000]]);
        $advance->update(['status' => Advance::STATUS_REALIZED_USER]);

        Livewire::test(\App\Filament\Resources\AdvanceResource\Pages\ListAdvances::class)
            ->callTableAction('selesai', $advance)
            ->assertNotified('Advance belum bisa diselesaikan');

        $this->assertSame(Advance::STATUS_REALIZED_USER, $advance->fresh()->status);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function advance(User $owner, float $paid, ?Project $project = null, string $status = Advance::STATUS_DIBAYARKAN): Advance
    {
        return Advance::create([
            'user_id'            => $owner->id,
            'submission_date'    => today(),
            'project_id'         => $project?->id,
            'category'           => 'Project Support',
            'keterangan_advance' => 'Tes realisasi ' . Str::random(4),
            'amount'             => $paid,
            'paid'               => $status === Advance::STATUS_DISETUJUI ? 0 : $paid,
            'status'             => $status,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'rl_' . Str::lower(Str::random(8)),
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
}
