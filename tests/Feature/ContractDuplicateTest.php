<?php

namespace Tests\Feature;

use App\Filament\Resources\ContractResource;
use App\Filament\Resources\ContractResource\Pages\EditContract;
use App\Filament\Resources\ContractResource\Pages\ListContracts;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Deal;
use App\Models\User;
use App\Services\ContractDuplicator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Duplikat kontrak: seluruh isi dan barisnya disalin ke kontrak baru (nomor,
 * klien, tanggal bisa diganti), kontrak asal tidak berubah, izin Shield.
 */
class ContractDuplicateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user('super_admin'));
    }

    public function test_seluruh_isi_dan_baris_disalin(): void
    {
        $source = $this->contract();

        $copy = app(ContractDuplicator::class)->duplicate($source, [
            'contract_number'  => '099/SPK/1226',
            'deal_id'          => $source->deal_id,
            'client_name_full' => 'PT Klien Baru',
            'effective_date'   => '2026-12-01',
        ]);

        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame(['099/SPK/1226', 'PT Klien Baru', '2026-12-01', '2027-11-30'],
            [$copy->contract_number, $copy->client_name_full, $copy->effective_date->toDateString(), $copy->expiration_date->toDateString()],
            'Durasi tetap: 1 Jan–31 Des menjadi 1 Des–30 Nov.');

        foreach (['document_title', 'articles', 'closing_text', 'header_image', 'agency_signatory_name', 'client_address', 'client_signatory_name',
            'table_a_title', 'table_a_columns', 'lampiran_description', 'has_third_party', 'payment_type', 'total_value', 'deal_id'] as $field) {
            $this->assertEquals($source->{$field}, $copy->{$field}, "{$field} ikut tersalin.");
        }

        $category = $copy->sowCategories()->sole();
        $this->assertSame(['Social Media', ['Desain 20 feed', 'Copywriting']], [$category->item, $category->children->pluck('item')->all()],
            'Sub-item menempel ke kategori salinan, bukan kategori asal.');
        $this->assertSame([['Termin 1', 'Rp 5 jt / bulan']], $copy->feeItems->map(fn ($i) => [$i->item, $i->estimate])->all());
        $this->assertSame(['Meta Ads'], $copy->thirdPartyItems->pluck('item')->all());

        $source->refresh();
        $this->assertSame('PT Klien Lama', $source->client_name_full, 'Kontrak asal tidak berubah.');
        $this->assertSame(5, ContractItem::where('contract_id', $source->id)->count());
        $this->assertSame(5, ContractItem::where('contract_id', $copy->id)->count());
    }

    public function test_klien_dari_deal_lain_atau_dikosongkan(): void
    {
        $source = $this->contract();
        $other  = Deal::forceCreate(['nama_perusahaan' => 'PT Deal Baru ' . Str::random(4), 'nama_pic' => 'Bu Sari', 'alamat' => 'Jl. Melati 9', 'telephone' => '0812999', 'email' => 'sari@example.test']);

        $fromDeal = app(ContractDuplicator::class)->duplicate($source, ['deal_id' => $other->id]);
        $this->assertSame([$other->id, $other->nama_perusahaan, 'Jl. Melati 9', '0812999', 'sari@example.test', 'Bu Sari', null],
            [$fromDeal->deal_id, $fromDeal->client_name_full, $fromDeal->client_address, $fromDeal->client_phone, $fromDeal->client_email, $fromDeal->client_signatory_name, $fromDeal->client_signatory_title]);

        $cleared = app(ContractDuplicator::class)->duplicate($source, ['deal_id' => $source->deal_id, 'client_name_full' => 'PT Belum Tahu', 'copy_client_details' => false]);
        $this->assertSame(['PT Belum Tahu', null, null, null, null], [$cleared->client_name_full, $cleared->client_address, $cleared->client_phone, $cleared->client_email, $cleared->client_signatory_name]);
        $this->assertNotSame($cleared->contract_number, $fromDeal->contract_number, 'Tanpa nomor: nomor baru dibuat otomatis.');
    }

    public function test_tombol_duplikat_membuka_edit_kontrak_baru(): void
    {
        $source = $this->contract();

        Livewire::test(ListContracts::class)
            ->callTableAction('duplicate', $source, data: ['contract_number' => '098/SPK/1126', 'client_name_full' => 'PT Dari Tabel'])
            ->assertHasNoTableActionErrors();

        $copy = Contract::where('contract_number', '098/SPK/1126')->sole();
        $this->assertSame('PT Dari Tabel', $copy->client_name_full);

        Livewire::test(EditContract::class, ['record' => $source->getRouteKey()])
            ->callAction('duplicate', data: ['contract_number' => '097/SPK/1126', 'client_name_full' => 'PT Dari Edit'])
            ->assertRedirect(ContractResource::getUrl('edit', ['record' => Contract::where('contract_number', '097/SPK/1126')->sole()]));
    }

    public function test_tanpa_izin_replicate_tombol_tidak_ada(): void
    {
        $source = $this->contract();

        foreach (['view_any_contract', 'view_contract', 'create_contract', 'update_contract', 'replicate_contract'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $role = Role::create(['name' => 'Tes Kontrak ' . Str::random(5), 'guard_name' => 'web']);
        $role->givePermissionTo(['view_any_contract', 'view_contract', 'create_contract', 'update_contract']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->user($role->name));

        Livewire::test(ListContracts::class)->assertTableActionHidden('duplicate', $source);

        $role->givePermissionTo('replicate_contract');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::test(ListContracts::class)->assertTableActionVisible('duplicate', $source);
    }

    private function contract(): Contract
    {
        $deal = Deal::forceCreate(['nama_perusahaan' => 'PT Klien Lama', 'nama_pic' => 'Pak Budi', 'alamat' => 'Jl. Lama 1']);

        $contract = Contract::create([
            'deal_id'                => $deal->id,
            'contract_number'        => 'TES/' . Str::random(6),
            'document_title'         => 'PERJANJIAN KERJA SAMA',
            'articles'               => [['title' => 'Ruang Lingkup', 'content' => '<p>Isi pasal</p>']],
            'closing_text'           => '<p>Penutup</p>',
            'header_image'           => 'contracts/letterhead/kop.png',
            'client_name_full'       => 'PT Klien Lama',
            'client_address'         => 'Jl. Lama 1',
            'client_phone'           => '0811',
            'client_email'           => 'lama@example.test',
            'client_signatory_name'  => 'Pak Budi',
            'client_signatory_title' => 'Direktur',
            'agency_signatory_name'  => 'Indra',
            'effective_date'         => '2026-01-01',
            'expiration_date'        => '2026-12-31',
            'duration_months'        => 12,
            'payment_type'           => 'monthly',
            'total_value'            => 60_000_000,
            'installment_months'     => 12,
            'table_a_title'          => 'Rincian',
            'table_a_columns'        => ['estimate' => 'Per bulan'],
            'lampiran_description'   => 'Selama {durasi} bulan',
            'has_third_party'        => true,
        ]);

        $category = $contract->items()->create(['type' => 'sow', 'item' => 'Social Media', 'is_category_header' => true, 'sort_order' => 1]);
        $contract->items()->create(['type' => 'sow', 'item' => 'Desain 20 feed', 'parent_id' => $category->id, 'sort_order' => 1]);
        $contract->items()->create(['type' => 'sow', 'item' => 'Copywriting', 'parent_id' => $category->id, 'sort_order' => 2]);
        $contract->items()->create(['type' => 'fee', 'item' => 'Termin 1', 'estimate' => 'Rp 5 jt / bulan', 'amount' => 60_000_000, 'sort_order' => 1]);
        $contract->items()->create(['type' => 'third_party', 'item' => 'Meta Ads', 'amount' => 3_000_000, 'sort_order' => 1]);

        return $contract->fresh();
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'cd_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => 'Duplikat',
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
