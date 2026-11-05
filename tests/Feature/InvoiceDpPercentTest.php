<?php

namespace Tests\Feature;

use App\Filament\Resources\DealResource\Pages\EditDeal;
use App\Filament\Resources\DealResource\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Persen DP invoice diketik langsung (1–99%), tidak lagi dipilih dari
 * kelipatan 10. Tersimpan tetap sebagai type_payment "dp_<persen>".
 */
class InvoiceDpPercentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user());
    }

    public function test_invoice_baru_dengan_dp_25_persen(): void
    {
        $client = 'PT DP Bebas ' . Str::upper(Str::random(5));

        $this->createForm()
            ->fillForm(['nama_perusahaan' => $client, 'type_payment' => 'dp', 'dp_persen' => 25])
            ->assertSee('Ditagih Rp 1.000.000 dari Grand Total Rp 4.000.000.')
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::where('nama_perusahaan', $client)->sole();
        $this->assertSame('dp_25', $invoice->type_payment);
        $this->assertSame(25, $invoice->dpPercentage());
        $this->assertEquals(1_000_000, $invoice->amountDue(), 'DP 25% dari Rp 4 jt.');
    }

    public function test_persen_dp_harus_angka_bulat_1_sampai_99(): void
    {
        foreach ([[null, 'required'], [0, 'min'], [100, 'max'], ['12.5', 'integer']] as [$persen, $aturan]) {
            $this->createForm()
                ->fillForm(['nama_perusahaan' => 'PT Salah', 'type_payment' => 'dp', 'dp_persen' => $persen])
                ->call('create')
                ->assertHasFormErrors(['dp_persen' => $aturan]);
        }

        $this->assertFalse(Invoice::where('nama_perusahaan', 'PT Salah')->exists());
    }

    public function test_kolom_persen_hanya_muncul_untuk_down_payment(): void
    {
        $client = 'PT Lunas ' . Str::upper(Str::random(5));

        $this->createForm()
            ->fillForm(['type_payment' => 'dp'])
            ->assertFormFieldIsVisible('dp_persen')
            ->fillForm(['nama_perusahaan' => $client, 'type_payment' => 'full_payment'])
            ->assertFormFieldIsHidden('dp_persen')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('full_payment', Invoice::where('nama_perusahaan', $client)->sole()->type_payment);
    }

    public function test_edit_invoice_dp_menampilkan_dan_mengubah_persennya(): void
    {
        $invoice = $this->invoice(['type_payment' => 'dp_30']);

        Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertFormSet(['type_payment' => 'dp', 'dp_persen' => 30])
            ->assertFormFieldIsEnabled('dp_persen')
            ->fillForm(['dp_persen' => 35])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('dp_35', $invoice->fresh()->type_payment);
        $this->assertEquals(1_400_000, $invoice->fresh()->amountDue());
    }

    public function test_dp_yang_sudah_dibayar_tidak_bisa_diubah_persennya(): void
    {
        $invoice = $this->invoice(['type_payment' => 'dp_30', 'paid' => 1_200_000, 'status' => 'paid']);

        Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertFormSet(['dp_persen' => 30])
            ->assertFormFieldIsDisabled('type_payment')
            ->assertFormFieldIsDisabled('dp_persen')
            ->set('data.dp_persen', 60)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('dp_30', $invoice->fresh()->type_payment, 'Nominal yang sudah dibayar tetap sesuai tagihannya.');
    }

    public function test_tab_invoice_di_deal_memakai_kolom_persen_yang_sama(): void
    {
        $deal = Deal::forceCreate(['nama_perusahaan' => 'PT Deal DP', 'nama_pic' => 'Rina', 'alamat' => 'Jl. Tes']);

        // Tab Invoice di Deal membuka form menu Invoice, dengan kolom persen yang sama.
        Livewire::test(InvoicesRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->assertTableActionHasUrl('create', \App\Filament\Resources\InvoiceResource::getUrl('create', ['deal' => $deal->id, 'type' => 'estimate']));

        Livewire::withQueryParams(['deal' => $deal->id])
            ->test(CreateInvoice::class)
            ->set('data.type_payment', 'dp')
            ->set('data.dp_persen', 150)
            ->call('create')
            ->assertHasFormErrors(['dp_persen' => 'max']);
    }

    private function createForm()
    {
        $component = Livewire::test(CreateInvoice::class);
        $key       = array_key_first($component->get('data.invoicesItems'));

        return $component
            ->set("data.invoicesItems.{$key}.item", 'Booth 3x3')
            ->set("data.invoicesItems.{$key}.qty", 1)
            ->set("data.invoicesItems.{$key}.vat_percentage", 0)
            ->set("data.invoicesItems.{$key}.price", 4_000_000);
    }

    private function invoice(array $attributes): Invoice
    {
        $invoice = Invoice::create($attributes + [
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'status'          => 'draft',
            'nama_perusahaan' => 'PT Edit DP',
            'nama_pic'        => 'Budi',
            'date'            => today(),
            'due_date'        => today()->addDays(14),
            'subtotal'        => 4_000_000,
            'total'           => 4_000_000,
            'grand_total'     => 4_000_000,
            'paid'            => 0,
            'currency_id'     => 66,
        ]);

        $invoice->invoicesItems()->create([
            'item'           => 'Booth 3x3',
            'qty'            => 1,
            'price'          => 4_000_000,
            'original_price' => 4_000_000,
            'discount'       => 0,
            'vat'            => 0,
            'total'          => 4_000_000,
        ]);

        return $invoice;
    }

    private function user(): User
    {
        $user = User::forceCreate([
            'username'     => 'dp_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'DP',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');

        return $user;
    }
}
