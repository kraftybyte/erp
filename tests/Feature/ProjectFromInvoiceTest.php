<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\ProjectResource;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectFromInvoice;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tombol "Buat Project" di invoice: cukup pilih kategori, project langsung
 * dibuat dari invoice.
 */
class ProjectFromInvoiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_project_langsung_dibuat_cukup_pilih_kategori(): void
    {
        $this->actingAs($this->user('super_admin'));

        $deal    = $this->deal('Toko Sinar', 'Budi Santoso');
        $invoice = $this->invoice($deal, [
            ['item' => 'Neon Box', 'qty' => 2, 'description' => '<p>Ukuran 2x1 m</p><ul><li>Akrilik susu</li><li>Lampu LED</li></ul>'],
            ['item' => 'Huruf Timbul', 'qty' => 1, 'description' => null],
        ]);
        $category = array_key_first(ProjectResource::getProjectCategoryOptions());

        Livewire::test(ListInvoices::class)
            ->callTableAction('project', $invoice, data: ['nama_project' => $category])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Project dibuat');

        $project = Project::where('invoice_id', $invoice->id)->sole();
        $this->assertSame($category, $project->nama_project);
        $this->assertSame($deal->id, $project->deal_id);
        $this->assertSame(['Toko Sinar', 'Budi Santoso'], [$project->nama_perusahaan, $project->nama_pic]);
        $this->assertSame([today()->toDateString(), today()->addDays(14)->toDateString()], [
            $project->timeline_mulai->toDateString(), $project->timeline_selesai->toDateString(),
        ]);

        $this->assertStringContainsString("Dari invoice {$invoice->uuid}", $project->detail_project);
        $this->assertStringContainsString('- **Neon Box** (2x)', $project->detail_project);
        $this->assertStringContainsString('• Lampu LED', $project->detail_project);
        $this->assertStringNotContainsString('<', $project->detail_project, 'Tanpa tag HTML.');

        // Tombol yang sama kini membuka project itu, bukan membuat lagi.
        Livewire::test(ListInvoices::class)
            ->assertTableActionHasUrl('project', ProjectResource::getUrl('view', ['record' => $project]), $invoice->fresh());
    }

    public function test_client_dibuat_otomatis_bila_belum_ada(): void
    {
        $this->actingAs($this->user('super_admin'));

        $company = 'PT Klien Baru ' . Str::upper(Str::random(5));
        $invoice = $this->invoice(null, [['item' => 'Booth', 'qty' => 1, 'description' => 'Booth 3x3']], [
            'nama_perusahaan' => $company,
            'nama_pic'        => 'Rina Kartika',
            'telephone'       => '08123456789',
            'alamat'          => 'Jl. Baru No. 2',
        ]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('project', data: ['nama_project' => array_key_first(ProjectResource::getProjectCategoryOptions())])
            ->assertHasNoActionErrors();

        $deal = Deal::where('nama_perusahaan', $company)->sole();
        $this->assertSame(['Rina Kartika', '08123456789', 'Dalam Tahap Project'], [$deal->nama_pic, $deal->telephone, $deal->status]);
        $this->assertSame($deal->id, $invoice->fresh()->deal_id, 'Invoice ikut tertaut ke client baru.');
        $this->assertSame($deal->id, Project::where('invoice_id', $invoice->id)->value('deal_id'));
    }

    public function test_client_dicocokkan_dari_nama_dan_pic_tanpa_membuat_baru(): void
    {
        $this->deal('PT Cabang Ganda', 'Sari Dewi');
        $budi    = $this->deal('PT Cabang Ganda', 'Budi Santoso');
        $invoice = $this->invoice(null, [['item' => 'Booth', 'qty' => 1, 'description' => 'Booth 3x3']], [
            'nama_perusahaan' => 'PT Cabang Ganda',
            'nama_pic'        => 'Budi Santoso',
        ]);
        $deals = Deal::count();

        $project = ProjectFromInvoice::create($invoice, 'SEO');

        $this->assertSame($budi->id, $project->deal_id);
        $this->assertSame($deals, Deal::count());
    }

    public function test_klik_ganda_tidak_membuat_project_dobel(): void
    {
        $invoice = $this->invoice($this->deal('PT Sekali Saja', 'Andi'), [['item' => 'Video', 'qty' => 1, 'description' => null]]);

        $first  = ProjectFromInvoice::create($invoice, 'SEO');
        $second = ProjectFromInvoice::create($invoice, 'Website Development');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Project::where('invoice_id', $invoice->id)->count());
    }

    public function test_tombol_tidak_tampil_tanpa_izin_membuat_project(): void
    {
        $role = Role::findOrCreate('Tes Lihat Invoice ' . Str::random(5), 'web');
        foreach (['view_any_invoice', 'view_invoice'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($this->user($role->name));

        $invoice = $this->invoice($this->deal('PT Tanpa Izin', 'Rina'), [['item' => 'Kontrak', 'qty' => 1, 'description' => null]]);

        Livewire::test(ListInvoices::class)->assertTableActionHidden('project', $invoice);
    }

    public function test_simpan_invoice_tidak_menghapus_tautan_client(): void
    {
        $this->actingAs($this->user('super_admin'));

        $deal    = $this->deal('PT Tetap Tertaut', 'Andi');
        $invoice = $this->invoice($deal, [['item' => 'Video Profile', 'qty' => 1, 'description' => 'Durasi 2 menit']]);

        Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($deal->id, $invoice->fresh()->deal_id, 'Dulu deal_id menjadi null setiap kali invoice disimpan.');
    }

    // =================================================================
    // Helper
    // =================================================================

    private function deal(string $company, string $pic): Deal
    {
        return Deal::forceCreate([
            'nama_perusahaan' => $company,
            'nama_pic'        => $pic,
            'alamat'          => 'Jl. Tes No. 1',
            'notes'           => 'Klien lama',
        ]);
    }

    private function invoice(?Deal $deal, array $items, array $attributes = []): Invoice
    {
        $total = collect($items)->sum(fn (array $item) => $item['qty'] * 1_000_000);

        $invoice = Invoice::create($attributes + [
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'deal_id'         => $deal?->id,
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'draft',
            'nama_perusahaan' => $deal?->nama_perusahaan,
            'nama_pic'        => $deal?->nama_pic,
            'alamat'          => $deal?->alamat,
            'date'            => today(),
            'due_date'        => today()->addDays(14),
            'subtotal'        => $total,
            'total'           => $total,
            'grand_total'     => $total,
            'paid'            => 0,
            'currency_id'     => 66,
        ]);

        foreach ($items as $item) {
            $invoice->invoicesItems()->create($item + [
                'price'          => 1_000_000,
                'original_price' => 1_000_000,
                'discount'       => 0,
                'vat'            => 0,
                'vat_percentage' => 0,
                'total'          => $item['qty'] * 1_000_000,
            ]);
        }

        return $invoice->fresh();
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'pfi_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Project',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
