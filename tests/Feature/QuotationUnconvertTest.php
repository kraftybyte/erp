<?php

namespace Tests\Feature;

use App\Exceptions\QuotationUnconvertException;
use App\Filament\Resources\QuotationResource\Pages\ListQuotations;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\QuotationUnconvert;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batalkan konversi quotation: proforma yang belum dipakai dihapus dan
 * quotation bisa dikonversi ulang; proforma yang sudah jadi invoice atau
 * sudah dibayar tidak ikut terhapus.
 */
class QuotationUnconvertTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::forceCreate([
            'username'     => 'unc_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Unconvert',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_konversi_dibatalkan_lalu_bisa_dikonversi_ulang(): void
    {
        $quotation = $this->convertedQuotation();
        $proforma  = $this->proformaOf($quotation);

        Livewire::test(ListQuotations::class)
            ->assertTableActionHidden('ConvertToEstimate', $quotation)
            ->callTableAction('unconvert', $quotation, data: ['status' => 'Approved'])
            ->assertHasNoTableActionErrors();

        $quotation = $quotation->fresh();
        $this->assertSame('Approved', $quotation->status);
        $this->assertSoftDeleted('invoices', ['id' => $proforma->id]);

        Livewire::test(ListQuotations::class)
            ->assertTableActionHidden('unconvert', $quotation)
            ->callTableAction('ConvertToEstimate', $quotation);

        $this->assertSame('Converted', $quotation->fresh()->status);
        $this->assertNotSame($proforma->id, $this->proformaOf($quotation)->id);
    }

    public function test_proforma_yang_sudah_jadi_invoice_tidak_dihapus(): void
    {
        $quotation = $this->convertedQuotation();
        $proforma  = $this->proformaOf($quotation);
        $proforma->update(['type' => 'sale']);

        $this->assertBlocked($quotation, 'sudah dikonversi menjadi invoice');
        $this->assertNotSoftDeleted('invoices', ['id' => $proforma->id]);
    }

    public function test_proforma_yang_sudah_dibayar_tidak_dihapus(): void
    {
        $quotation = $this->convertedQuotation();
        $proforma  = $this->proformaOf($quotation);
        $proforma->forceFill(['paid' => 500_000])->saveQuietly();

        $this->assertBlocked($quotation, 'sudah menerima pembayaran');

        // Modal menyebut alasannya; lewat tombol pun quotation dan proforma tetap utuh.
        Livewire::test(ListQuotations::class)
            ->mountTableAction('unconvert', $quotation)
            ->assertSee('Tidak bisa dibatalkan: ' . $proforma->uuid . ' sudah menerima pembayaran.')
            ->callMountedTableAction();

        $this->assertSame('Converted', $quotation->fresh()->status);
        $this->assertNotSoftDeleted('invoices', ['id' => $proforma->id]);
    }

    public function test_proforma_dengan_invoice_lanjutan_tidak_dihapus(): void
    {
        $quotation = $this->convertedQuotation();
        $proforma  = $this->proformaOf($quotation);
        $proforma->replicate()->fill(['uuid' => '#TES/' . Str::upper(Str::random(8)), 'for_type' => null, 'for_id' => null, 'parent_invoice_id' => $proforma->id])->save();

        $this->assertBlocked($quotation, 'sudah punya invoice lanjutan');
    }

    public function test_proforma_yang_sudah_dihapus_manual_tetap_bisa_dibatalkan(): void
    {
        $quotation = $this->convertedQuotation();
        $this->proformaOf($quotation)->delete();

        $this->assertCount(0, QuotationUnconvert::run($quotation, 'Pending'));
        $this->assertSame('Pending', $quotation->fresh()->status);
    }

    private function assertBlocked(Quotation $quotation, string $reason): void
    {
        try {
            QuotationUnconvert::run($quotation, 'Approved');
            $this->fail('Pembatalan konversi seharusnya ditolak.');
        } catch (QuotationUnconvertException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }

        $this->assertSame('Converted', $quotation->fresh()->status);
    }

    private function convertedQuotation(): Quotation
    {
        $quotation = Quotation::create([
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'nama_perusahaan' => 'PT Batal Konversi ' . Str::upper(Str::random(5)),
            'nama_pic'        => 'Bu Rahma',
            'status'          => 'Approved',
            'date'            => now(),
            'due_date'        => now()->addWeek(),
            'subtotal'        => 1_000_000,
            'total_discount'  => 0,
            'total_vat'       => 0,
            'grand_total'     => 1_000_000,
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'item'         => 'Signage akrilik',
            'qty'          => 1,
            'price'        => 1_000_000,
            'discount'     => 0,
            'total'        => 1_000_000,
        ]);

        Livewire::test(ListQuotations::class)->callTableAction('ConvertToEstimate', $quotation);
        $this->assertSame('Converted', $quotation->fresh()->status);

        return $quotation->fresh();
    }

    private function proformaOf(Quotation $quotation): Invoice
    {
        return Invoice::where('for_type', 'quotation')->where('for_id', $quotation->id)->latest('id')->firstOrFail();
    }
}
