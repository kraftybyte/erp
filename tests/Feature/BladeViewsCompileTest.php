<?php

namespace Tests\Feature;

use App\Filament\Resources\AdvanceResource\Pages\ViewAdvance;
use App\Models\Advance;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ParseError;
use Tests\TestCase;

/**
 * Setiap view Blade aplikasi harus menjadi PHP yang valid. Kesalahan seperti
 * @php(...) satu baris di file yang juga memakai @php ... @endphp baru
 * ketahuan saat halamannya dibuka (View Advance sempat error 500 karena itu).
 */
class BladeViewsCompileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_semua_view_blade_menjadi_php_yang_valid(): void
    {
        $broken = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $php = Blade::compileString($file->getContents());

            // Isi <x-filament-panels::page> dikompilasi di dalam blok if, tempat
            // `use ...;` di @php tidak sah; dibungkus if supaya itu ikut ketahuan.
            if (str_contains($file->getContents(), '<x-filament-panels::page')) {
                $php = '<?php if (true): ?>' . $php . '<?php endif; ?>';
            }

            try {
                token_get_all($php, TOKEN_PARSE);
            } catch (ParseError $e) {
                $broken[] = $file->getRelativePathname() . ' (baris hasil kompilasi ' . $e->getLine() . '): ' . $e->getMessage();
            }
        }

        $this->assertSame([], $broken);
    }

    public function test_halaman_view_advance_tampil_dengan_lampiran_dari_bot(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::forceCreate([
            'username'     => 'view_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Advance',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole('super_admin');
        $this->actingAs($user);

        $advance = Advance::create([
            'user_id'            => $user->id,
            'submission_date'    => today(),
            'category'           => 'Project Support',
            'keterangan_advance' => 'Sewa lighting',
            'amount'             => 2_000_000,
            'status'             => Advance::STATUS_PENDING,
            'attachments'        => ['advance/attachment/a.jpg', 'advance/attachment/b.pdf'],
        ]);

        Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
            ->assertOk()
            ->assertSee('Lampiran 1')
            ->assertSee('Lampiran 2');
    }
}
