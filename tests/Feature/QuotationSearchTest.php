<?php

namespace Tests\Feature;

use App\Filament\Resources\QuotationResource\Pages\ListQuotations;
use App\Models\Quotation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kotak pencarian tabel Quotation: nomor, klien/PIC, dan total.
 * Nominal dibuat ganjil agar tidak bertabrakan dengan data yang sudah ada.
 */
class QuotationSearchTest extends TestCase
{
    use DatabaseTransactions;

    private Quotation $signage;

    private Quotation $booth;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::forceCreate([
            'username'     => 'quo_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Quotation',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->signage = $this->quotation(['grand_total' => 876_543_217, 'nama_perusahaan' => 'PT Cari Signage ' . Str::upper(Str::random(5)), 'nama_pic' => 'Bu Rahma']);
        $this->booth   = $this->quotation(['grand_total' => 123_456_781, 'nama_perusahaan' => 'PT Cari Booth ' . Str::upper(Str::random(5)), 'nama_pic' => 'Pak Dimas']);
    }

    public function test_cari_dari_total(): void
    {
        foreach (['876543217', '876.543.217', 'Rp 876.543.217,00', '543.217'] as $search) {
            Livewire::test(ListQuotations::class)
                ->searchTable($search)
                ->assertCanSeeTableRecords([$this->signage])
                ->assertCanNotSeeTableRecords([$this->booth]);
        }
    }

    public function test_cari_dari_nomor_klien_dan_pic(): void
    {
        foreach ([$this->signage->uuid, $this->signage->nama_perusahaan, $this->signage->nama_pic . ' Signage'] as $search) {
            Livewire::test(ListQuotations::class)
                ->searchTable($search)
                ->assertCanSeeTableRecords([$this->signage])
                ->assertCanNotSeeTableRecords([$this->booth]);
        }
    }

    private function quotation(array $attributes): Quotation
    {
        return Quotation::create($attributes + [
            'uuid'     => '#TES/' . Str::upper(Str::random(8)),
            'status'   => 'Pending',
            'date'     => now(),
            'due_date' => now()->addWeek(),
        ]);
    }
}
