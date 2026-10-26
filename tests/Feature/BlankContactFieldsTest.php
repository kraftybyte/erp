<?php

namespace Tests\Feature;

use App\Filament\Resources\LeadsResource\Pages\CreateLeads;
use App\Models\Leads;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Email (dan kontak lain) boleh kosong, dan yang kosong tidak dicetak di PDF.
 */
class BlankContactFieldsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_leads_boleh_tanpa_email(): void
    {
        $user = User::forceCreate([
            'username'     => 'lead_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Leads',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $company = 'PT Tanpa Email ' . Str::upper(Str::random(6));

        Livewire::test(CreateLeads::class)
            ->fillForm([
                'nama_pic'        => 'Rina',
                'nama_perusahaan' => $company,
                'email'           => '',
                'telephone'       => '0812345678',
                'source'          => 'Referral',
                'status'          => 'Dalam Komunikasi',
                'product_id'      => [Product::query()->value('id') ?? Product::forceCreate(['nama_layanan' => 'Tes'])->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Leads::where('nama_perusahaan', $company)->value('email'));
    }

    public function test_pdf_quotation_tidak_mencetak_kontak_kosong(): void
    {
        $quotation = new Quotation([
            'uuid'            => '#DG/TES/00001',
            'nama_perusahaan' => 'PT Organik',
            'nama_pic'        => 'Fahmi',
            'email'           => '',
            'telephone'       => '-',
            'date'            => '2026-10-26',
            'due_date'        => '2026-11-04',
        ]);
        $quotation->setRelation('items', collect());

        $settings = app(GeneralSettings::class);
        $html = view('pdf.quotation-dynamic', [
            'quotation'  => $quotation,
            'settings'   => $settings->getQuotationSettings(),
            'themeColor' => $settings->quot_theme_color,
        ])->render();

        $this->assertMatchesRegularExpression('/PT Organik<br>Fahmi\s*<\/p>/', $html);
    }
}
