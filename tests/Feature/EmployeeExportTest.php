<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Export Excel data karyawan dari HR → Employee: baris yang tampil di tabel;
 * kolom gaji dan lembar Komponen Gaji hanya untuk yang boleh mengelola gaji.
 */
class EmployeeExportTest extends TestCase
{
    use DatabaseTransactions;

    private string $division;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Karyawan tes dicari lewat awalan NIK-nya (kolom yang bisa dicari di tabel).
        $this->division = 'Divisi Tes ' . Str::random(5);
    }

    public function test_pengelola_gaji_mendapat_data_karyawan_dan_gaji(): void
    {
        $budi = $this->employee('Budi', '0812000111', [['Gaji pokok', 'earning', 5_000_000], ['BPJS', 'deduction', 100_000]]);
        $this->employee('Sari', '0812000222', [], active: false);

        $this->actingAs($this->user('super_admin'));
        $book = $this->download(Livewire::test(ListEmployees::class)->set('tableSearch', '327600000000000'));

        $this->assertSame(['Karyawan', 'Komponen Gaji'], $book->getSheetNames());

        $sheet = $book->getSheetByName('Karyawan');
        $row   = $this->rowOf($sheet, 'Budi Tes');
        $this->assertSame('3276000000000001', $sheet->getCell("A{$row}")->getValue(), 'NIK tetap teks, tidak jadi angka.');
        $this->assertSame('0812000111', $sheet->getCell("D{$row}")->getValue(), 'Nol di depan nomor HP tidak hilang.');
        $this->assertSame('Aktif', $sheet->getCell("J{$row}")->getValue());
        $this->assertSame('Take home pay', $sheet->getCell('O5')->getValue());
        $this->assertEquals(4_900_000, $sheet->getCell("O{$row}")->getValue());
        $this->assertSame('Nonaktif', $sheet->getCell('J' . $this->rowOf($sheet, 'Sari Tes'))->getValue());

        $components = $book->getSheetByName('Komponen Gaji');
        $this->assertSame('Gaji pokok', $components->getCell('C6')->getValue());
        $this->assertEquals(-100_000, $components->getCell('E7')->getValue(), 'Potongan bertanda minus.');
        $this->assertSame($budi->nik, $components->getCell('A6')->getValue());
    }

    public function test_tanpa_izin_kelola_gaji_tidak_ada_kolom_gaji(): void
    {
        $this->employee('Budi', '0812000111', [['Gaji pokok', 'earning', 5_000_000]]);

        $role = Role::create(['name' => 'Tes HR ' . Str::random(5), 'guard_name' => 'web']);
        $role->givePermissionTo(['view_any_user', 'view_user']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user($role->name));
        $book = $this->download(Livewire::test(ListEmployees::class)->set('tableSearch', '327600000000000'));

        $this->assertSame(['Karyawan'], $book->getSheetNames());
        $this->assertSame('Bergabung', $book->getSheetByName('Karyawan')->getCell('L5')->getValue());
        $this->assertNull($book->getSheetByName('Karyawan')->getCell('M5')->getValue(), 'Tanpa kolom gaji.');
    }

    private function download(Testable $component): Spreadsheet
    {
        $component->callAction('export')->assertFileDownloaded('Data Karyawan ' . now()->format('Y-m-d') . '.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'tes-export-');
        file_put_contents($path, base64_decode($component->effects['download']['content']));

        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function rowOf(Worksheet $sheet, string $name): int
    {
        for ($row = 6; $row < 50; $row++) {
            if ($sheet->getCell("B{$row}")->getValue() === $name) {
                return $row;
            }
        }

        $this->fail("{$name} tidak ada di export.");
    }

    private function employee(string $name, string $phone, array $components, bool $active = true): User
    {
        $user = User::forceCreate([
            'username'     => 'ex_' . Str::lower(Str::random(8)),
            'firstname'    => $name,
            'lastname'     => 'Tes',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => $active,
            'push_enabled' => false,
            'nik'          => $name === 'Budi' ? '3276000000000001' : '3276000000000002',
            'phone'        => $phone,
            'position'     => 'Staff',
            'division'     => $this->division,
        ]);
        $user->assignRole('Staff');

        foreach ($components as $i => [$label, $type, $amount]) {
            $user->payrollComponents()->create(['name' => $label, 'type' => $type, 'amount' => $amount, 'sort' => $i + 1]);
        }

        return $user;
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'ex_' . Str::lower(Str::random(8)),
            'firstname'         => 'Admin',
            'lastname'          => 'Tes',
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'is_active'         => true,
            'push_enabled'      => false,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
