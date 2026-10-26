<?php

namespace Tests\Feature;

use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\DealResource\Pages\CreateDeal;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CompanyDirectory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Perusahaan & PIC: satu perusahaan bisa punya PIC berbeda per order.
 * Nama perusahaan diberi akhiran acak agar tidak bertabrakan dengan data yang sudah ada.
 */
class CompanyDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = Str::upper(Str::random(6));

        $user = User::forceCreate([
            'username'     => 'cmp_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Perusahaan',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_migrasi_hanya_menambah_dan_tidak_mengubah_kolom_lama(): void
    {
        // Client lama, dimasukkan langsung seperti data sebelum fitur ini ada.
        $ids = [
            $this->legacyDeal("PT Organik {$this->tag}", 'Fahmi', '0811'),
            $this->legacyDeal("pt  organik {$this->tag} ", 'fahmi', '-'),
            $this->legacyDeal("PT. Organik {$this->tag}", 'Bellamy', '0822'),
            $this->legacyDeal("Budi {$this->tag}", '-', '0833'),
        ];

        $before = $this->legacyColumns($ids);

        (require database_path('migrations/2026_10_26_130100_link_deals_to_companies.php'))->up();

        $this->assertEquals($before, $this->legacyColumns($ids), 'Kolom lama & updated_at tidak berubah.');

        $deals = DB::table('deals')->whereIn('id', $ids)->get()->keyBy('id');

        // Beda huruf besar/kecil & spasi saja: satu perusahaan, satu PIC.
        $this->assertSame($deals[$ids[0]]->company_id, $deals[$ids[1]]->company_id);
        $this->assertSame($deals[$ids[0]]->contact_id, $deals[$ids[1]]->contact_id);
        $this->assertSame('0811', CompanyContact::find($deals[$ids[0]]->contact_id)->telephone);

        // "PT." vs "PT": tidak digabung otomatis, tapi ditandai dugaan duplikat.
        $this->assertNotSame($deals[$ids[0]]->company_id, $deals[$ids[2]]->company_id);
        $suspects = app(CompanyDirectory::class)->suspectedDuplicateIds();
        $this->assertContains($deals[$ids[0]]->company_id, $suspects);
        $this->assertContains($deals[$ids[2]]->company_id, $suspects);

        // Klien pribadi: perusahaan ada, PIC tidak.
        $this->assertNotNull($deals[$ids[3]]->company_id);
        $this->assertNull($deals[$ids[3]]->contact_id);
    }

    public function test_client_baru_dari_teks_tertaut_dengan_pic_berbeda(): void
    {
        // Seperti konversi Leads / import Excel: hanya teks.
        $first  = Deal::create($this->dealText("PT Teks {$this->tag}", 'Rina', '0812'));
        $second = Deal::create($this->dealText("PT Teks {$this->tag}", 'Doni', '0813'));

        $this->assertSame($first->company_id, $second->company_id);
        $this->assertNotSame($first->contact_id, $second->contact_id);
        $this->assertEqualsCanonicalizing(['Rina', 'Doni'], $first->company->contacts->pluck('name')->all());
        $this->assertSame('0813', $second->contact->telephone);
    }

    public function test_gabung_perusahaan_memindahkan_pic_dan_order(): void
    {
        $directory = app(CompanyDirectory::class);

        $keep  = Deal::create($this->dealText("PT Gabung {$this->tag}", 'Fahmi', '0811'));
        $dupe  = Deal::create($this->dealText("PT. Gabung {$this->tag}", 'fahmi', '0811'));
        $other = Deal::create($this->dealText("PT. Gabung {$this->tag}", 'Bellamy', '0822'));
        $invoice = $this->invoiceFor($dupe);

        $directory->merge($dupe->company, $keep->company);

        foreach ([$dupe, $other] as $deal) {
            $deal->refresh();
            $this->assertSame($keep->company_id, $deal->company_id);
            $this->assertSame("PT Gabung {$this->tag}", $deal->nama_perusahaan);
        }

        $this->assertSame($keep->contact_id, $dupe->contact_id, 'PIC bernama sama dijadikan satu.');
        $this->assertCount(2, $keep->company->contacts()->get());
        $this->assertSame("PT. Gabung {$this->tag}", $invoice->fresh()->nama_perusahaan, 'Invoice yang sudah terbit tidak berubah.');

        // Ketikan nama lama berikutnya masuk ke perusahaan tujuan.
        $later = Deal::create($this->dealText("PT. Gabung {$this->tag}", 'Fahmi', '0811'));
        $this->assertSame($keep->company_id, $later->company_id);
        $this->assertSame($keep->contact_id, $later->contact_id);
    }

    public function test_ganti_nama_perusahaan_mengikuti_di_client_tapi_tidak_di_invoice(): void
    {
        $deal    = Deal::create($this->dealText("PT Lama {$this->tag}", 'Rina', '0812'));
        $invoice = $this->invoiceFor($deal);

        $deal->company->update(['name' => "PT Baru {$this->tag}"]);
        $deal->contact->update(['name' => 'Rina Wati']);

        $this->assertSame("PT Baru {$this->tag}", $deal->fresh()->nama_perusahaan);
        $this->assertSame('Rina Wati', $deal->fresh()->nama_pic);
        $this->assertSame("PT Lama {$this->tag}", $invoice->fresh()->nama_perusahaan);
    }

    public function test_form_client_memilih_perusahaan_dan_pic(): void
    {
        $company = Company::create(['name' => "PT Form {$this->tag}"]);
        $rina    = $company->contacts()->create(['name' => 'Rina', 'telephone' => '0812']);
        $stranger = Company::create(['name' => "PT Lain {$this->tag}"])->contacts()->create(['name' => 'Asing']);

        Livewire::test(CreateDeal::class)
            ->fillForm(['company_id' => $company->id, 'contact_id' => $stranger->id, 'telephone' => '0812', 'source' => 'Referral', 'status' => 'Negosiasi'])
            ->call('create')
            ->assertHasFormErrors(['contact_id']);

        Livewire::test(CreateDeal::class)
            ->fillForm(['company_id' => $company->id])
            ->fillForm(['contact_id' => $rina->id])
            ->assertFormSet(['telephone' => '0812'])
            ->fillForm(['source' => 'Referral', 'status' => 'Negosiasi'])
            ->call('create')
            ->assertHasNoFormErrors();

        $deal = Deal::where('company_id', $company->id)->latest('id')->first();
        $this->assertSame($rina->id, $deal->contact_id);
        $this->assertSame("PT Form {$this->tag}", $deal->nama_perusahaan);
        $this->assertSame('Rina', $deal->nama_pic);
    }

    public function test_menu_perusahaan_menampilkan_dugaan_duplikat_dan_bisa_menggabungkan(): void
    {
        $keep = Company::create(['name' => "CV Mirip {$this->tag}"]);
        $dupe = Company::create(['name' => "CV. MIRIP {$this->tag}"]);
        $solo = Company::create(['name' => "PT Sendiri {$this->tag}"]);

        Livewire::test(ListCompanies::class)
            ->filterTable('duplikat', true)
            ->assertCanSeeTableRecords([$keep, $dupe])
            ->assertCanNotSeeTableRecords([$solo])
            ->callTableAction('merge', $dupe, data: ['into' => $keep->id])
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted($dupe);
        $this->assertSame($keep->id, $dupe->fresh()->merged_into_id);
    }

    private function legacyDeal(string $company, string $pic, string $phone): int
    {
        return DB::table('deals')->insertGetId([
            'nama_perusahaan' => $company,
            'nama_pic'        => $pic,
            'telephone'       => $phone,
            'email'           => '-',
            'alamat'          => '-',
            'source'          => 'Referral',
            'status'          => 'Negosiasi',
            'created_at'      => '2025-01-01 10:00:00',
            'updated_at'      => '2025-01-01 10:00:00',
        ]);
    }

    private function legacyColumns(array $ids): array
    {
        return DB::table('deals')->whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'nama_perusahaan', 'nama_pic', 'telephone', 'email', 'alamat', 'NPWP', 'source', 'status', 'created_at', 'updated_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function dealText(string $company, string $pic, string $phone): array
    {
        return [
            'nama_perusahaan' => $company,
            'nama_pic'        => $pic,
            'telephone'       => $phone,
            'email'           => '-',
            'alamat'          => '-',
            'source'          => 'Referral',
            'status'          => 'Negosiasi',
        ];
    }

    private function invoiceFor(Deal $deal): Invoice
    {
        return Invoice::create([
            'uuid'            => 'TES/' . Str::upper(Str::random(8)),
            'user_id'         => auth()->id(),
            'deal_id'         => $deal->id,
            'type'            => 'sale',
            'nama_perusahaan' => $deal->nama_perusahaan,
            'nama_pic'        => $deal->nama_pic,
            'date'            => now(),
            'due_date'        => now()->addWeek(),
            'paid'            => 0,
        ]);
    }
}
