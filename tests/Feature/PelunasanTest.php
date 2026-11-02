<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\FinanceOverview;
use App\Services\InvoicePayment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Invoice pelunasan menagih harga penuh dikurangi DP, sama dengan "Sisa
 * Pembayaran" di PDF. Contoh: pekerjaan Rp 10 jt, DP 30% (Rp 3 jt).
 */
class PelunasanTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = $this->user('super_admin');
        $this->actingAs($this->user);
    }

    public function test_pelunasan_yang_dibayar_sesuai_pdf_jadi_lunas(): void
    {
        $dp    = $this->invoice(['type_payment' => 'dp_30', 'paid' => 3_000_000, 'status' => 'paid']);
        $final = $this->invoice(['type_payment' => 'final_payment', 'parent_invoice_id' => $dp->id]);

        $this->assertEquals(7_000_000, $final->amountDue());
        $this->assertEquals(7_000_000, InvoicePayment::remaining($final));
        $this->assertEquals(7_000_000, FinanceOverview::invoiceUnpaid($final));

        $account = PaymentAccount::create([
            'name' => 'Rekening Tes ' . Str::upper(Str::random(4)), 'bank_name' => 'BCA',
            'account_number' => '123', 'account_holder' => 'Tes', 'initial_balance' => 0, 'is_active' => true,
        ]);
        $paid = app(InvoicePayment::class)->pay($final, 7_000_000, $account, $this->user);

        // Dulu tetap "partial" dengan piutang Rp 3 jt (sebesar DP).
        $this->assertSame('paid', $paid->status);
        $this->assertEquals(0, FinanceOverview::invoiceUnpaid($paid));
    }

    public function test_pelunasan_lama_tanpa_tautan_memakai_catatan_dp(): void
    {
        $final = $this->invoice(['type_payment' => 'final_payment', 'notes' => "DP: 30% - Rp 3.000.000\n"]);

        $this->assertEquals(7_000_000, $final->amountDue());
    }

    public function test_pelunasan_tanpa_informasi_dp_tetap_ditagih_penuh(): void
    {
        $final = $this->invoice(['type_payment' => 'final_payment']);

        $this->assertNull($final->settledDp());
        $this->assertEquals(10_000_000, $final->amountDue());
    }

    public function test_tombol_buat_pelunasan_membuat_invoice_yang_tertaut(): void
    {
        $dp = $this->invoice(['type_payment' => 'dp_30', 'paid' => 3_000_000, 'status' => 'paid']);
        $dp->invoicesItems()->create(['item' => 'Booth 3x3', 'qty' => 1, 'price' => 10_000_000, 'original_price' => 10_000_000, 'discount' => 0, 'vat' => 0, 'total' => 10_000_000]);

        Livewire::test(ListInvoices::class)
            ->assertTableActionVisible('pelunasan', $dp)
            ->callTableAction('pelunasan', $dp);

        $final = Invoice::where('parent_invoice_id', $dp->id)->sole();
        $this->assertSame(['final_payment', 'draft'], [$final->type_payment, $final->status]);
        $this->assertSame($dp->nama_perusahaan, $final->nama_perusahaan);
        $this->assertSame(['Booth 3x3'], $final->invoicesItems->pluck('item')->all());
        $this->assertEquals(7_000_000, $final->amountDue());
        $this->assertNotSame($dp->uuid, $final->uuid);

        // Sudah ada pelunasannya: tombolnya tidak muncul lagi.
        Livewire::test(ListInvoices::class)->assertTableActionHidden('pelunasan', $dp->fresh());
    }

    public function test_tombol_pelunasan_hanya_untuk_invoice_dp(): void
    {
        $full = $this->invoice(['type_payment' => 'full_payment']);

        Livewire::test(ListInvoices::class)->assertTableActionHidden('pelunasan', $full);
    }

    public function test_invoice_dp_dipilih_di_form_pelunasan(): void
    {
        $dp    = $this->invoice(['type_payment' => 'dp_30']);
        $other = $this->invoice(['type_payment' => 'dp_50', 'nama_perusahaan' => 'PT Klien Lain ' . Str::random(4)]);
        $final = $this->invoice(['type_payment' => 'final_payment']);
        $final->invoicesItems()->create(['item' => 'Booth 3x3', 'qty' => 1, 'price' => 10_000_000, 'original_price' => 10_000_000, 'discount' => 0, 'vat' => 0, 'total' => 10_000_000]);

        $options = \App\Filament\Resources\InvoiceResource::dpInvoiceOptions(null, $final->nama_perusahaan, $final->id);
        $this->assertArrayHasKey($dp->id, $options, 'Invoice DP client yang sama ditawarkan.');
        $this->assertArrayNotHasKey($other->id, $options, 'Invoice DP client lain tidak.');
        $this->assertStringContainsString('DP 30% · Rp 3.000.000 dari Rp 10.000.000', $options[$dp->id]);

        Livewire::test(EditInvoice::class, ['record' => $final->getRouteKey()])
            ->assertFormFieldIsVisible('parent_invoice_id')
            ->fillForm(['parent_invoice_id' => $dp->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($dp->id, $final->fresh()->parent_invoice_id);
        $this->assertEquals(7_000_000, $final->fresh()->amountDue());

        // Diganti jadi full payment: tautannya dilepas.
        Livewire::test(EditInvoice::class, ['record' => $final->getRouteKey()])
            ->fillForm(['type_payment' => 'full_payment'])
            ->assertFormFieldIsHidden('parent_invoice_id')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($final->fresh()->parent_invoice_id);
    }

    // =================================================================

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => $this->user->id,
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'draft',
            'nama_perusahaan' => 'PT Pelunasan Tes',
            'nama_pic'        => 'Budi',
            'date'            => today(),
            'due_date'        => today()->addDays(14),
            'subtotal'        => 10_000_000,
            'total'           => 10_000_000,
            'grand_total'     => 10_000_000,
            'paid'            => 0,
            'currency_id'     => 66,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'lunas_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => $role,
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
