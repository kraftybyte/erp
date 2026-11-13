<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\User;
use App\Services\EmployeeExport;
use App\Services\EmployeeImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Import karyawan dari Excel: dicocokkan lewat NIK, yang ada diperbarui dan yang
 * baru dibuat, baris bermasalah dilewati dengan alasannya, komponen gaji dari
 * sheet Komponen Gaji, dan izin Shield per baris.
 */
class EmployeeImportTest extends TestCase
{
    use DatabaseTransactions;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'file_exists'));

        parent::tearDown();
    }

    public function test_membuat_memperbarui_dan_melewati_baris_bermasalah(): void
    {
        $existing = $this->employee('3276000000009001', 'Lama', ['phone' => '081100000000', 'email' => 'lama-' . Str::random(5) . '@example.test']);
        $email    = 'baru-' . Str::lower(Str::random(6)) . '@example.test';

        $path = $this->xlsx([
            'Karyawan' => [
                ['NIK', 'Nama', 'Email', 'Nomor HP', 'Alamat', 'Jabatan', 'Divisi', 'NPWP', 'Peran', 'Status'],
                ['3276000000009002', 'Rina Ayu Lestari', $email, 81299990000, 'Jl. Mawar 2', 'staff', 'graphic design', '', 'Staff', 'Aktif'],
                ['3276000000009001', 'Lama Tes', '', '081100000099', '', 'Manager', '', '', '', ''],
                ['3276000000009003', 'Salah Divisi', 'x-' . Str::random(5) . '@example.test', '', '', 'Staff', 'Marketing', '', '', ''],
                ['3276000000009002', 'Dobel', 'y-' . Str::random(5) . '@example.test', '', '', '', '', '', '', ''],
                [3276000000009004, 'Angka', 'z-' . Str::random(5) . '@example.test', '', '', '', '', '', '', ''],
                ['', '', '', '', '', '', '', '', '', ''],
                ['Total', '', '', '', '', '', '', '', '', ''],
            ],
            'Komponen Gaji' => [
                ['NIK', 'Nama', 'Komponen', 'Jenis', 'Nominal / bulan'],
                ['3276000000009002', 'Rina', 'Gaji pokok', 'Pendapatan', 'Rp 5.000.000'],
                ['3276000000009002', 'Rina', 'BPJS Kesehatan', '', -50000],
                ['9999999999999999', 'Siapa', 'Gaji pokok', 'Pendapatan', 1000],
            ],
        ]);

        $admin = $this->admin('super_admin');
        $plan  = app(EmployeeImport::class)->plan($path, $admin);
        $rows  = collect($plan['rows'])->keyBy('row');

        $this->assertNull($plan['error']);
        $this->assertSame('create', $rows[2]['action']);
        $this->assertSame('update', $rows[3]['action']);
        $this->assertSame(['HP: 081100000000 → 081100000099', 'Jabatan: Staff → Manager'], $rows[3]['changes'], 'Sel kosong tidak mengubah data lama.');
        $this->assertStringContainsString('Divisi "Marketing" tidak dikenal', $rows[4]['reason']);
        $this->assertSame(['3276000000009003', 'Salah Divisi'], [$rows[4]['nik'], $rows[4]['name']], 'Baris yang dilewati tetap menyebut NIK dan namanya.');
        $this->assertSame('NIK sama dengan baris 2.', $rows[5]['reason']);
        $this->assertStringContainsString('tersimpan sebagai angka', $rows[6]['reason']);
        $this->assertCount(5, $plan['rows'], 'Baris kosong dan Total dilewati tanpa dihitung.');
        $this->assertStringContainsString('NIK 9999999999999999 tidak ada', implode(' ', $plan['notes']));

        $counts = app(EmployeeImport::class)->import($path, $admin);
        $this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'skipped' => 3, 'components' => 2], $counts);

        $rina = User::where('nik', '3276000000009002')->sole();
        $this->assertSame(['Rina', 'Ayu Lestari', $email, '081299990000', 'Staff', 'Graphic Design'], [$rina->firstname, $rina->lastname, $rina->email, $rina->phone, $rina->position, $rina->division]);
        $this->assertTrue($rina->hasRole('Staff'));
        $this->assertTrue($rina->is_active);
        $this->assertSame([['Gaji pokok', 'earning', 5_000_000.0], ['BPJS Kesehatan', 'deduction', 50_000.0]],
            $rina->payrollComponents()->get()->map(fn ($c) => [$c->name, $c->type, (float) $c->amount])->all());

        $existing->refresh();
        $this->assertSame(['081100000099', 'Manager'], [$existing->phone, $existing->position]);
    }

    public function test_izin_shield_per_baris(): void
    {
        $this->employee('3276000000009101', 'Ada');

        $role = Role::create(['name' => 'Tes Import ' . Str::random(5), 'guard_name' => 'web']);
        $role->givePermissionTo(['view_any_user', 'view_user', 'update_user']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $hr = $this->admin($role->name);

        $path = $this->xlsx([
            'Karyawan' => [
                ['NIK', 'Nama', 'Email', 'Jabatan'],
                ['3276000000009101', 'Ada Tes', '', 'Supervisor'],
                ['3276000000009102', 'Belum Ada', 'b-' . Str::random(5) . '@example.test', 'Staff'],
            ],
            'Komponen Gaji' => [
                ['NIK', 'Komponen', 'Jenis', 'Nominal'],
                ['3276000000009101', 'Gaji pokok', 'Pendapatan', 5000000],
            ],
        ]);

        $plan = app(EmployeeImport::class)->plan($path, $hr);
        $rows = collect($plan['rows'])->keyBy('row');

        $this->assertSame('update', $rows[2]['action'], 'Update User cukup untuk mengubah.');
        $this->assertStringContainsString('butuh izin Create User', $rows[3]['reason']);
        $this->assertSame([], $plan['components']);
        $this->assertStringContainsString('Sheet Komponen Gaji diabaikan', implode(' ', $plan['notes']));

        // Tanpa izin create maupun update: tombol Import tidak tampil.
        $viewer = Role::create(['name' => 'Tes Lihat ' . Str::random(5), 'guard_name' => 'web']);
        $viewer->givePermissionTo(['view_any_user', 'view_user']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->admin($viewer->name));
        Livewire::test(ListEmployees::class)->assertActionHidden('import')->assertActionVisible('export');
        $this->get(route('import-template.employees'))->assertForbidden();
    }

    public function test_tanpa_update_role_peran_tidak_bisa_diubah_lewat_import(): void
    {
        $this->employee('3276000000009201', 'Hera');

        $role = Role::create(['name' => 'Tes Import HR ' . Str::random(5), 'guard_name' => 'web']);
        $role->givePermissionTo(['view_any_user', 'view_user', 'update_user']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $path = $this->xlsx(['Karyawan' => [
            ['NIK', 'Nama', 'Peran'],
            ['3276000000009201', 'Hera Tes', 'Director'],
        ]]);

        $row = collect(app(EmployeeImport::class)->plan($path, $this->admin($role->name))['rows'])->first();

        $this->assertSame('skip', $row['action']);
        $this->assertStringContainsString('butuh izin Update Role', $row['reason']);
    }

    public function test_file_export_bisa_diimpor_lagi_tanpa_perubahan(): void
    {
        $budi = $this->employee('3276000000009201', 'Budi', ['phone' => '081234500000', 'npwp' => '12.345.678.9-012.' . random_int(100, 999)]);
        $budi->payrollComponents()->create(['name' => 'Gaji pokok', 'type' => 'earning', 'amount' => 5_000_000, 'sort' => 1]);

        $admin = $this->admin('super_admin');
        $this->actingAs($admin);
        $path = $this->files[] = app(EmployeeExport::class)->employees(User::query()->whereKey($budi->id));

        $plan = app(EmployeeImport::class)->plan($path, $admin);

        $this->assertSame(['same'], collect($plan['rows'])->pluck('action')->all(), 'Data yang sama tidak dianggap berubah.');
        $this->assertSame(['3276000000009201' => [['name' => 'Gaji pokok', 'type' => 'earning', 'amount' => 5_000_000.0]]], $plan['components']);
    }

    public function test_template_bisa_diunduh_dan_terbaca(): void
    {
        $this->actingAs($this->admin('super_admin'));

        $this->get(route('import-template.employees'))->assertOk();

        $path = $this->files[] = app(EmployeeImport::class)->template();
        $plan = app(EmployeeImport::class)->plan($path, $this->admin('super_admin'));

        $this->assertNull($plan['error']);
        $this->assertSame('3276012345678901', $plan['rows'][0]['nik'], 'NIK contoh tetap teks.');
        $this->assertCount(3, $plan['components']['3276012345678901']);
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  nama sheet => baris (baris pertama judul kolom)
     */
    private function xlsx(array $sheets): string
    {
        $book  = new Spreadsheet();
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
            $sheet->setTitle($name);
            $first = false;

            foreach ($rows as $r => $values) {
                foreach ($values as $c => $value) {
                    $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . ($r + 1);
                    is_int($value) || is_float($value)
                        ? $sheet->setCellValue($coordinate, $value)
                        : $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                }
            }
        }

        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'tes-import-') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function employee(string $nik, string $name, array $extra = []): User
    {
        $user = User::forceCreate($extra + [
            'username'     => 'im_' . Str::lower(Str::random(8)),
            'firstname'    => $name,
            'lastname'     => 'Tes',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
            'nik'          => $nik,
            'position'     => 'Staff',
            'division'     => 'Finance',
        ]);
        $user->assignRole('Staff');

        return $user;
    }

    private function admin(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'ia_' . Str::lower(Str::random(8)),
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
