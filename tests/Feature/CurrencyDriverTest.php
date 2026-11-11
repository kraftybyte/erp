<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use TomatoPHP\FilamentLocations\Models\Currency;

/**
 * Mata uang dibaca dari tabel MySQL, bukan Sushi/SQLite (gagal "too many SQL
 * variables" di server dengan SQLite lama), dan migrasi mengisi yang hilang
 * dengan id yang sama.
 */
class CurrencyDriverTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mata_uang_dari_mysql_dengan_id_tetap(): void
    {
        $this->assertSame('database', config('filament-locations.driver'));
        $this->assertSame(DB::connection()->getDriverName(), (new Currency())->getConnection()->getDriverName());
        $this->assertSame(66, (int) Currency::where('iso', 'IDR')->value('id'));
    }

    public function test_migrasi_mengisi_mata_uang_yang_hilang_tanpa_mengubah_yang_ada(): void
    {
        DB::table('currencies')->where('iso', 'EUR')->delete(); // tidak dirujuk invoice (IDR dijaga foreign key)
        DB::table('currencies')->where('iso', 'USD')->update(['symbol' => 'US$ (diubah)']);
        $before = DB::table('currencies')->count();

        (require database_path('migrations/2026_11_11_160000_fill_currencies_table.php'))->up();

        $eur = DB::table('currencies')->where('iso', 'EUR')->first();
        $this->assertSame([50, 'Euro', '€'], [(int) $eur->id, $eur->name, $eur->symbol]);
        $this->assertSame($before + 1, DB::table('currencies')->count());
        $this->assertSame('US$ (diubah)', DB::table('currencies')->where('iso', 'USD')->value('symbol'), 'Baris yang ada tidak ditimpa.');
    }
}
