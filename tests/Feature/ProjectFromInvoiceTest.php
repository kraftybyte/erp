<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tombol "Buat Project" di invoice: form project terisi dari invoice, tinggal
 * memilih kategori.
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

    public function test_project_dibuat_dari_invoice_dengan_data_terisi(): void
    {
        $this->actingAs($this->user('super_admin'));

        $deal    = $this->deal('Toko Sinar', 'Budi Santoso');
        $invoice = $this->invoice($deal, [
            ['item' => 'Neon Box', 'qty' => 2, 'description' => '<p>Ukuran 2x1 m</p><ul><li>Akrilik susu</li><li>Lampu LED</li></ul>'],
            ['item' => 'Huruf Timbul', 'qty' => 1, 'description' => null],
        ]);

        $createUrl = ProjectResource::getUrl('create', ['invoice' => $invoice->id]);

        Livewire::test(ListInvoices::class)
            ->assertTableActionVisible('project', $invoice)
            ->assertTableActionHasUrl('project', $createUrl, $invoice);

        $page = Livewire::withQueryParams(['invoice' => $invoice->id])->test(CreateProject::class)
            ->assertFormSet([
                'deal_id'         => $deal->id,
                'nama_perusahaan' => 'Toko Sinar',
                'nama_pic'        => 'Budi Santoso',
            ]);

        $detail = $page->get('data.detail_project');
        $this->assertStringContainsString("Dari invoice {$invoice->uuid}", $detail);
        $this->assertStringContainsString('- **Neon Box** (2x)', $detail);
        $this->assertStringContainsString('• Lampu LED', $detail);
        $this->assertStringNotContainsString('<', $detail, 'Tanpa tag HTML.');

        // Pengguna cukup memilih kategori.
        $category = array_key_first(ProjectResource::getProjectCategoryOptions());
        $page->fillForm(['nama_project' => $category])->call('create')->assertHasNoFormErrors();

        $project = Project::where('invoice_id', $invoice->id)->sole();
        $this->assertSame($category, $project->nama_project);
        $this->assertSame($deal->id, $project->deal_id);

        // Tombol yang sama kini membuka project itu, bukan membuat lagi.
        Livewire::test(ListInvoices::class)
            ->assertTableActionHasUrl('project', ProjectResource::getUrl('view', ['record' => $project]), $invoice->fresh());
    }

    public function test_invoice_tanpa_tautan_mencari_client_dari_nama_dan_pic(): void
    {
        $this->actingAs($this->user('super_admin'));

        $this->deal('PT Cabang Ganda', 'Sari Dewi');
        $budi    = $this->deal('PT Cabang Ganda', 'Budi Santoso');
        $invoice = $this->invoice(null, [['item' => 'Booth', 'qty' => 1, 'description' => 'Booth 3x3']], [
            'nama_perusahaan' => 'PT Cabang Ganda',
            'nama_pic'        => 'Budi Santoso',
        ]);

        Livewire::withQueryParams(['invoice' => $invoice->id])->test(CreateProject::class)
            ->assertFormSet(['deal_id' => $budi->id, 'nama_pic' => 'Budi Santoso']);
    }

    public function test_invoice_yang_tidak_boleh_dilihat_tidak_dipakai_untuk_mengisi(): void
    {
        $role = Role::findOrCreate('Tes Pembuat Project ' . Str::random(5), 'web');
        $role->givePermissionTo(Permission::findOrCreate('create_project', 'web'));
        $this->actingAs($this->user($role->name));

        $invoice = $this->invoice($this->deal('PT Rahasia', 'Rina'), [['item' => 'Kontrak Rahasia', 'qty' => 1, 'description' => 'Nilai besar']]);

        $page = Livewire::withQueryParams(['invoice' => $invoice->id])->test(CreateProject::class);

        $this->assertNull($page->get('fromInvoice'));
        $this->assertStringNotContainsString('Kontrak Rahasia', (string) $page->get('data.detail_project'));
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
