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
 * Kotak pencarian tabel Invoice menemukan invoice dari total maupun nominal DP-nya.
 * Nominal dibuat ganjil agar tidak bertabrakan dengan data yang sudah ada.
 */
class InvoiceSearchTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Invoice $dp;

    private Invoice $full;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'username'     => 'inv_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Invoice',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $this->user->assignRole('super_admin');

        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // DP 50% dari 987.654.317 = 493.827.158,5 → ditampilkan Rp 493.827.159.
        $this->dp   = $this->invoice(['grand_total' => 987_654_317, 'type_payment' => 'dp_50']);
        $this->full = $this->invoice(['grand_total' => 493_827_159, 'type_payment' => 'full_payment']);
    }

    public function test_cari_dari_total(): void
    {
        foreach (['987654317', '987.654.317', 'Rp987.654.317', 'Rp 987.654.317,00', '654.317'] as $search) {
            Livewire::test(ListInvoices::class)
                ->searchTable($search)
                ->assertCanSeeTableRecords([$this->dp])
                ->assertCanNotSeeTableRecords([$this->full]);
        }
    }

    public function test_cari_dari_nominal_dp_sama_dengan_yang_ditampilkan(): void
    {
        // Nominal DP invoice pertama sama dengan total invoice kedua: keduanya muncul.
        Livewire::test(ListInvoices::class)
            ->searchTable('Rp 493.827.159')
            ->assertCanSeeTableRecords([$this->dp, $this->full]);

        // Ditambah kata "dp": hanya invoice DP.
        Livewire::test(ListInvoices::class)
            ->searchTable('dp 493.827.159')
            ->assertCanSeeTableRecords([$this->dp])
            ->assertCanNotSeeTableRecords([$this->full]);
    }

    public function test_pencarian_teks_tetap_jalan(): void
    {
        Livewire::test(ListInvoices::class)
            ->searchTable($this->dp->nama_perusahaan)
            ->assertCanSeeTableRecords([$this->dp])
            ->assertCanNotSeeTableRecords([$this->full]);
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => 'TES/' . Str::upper(Str::random(8)),
            'user_id'         => $this->user->id,
            'type'            => 'sale',
            'nama_perusahaan' => 'PT Cari ' . Str::upper(Str::random(6)),
            'date'            => now(),
            'due_date'        => now()->addWeek(),
            'paid'            => 0,
        ]);
    }
}
