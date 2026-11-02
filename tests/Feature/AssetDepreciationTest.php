<?php

namespace Tests\Feature;

use App\Filament\Resources\AssetResource\Pages\ListAssets;
use App\Filament\Resources\AssetResource\Widgets\AssetDepreciationStats;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetDepreciation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Penyusutan aset menurut kelompok harta pajak. Angka-angkanya bisa dicek
 * manual: laptop Rp 12 jt, Kelompok 1 (4 tahun), dibeli Juli 2026.
 */
class AssetDepreciationTest extends TestCase
{
    use DatabaseTransactions;

    private AssetDepreciation $depreciation;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->depreciation = app(AssetDepreciation::class);
    }

    public function test_garis_lurus_mulai_bulan_pembelian(): void
    {
        $laptop = $this->asset(12_000_000, '2026-07-15', 'kelompok_1');

        $this->assertEquals(
            [[2026, 1_500_000], [2027, 3_000_000], [2028, 3_000_000], [2029, 3_000_000], [2030, 1_500_000]],
            array_map(fn ($row) => [$row['year'], $row['depreciation']], $this->depreciation->yearly($laptop))
        );

        $akhir2026 = $this->depreciation->at($laptop, Carbon::create(2026, 12, 1));
        $this->assertEquals([250_000, 1_500_000, 10_500_000, 'aktif'], [$akhir2026['monthly'], $akhir2026['accumulated'], $akhir2026['book'], $akhir2026['status']]);

        $habis = $this->depreciation->at($laptop, Carbon::create(2030, 6, 1));
        $this->assertEquals([0, 'habis', 0], [$habis['book'], $habis['status'], $habis['remaining_months']]);
    }

    public function test_saldo_menurun_sisa_disusutkan_di_akhir_umur(): void
    {
        $laptop = $this->asset(12_000_000, '2026-07-15', 'kelompok_1', 'saldo_menurun');

        // 50% per tahun dari nilai buku; tahun pertama 6 bulan; sisa di tahun terakhir.
        $this->assertEquals(
            [3_000_000, 4_500_000, 2_250_000, 1_125_000, 1_125_000],
            array_column($this->depreciation->yearly($laptop), 'depreciation')
        );
        $this->assertEquals(0, $this->depreciation->at($laptop, Carbon::create(2030, 12, 1))['book']);
    }

    public function test_bangunan_selalu_garis_lurus(): void
    {
        $gedung = $this->asset(200_000_000, '2026-01-10', 'bangunan_permanen', 'saldo_menurun');

        $this->assertEquals(10_000_000, $this->depreciation->yearly($gedung)[0]['depreciation']);
        $this->assertCount(20, $this->depreciation->yearly($gedung));
    }

    public function test_aset_dilepas_berhenti_disusutkan_dan_tidak_dihitung(): void
    {
        $laptop = $this->asset(12_000_000, '2026-07-15', 'kelompok_1', dilepas: '2027-03-10');

        $months = $this->depreciation->months($laptop);
        $this->assertSame('2027-03', array_key_last($months));
        $this->assertEquals(2_250_000, array_sum($months), '9 bulan × 250 rb.');

        $this->assertSame('dilepas', $this->depreciation->at($laptop, Carbon::create(2027, 4, 1))['status']);
        $this->assertSame(0, $this->depreciation->summary(collect([$laptop]), Carbon::create(2027, 4, 1))['count']);
    }

    public function test_tanpa_kelompok_dan_tanah(): void
    {
        $monitor = $this->asset(3_000_000, '2026-01-05', null);
        $tanah   = $this->asset(500_000_000, '2020-01-05', 'tidak_disusutkan');

        $this->assertSame('tanpa_kelompok', $this->depreciation->at($monitor)['status']);
        $this->assertEquals(3_000_000, $this->depreciation->at($monitor)['book']);
        $this->assertSame('tidak_disusutkan', $this->depreciation->at($tanah)['status']);
        $this->assertEquals(500_000_000, $this->depreciation->at($tanah)['book']);

        $sum = $this->depreciation->summary(collect([$monitor, $tanah]));
        $this->assertSame([2, 1], [$sum['count'], $sum['without_group']]);
        $this->assertEquals(503_000_000, $sum['book']);
    }

    public function test_excel_daftar_penyusutan_satu_tahun(): void
    {
        $laptop = $this->asset(12_000_000, '2026-07-15', 'kelompok_1', nama: 'Laptop Uji Penyusutan ' . Str::random(4));

        $path  = $this->depreciation->excel(2027);
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $row = collect($sheet->toArray(null, true, false))->first(fn ($row) => $row[1] === $laptop->nama_barang);

        $this->assertNotNull($row);
        // Harga, akumulasi s.d. 2026, penyusutan 2027, akumulasi s.d. 2027, nilai buku akhir 2027.
        $this->assertEquals([12_000_000, 1_500_000, 3_000_000, 4_500_000, 7_500_000], array_slice($row, 6, 5));
    }

    public function test_ringkasan_dan_unduhan_hanya_untuk_finance(): void
    {
        $this->actingAs($staff = $this->user('Staff'));
        $this->asset(12_000_000, '2026-07-15', 'kelompok_1', owner: $staff);

        $this->assertFalse(AssetDepreciationStats::canView());
        Livewire::test(ListAssets::class)->assertActionHidden('unduhPenyusutan')->assertSee('Nilai buku');
        $this->get(route('asset.depreciation', 2026))->assertForbidden();

        // Izin membuka menu Asset diatur lewat Shield (Roles); di sini diberikan langsung.
        $finance = $this->user('Finance');
        $finance->givePermissionTo('view_any_asset');
        $this->actingAs($finance);
        $this->assertTrue(AssetDepreciationStats::canView());
        Livewire::test(ListAssets::class)->assertActionVisible('unduhPenyusutan');
        $this->get(route('asset.depreciation', 2026))->assertOk()->assertDownload('penyusutan-aset-2026.xlsx');
    }

    public function test_kelompok_harta_diisi_lewat_form_dan_aset_bisa_dicari(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin);
        $asset = $this->asset(12_000_000, '2026-07-15', null, owner: $admin);

        // Dulu gagal: kolom "ID Barang" (uuid) dipakai form tapi tidak pernah dibuat.
        Livewire::test(\App\Filament\Resources\AssetResource\Pages\EditAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm(['uuid' => 'AST-UJI-01', 'kelompok_harta' => 'kelompok_1', 'metode_penyusutan' => 'saldo_menurun'])
            ->call('save')
            ->assertHasNoFormErrors();

        $asset->refresh();
        $this->assertSame(['AST-UJI-01', 'kelompok_1', 'saldo_menurun'], [$asset->uuid, $asset->kelompok_harta, $asset->metode_penyusutan]);

        Livewire::test(ListAssets::class)->searchTable('AST-UJI-01')->assertCanSeeTableRecords([$asset]);
    }

    // =================================================================

    private function asset(float $harga, string $tanggal, ?string $kelompok, string $metode = 'garis_lurus', ?string $dilepas = null, ?string $nama = null, ?User $owner = null): Asset
    {
        return Asset::forceCreate([
            'nama_barang'         => $nama ?? 'Aset uji ' . Str::random(4),
            'tanggal_pembelian'   => $tanggal,
            'pembelian_dari'      => 'Langsung',
            'kondisi'             => 'Baru',
            'penanggung_jawab_id' => $owner?->id ?? User::value('id'),
            'harga_barang'        => $harga,
            'kelompok_harta'      => $kelompok,
            'metode_penyusutan'   => $metode,
            'tanggal_dilepas'     => $dilepas,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'aset_' . Str::lower(Str::random(8)),
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
