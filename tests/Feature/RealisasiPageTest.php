<?php

namespace Tests\Feature;

use App\Filament\Resources\AdvanceResource\Pages\RealisasiAdvance;
use App\Filament\Resources\AdvanceResource\Pages\ViewAdvance;
use App\Models\Advance;
use App\Models\Project;
use App\Models\Realisasi;
use App\Models\RealisasiItem;
use App\Models\User;
use App\Services\RealisasiService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
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

    public function test_keterangan_panjang_tidak_jadi_judul_halaman(): void
    {
        $this->actingAs($this->user('Finance'));
        $project = Project::create(['nama_project' => 'Signage Akrilik', 'nama_perusahaan' => 'Hollywing Group', 'nama_pic' => 'Sajjad', 'status' => 'in_progress']);
        $advance = $this->advance($this->user('Staff'), 3_000_000, $project);
        $long    = 'SIGNAGE 01 (Warna lampu warmwhite 3000k, body plat galvanis 1mm cat duco hitam, penutup akrilik putih susu 5mm + oracal 8500) SIGNAGE 02 (reposisi signage H LIVE SIZE 3525 x 2350 CM)';
        $advance->update(['keterangan_advance' => $long]);

        $realisasi = Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()]);
        $this->assertSame('Sajjad - Hollywing Group | Signage Akrilik', $realisasi->instance()->getSubheading());
        // Keperluan tetap bisa dibaca di halaman realisasi, satu baris dulu.
        $realisasi->assertSee('Keperluan')->assertSee($long)->assertSee('Selengkapnya');

        $view = Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()]);
        $this->assertSame('Sajjad - Hollywing Group | Signage Akrilik', $view->instance()->getSubheading());

        $advance->update(['project_id' => null]);
        $this->assertSame('Tanpa project', Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])->instance()->getSubheading());
    }

    public function test_tombol_simpan_di_footer_modal_terhubung_ke_formnya(): void
    {
        $this->actingAs($this->user('Finance'));
        $advance = $this->advance($this->user('Staff'), 1_500_000);

        $html = Livewire::test(RealisasiAdvance::class, ['record' => $advance])->html();

        // Tombol di footer modal ada di luar <form>; tanpa atribut form="…" klik
        // Simpan tidak mengirim apa pun. Di <x-filament::button> atribut itu
        // ditulis form-id; "form" ditelan komponennya (dulu terjadi di sini).
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        foreach (['Simpan batch' => 'batchForm', 'Simpan pengembalian' => 'returnForm'] as $label => $form) {
            $button = $xpath->query("//button[contains(normalize-space(.), '{$label}')]")->item(0);

            $this->assertInstanceOf(\DOMElement::class, $button, $label);
            $this->assertSame('submit', $button->getAttribute('type'), $label);
            $this->assertSame($form, $button->getAttribute('form'), $label);
            $this->assertSame(1, $xpath->query("//form[@id='{$form}']")->length, $label);
        }
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

    public function test_finance_director_dan_super_admin_bisa_menghapus_batch(): void
    {
        foreach (['Finance', 'Director', 'super_admin'] as $role) {
            $this->actingAs($this->user($role));
            $advance = $this->advance($this->user('Staff'), 1_000_000);
            $this->batch($advance, 400_000);

            Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
                ->assertSeeHtml('wire:click="deleteBatch(')
                ->call('deleteBatch', $advance->realizasis()->value('id'))
                ->assertNotified('Batch dihapus');

            $this->assertSame(0, $advance->realizasis()->count(), "{$role} bisa menghapus batch.");
        }
    }

    public function test_batch_yang_melebihi_advance_bisa_dihapus(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('Finance'));
        $owner   = $this->user('Staff');
        $advance = $this->advance($owner, 1_000_000);
        Storage::disk('public')->put('realisasi/tes/struk.jpg', 'jpg');

        // Salah ketik nominal: 1,2 jt untuk advance 1 jt. Batch inilah yang
        // paling perlu dihapus, dan dulu justru ditolak.
        $batch = $this->batch($advance, 1_200_000, 'realisasi/tes/struk.jpg');

        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
            ->call('deleteBatch', $batch->id)
            ->assertNotified('Batch dihapus');

        $this->assertSame(0, $advance->realizasis()->count());
        $this->assertEquals(0, app(RealisasiService::class)->getTotalRealisasi($advance));
        Storage::disk('public')->assertMissing('realisasi/tes/struk.jpg');
        $this->assertSame(0, RealisasiItem::where('realisasi_id', $batch->id)->count(), 'Item ikut terhapus.');

        // Tanpa batch tersisa, advance kembali menunggu realisasi pertama;
        // tanpa notifikasi "Advance Sudah Dibayarkan" palsu ke pemiliknya.
        $this->assertSame(Advance::STATUS_DIBAYARKAN, $advance->fresh()->status);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('notifications')
            ->where('notifiable_id', $owner->id)->where('data', 'like', '%Sudah Dibayarkan%')->count());
    }

    public function test_menghapus_satu_dari_dua_batch(): void
    {
        $this->actingAs($this->user('Director'));
        $advance = $this->advance($this->user('Staff'), 1_000_000);
        $first   = $this->batch($advance, 300_000);
        $this->batch($advance, 200_000);

        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])->call('deleteBatch', $first->id);

        $this->assertSame(1, $advance->realizasis()->count());
        $this->assertEquals(200_000, app(RealisasiService::class)->getTotalRealisasi($advance));
        $this->assertSame(Advance::STATUS_REALIZED_USER, $advance->fresh()->status);
    }

    public function test_batch_advance_berstatus_lama_bisa_dihapus(): void
    {
        $this->actingAs($this->user('super_admin'));
        $advance = $this->advance($this->user('Staff'), 1_000_000);
        $this->batch($advance, 600_000);
        $keep = $this->batch($advance, 400_000);
        $advance->forceFill(['status' => Advance::STATUS_READY_TO_CLOSE])->saveQuietly();

        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])
            ->call('deleteBatch', $advance->realizasis()->where('id', '!=', $keep->id)->value('id'))
            ->assertNotified('Batch dihapus');

        $this->assertSame([$keep->id], $advance->realizasis()->pluck('id')->all());
        $this->assertSame(Advance::STATUS_REALIZED_USER, $advance->fresh()->status, 'Tidak lagi pas dengan advance.');
    }

    public function test_pemilik_advance_tidak_bisa_menghapus_batch(): void
    {
        $owner   = $this->user('Staff');
        $advance = $this->advance($owner, 1_000_000);
        $batch   = $this->batch($advance, 400_000);
        $this->actingAs($owner);

        Livewire::test(RealisasiAdvance::class, ['record' => $advance->fresh()])->call('deleteBatch', $batch->id);

        $this->assertSame(1, $advance->realizasis()->count());
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

    private function batch(Advance $advance, int $price, ?string $file = null): Realisasi
    {
        return app(RealisasiService::class)->createBatch(
            $advance->fresh(),
            ['uploaded_by' => $advance->user_id],
            [['name' => 'Material', 'quantity' => 1, 'price' => $price, 'files' => $file ? [['original_name' => 'struk.jpg', 'filepath' => $file, 'filesize' => 3, 'mime_type' => 'image/jpeg']] : []]]
        );
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
