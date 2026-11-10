<?php

namespace Tests\Feature;

use App\Filament\Resources\AdvanceResource\Pages\RealisasiAdvance;
use App\Models\Advance;
use App\Models\RealisasiItem;
use App\Models\User;
use App\Services\RealisasiService;
use App\Settings\IntegrationSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tanggal pembelian di struk/invoice/bukti bayar setiap item realisasi, dibaca
 * AI: realisasi bisa dikirim jauh setelah uangnya dipakai.
 */
class RealisasiReceiptDateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('public');

        IntegrationSettings::fake([
            'gemini_api_key'          => 'kunci-tes',
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => null,
            'telegram_webhook_secret' => null,
            'finance_bot_token'       => null,
            'finance_webhook_secret'  => null,
        ]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'amount'      => 281_663,
            'name'        => 'Kurir Barang',
            'description' => 'Kirim barang pickup bak',
            'date_text'   => '02/11/2026 13:20',
            'date'        => '2026-11-02',
            'category'    => null,
            'items'       => [],
        ])]]]]]])]);
    }

    public function test_unggah_bukti_di_erp_mengisi_tanggal_struk_dari_ai(): void
    {
        $owner   = $this->user('Staff');
        $advance = $this->advance($owner);

        $this->actingAs($owner);

        Livewire::test(RealisasiAdvance::class, ['record' => $advance])
            ->call('openBatchModal')
            ->set('uploadedFiles', [UploadedFile::fake()->image('bukti-bayar.jpg', 400, 800)])
            ->assertSet('batchItems.0.receipt_date', '2026-11-02')
            ->assertSee('Tanggal dibaca otomatis dari struk')
            ->set('batchItems.0.name', 'Kirim barang pickup')
            ->set('batchItems.0.price', 281_663)
            ->call('saveBatch')
            ->assertSee('Tanggal struk 2 Nov 2026');

        $item = RealisasiItem::whereHas('realizasi', fn ($query) => $query->where('advance_id', $advance->id))->sole();
        $this->assertSame('2026-11-02', $item->receipt_date->toDateString(), 'Tanggal pembelian, bukan tanggal dikirim (10 Nov).');
    }

    public function test_tanggal_yang_tidak_masuk_akal_tidak_disimpan(): void
    {
        $owner   = $this->user('Staff');
        $advance = $this->advance($owner);

        app(RealisasiService::class)->createBatch($advance, ['uploaded_by' => $owner->id], [
            ['name' => 'Bensin', 'quantity' => 1, 'price' => 100_000, 'receipt_date' => '2026-11-08'],
            ['name' => 'Parkir', 'quantity' => 1, 'price' => 10_000, 'receipt_date' => '2026-12-01'],
            ['name' => 'Tol', 'quantity' => 1, 'price' => 20_000, 'receipt_date' => '2026-02-30'],
            ['name' => 'Makan', 'quantity' => 1, 'price' => 50_000],
        ]);

        $dates = collect(app(RealisasiService::class)->batchesForDisplay($advance)[0]['items'])->pluck('receipt_date', 'name');

        $this->assertSame(['Bensin' => '2026-11-08', 'Parkir' => null, 'Tol' => null, 'Makan' => null], $dates->all());
    }

    private function advance(User $owner): Advance
    {
        return Advance::create([
            'user_id'            => $owner->id,
            'submission_date'    => today(),
            'category'           => 'Project Support',
            'keterangan_advance' => 'Tes tanggal struk ' . Str::random(4),
            'amount'             => 1_000_000,
            'paid'               => 1_000_000,
            'status'             => Advance::STATUS_DIBAYARKAN,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'rd_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => $role,
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
