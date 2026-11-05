<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nomor proforma tidak melompat: nomor proforma yang dihapus dipakai lagi.
 * Invoice #SC dan proforma yang sudah menjadi invoice tetap memegang nomornya.
 * Dijalankan di bulan Maret 2031 supaya seri nomornya masih kosong.
 */
class ProformaNumberTest extends TestCase
{
    use DatabaseTransactions;

    private const PI = '#PI/0133103/';

    private const SC = '#SC/0133103/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2031-03-10 10:00:00');

        $user = User::forceCreate([
            'username'     => 'pin_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Nomor',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_nomor_proforma_yang_dihapus_dipakai_lagi(): void
    {
        [, $second, $third] = [$this->proforma(), $this->proforma(), $this->proforma()];
        $this->assertSame(self::PI . '00003', $third->uuid);

        $third->delete();
        $this->assertSame(self::PI . '00003' . Invoice::TANDA_BATAL . $third->id, $third->fresh()->uuid);
        $this->assertSame(self::PI . '00003', $this->proforma()->uuid);
        $this->assertSame(self::PI . '00004', $this->proforma()->uuid);

        // Nomor di tengah pun diisi lagi.
        $second->delete();
        $this->assertSame(self::PI . '00002', $this->proforma()->uuid);
        $this->assertSame(self::PI . '00005', $this->proforma()->uuid);
    }

    public function test_proforma_yang_dihapus_dipulihkan_dengan_nomor_lamanya(): void
    {
        $this->proforma();
        $second = $this->proforma();

        $second->delete();
        $second->restore();

        $this->assertSame(self::PI . '00002', $second->fresh()->uuid);
    }

    public function test_nomor_lama_sudah_dipakai_lagi_saat_dipulihkan_dapat_nomor_baru(): void
    {
        $this->proforma();
        $second = $this->proforma();

        $second->delete();
        $this->assertSame(self::PI . '00002', $this->proforma()->uuid);

        $second->restore();
        $this->assertSame(self::PI . '00003', $second->fresh()->uuid);
    }

    public function test_proforma_yang_jadi_invoice_tetap_memegang_nomornya(): void
    {
        $this->proforma();
        $second = $this->proforma();

        Livewire::test(ListInvoices::class)
            ->callTableAction('convert_to_invoice', $second, data: ['faktur' => null, 'delivery_note_number' => null])
            ->assertHasNoTableActionErrors();

        $second = $second->fresh();
        $this->assertSame('sale', $second->type);
        $this->assertSame(self::PI . '00002', $second->proforma_uuid);

        // Dulu nomor PI dari proforma yang sudah jadi invoice diberikan lagi.
        $this->assertSame(self::PI . '00003', $this->proforma()->uuid);
    }

    public function test_nomor_invoice_penjualan_tidak_dipakai_lagi(): void
    {
        $this->invoice('sale');
        $second = $this->invoice('sale');

        $second->delete();

        $this->assertSame(self::SC . '00002', $second->fresh()->uuid);
        $this->assertSame(self::SC . '00003', $this->invoice('sale')->uuid);
    }

    private function proforma(): Invoice
    {
        return $this->invoice('estimate');
    }

    private function invoice(string $type): Invoice
    {
        return Invoice::create([
            'uuid'            => Invoice::generateUuid($type),
            'user_id'         => auth()->id(),
            'type'            => $type,
            'type_payment'    => 'full_payment',
            'status'          => 'draft',
            'nama_perusahaan' => 'PT Nomor Rapi',
            'date'            => now(),
            'due_date'        => now()->addWeek(),
            'subtotal'        => 1_000_000,
            'grand_total'     => 1_000_000,
            'paid'            => 0,
        ]);
    }
}
