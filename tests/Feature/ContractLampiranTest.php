<?php

namespace Tests\Feature;

use App\Filament\Resources\ContractResource\Pages\EditContract;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\User;
use App\Services\ContractTemplateService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lampiran kontrak bisa diatur penuh: judul, keterangan, judul kolom, baris
 * (termasuk Estimasi sendiri), dan label total, untuk Tabel A dan B. Kontrak
 * yang tidak mengisinya tetap tampil seperti dulu.
 */
class ContractLampiranTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->superAdmin());
    }

    public function test_kontrak_tanpa_isian_tetap_tampil_seperti_dulu(): void
    {
        $html = $this->render($this->contract());

        $this->assertStringContainsString('<div class="table-a-title">Tabel A</div>', $html);
        $this->assertStringContainsString('Biaya yang perlu dibayarkan oleh Pihak Kedua kepada Pihak Pertama selama 12 bulan', $html);
        foreach (['>No</th>', '>Item</th>', '>Estimasi</th>', '>Tanggal</th>', '>Jumlah</th>'] as $header) {
            $this->assertStringContainsString($header, $html);
        }
        // Baris otomatis dari Pembayaran, seperti contoh: 12x Rp 5.500.000 = Rp 66.000.000.
        $this->assertMatchesRegularExpression('/Pembayaran per Bulan \(12x\)<\/td>\s*<td>Rp 5\.500\.000<\/td>\s*<td>Setiap tanggal 15<\/td>\s*<td class="text-right">Rp 66\.000\.000<\/td>/', $html);
        $this->assertMatchesRegularExpression('/>TOTAL<\/td>\s*<td class="text-right font-bold">Rp 66\.000\.000</', $html);
    }

    public function test_semua_teks_tabel_bisa_diganti(): void
    {
        $contract = $this->contract([
            'lampiran_title'       => 'Lampiran I — Biaya',
            'table_a_title'        => 'Rincian Pembayaran',
            'lampiran_description' => 'Dibayar selama {durasi} bulan ke rekening perusahaan',
            'table_a_columns'      => ['no' => '#', 'item' => 'Uraian', 'estimate' => 'Per bulan', 'date' => 'Jatuh tempo', 'amount' => 'Subtotal'],
            'table_a_total_label'  => 'Jumlah keseluruhan',
            'has_third_party'      => true,
            'table_b_title'        => 'Biaya Iklan',
            'table_b_description'  => 'Dibayar langsung ke Meta',
            'table_b_columns'      => ['item' => 'Platform', 'amount' => 'Nominal'],
        ]);
        $contract->feeItems()->create(['type' => 'fee', 'item' => 'Retainer', 'estimate' => 'Rp 5,5 jt / bulan', 'due_date' => 'Tanggal 15 tiap bulan', 'amount' => 66_000_000, 'sort_order' => 1]);
        $contract->thirdPartyItems()->create(['type' => 'third_party', 'item' => 'Meta Ads', 'amount' => 3_000_000, 'notes' => 'Saldo iklan', 'sort_order' => 1]);

        $html = $this->render($contract);

        foreach (['Lampiran I — Biaya', 'Rincian Pembayaran', 'Dibayar selama 12 bulan ke rekening perusahaan', '>#</th>', '>Uraian</th>', '>Per bulan</th>', '>Jatuh tempo</th>', '>Subtotal</th>',
            '<td>Rp 5,5 jt / bulan</td>', '<td>Tanggal 15 tiap bulan</td>', '>Jumlah keseluruhan</td>', 'Biaya Iklan', 'Dibayar langsung ke Meta', '>Platform</th>', '>Nominal</th>', 'Meta Ads'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Pembayaran per Bulan (12x)', $html, 'Ada baris sendiri: baris otomatis tidak dipakai.');
        $this->assertStringContainsString('>No</th>', $html, 'Kolom Tabel B yang kosong memakai judul bawaan.');
    }

    public function test_isi_otomatis_lalu_ubah_baris_di_form(): void
    {
        $contract = $this->contract();

        $page = Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
            ->callFormComponentAction('fee_items', 'fillFeeRowsFromPayment');

        $rows = array_values($page->get('data.fee_items'));
        $this->assertSame([['item' => 'Pembayaran per Bulan (12x)', 'estimate' => 'Rp 5.500.000', 'due_date' => 'Setiap tanggal 15', 'amount' => 66000000.0]],
            array_map(fn ($row) => array_intersect_key($row, array_flip(['item', 'estimate', 'due_date', 'amount'])), $rows));

        $key = array_key_first($page->get('data.fee_items'));
        $page->set("data.fee_items.{$key}.due_date", 'Setiap tanggal 25')
            ->set('data.table_a_columns.estimate', 'Per bulan')
            ->set('data.has_third_party', true)
            ->set('data.third_party_items', [(string) Str::uuid() => ['item' => 'Meta Ads', 'amount' => 3_000_000, 'notes' => null]])
            ->call('save')
            ->assertHasNoFormErrors();

        $contract->refresh();
        $this->assertSame([['fee', 'Pembayaran per Bulan (12x)', 'Rp 5.500.000', 'Setiap tanggal 25']],
            $contract->feeItems->map(fn (ContractItem $i) => [$i->type, $i->item, $i->estimate, $i->due_date])->all());
        $this->assertSame([['third_party', 'Meta Ads']], $contract->thirdPartyItems->map(fn (ContractItem $i) => [$i->type, $i->item])->all(),
            'Baris pihak ketiga tersimpan sebagai third_party, bukan masuk Tabel A.');
        $this->assertSame('Per bulan', $contract->table_a_columns['estimate']);

        $html = $this->render($contract);
        $this->assertStringContainsString('>Per bulan</th>', $html);
        $this->assertStringContainsString('<td>Setiap tanggal 25</td>', $html);
    }

    private function render(Contract $contract): string
    {
        return app(ContractTemplateService::class)->generate($contract->fresh());
    }

    private function contract(array $attributes = []): Contract
    {
        return Contract::create($attributes + [
            'contract_number'      => 'TES/' . Str::random(6),
            'client_name_full'     => 'PT Uji Lampiran',
            'effective_date'       => '2026-11-01',
            'duration_months'      => 12,
            'payment_type'         => 'monthly',
            'total_value'          => 66_000_000,
            'down_payment_percent' => 0,
            'installment_months'   => 12,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::forceCreate([
            'username'          => 'cl_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => 'Lampiran',
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'email_verified_at' => now(),
            'is_active'         => true,
            'push_enabled'      => false,
        ]);
        $user->assignRole('super_admin');

        return $user;
    }
}
