<?php

namespace App\Services;

use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Models\PayrollComponent;
use App\Models\User;
use App\Services\Concerns\WritesExcelTables;
use App\Services\Telegram\AmountParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Import karyawan dari Excel/CSV: format yang sama dengan Export Excel di
 * HR → Employee (atau template-nya), jadi data bisa diekspor, diubah, lalu
 * diimpor lagi.
 *
 * - Karyawan dicocokkan lewat NIK, lalu email. Yang cocok diperbarui (sel
 *   kosong = data lama tetap); yang tidak cocok dibuat baru.
 * - Baris yang bermasalah dilewati dengan alasannya; baris lain tetap masuk.
 * - Izin Shield: buat baru create_user, ubah update_user, sheet Komponen Gaji
 *   update_payslip. Peran super_admin hanya boleh diberikan super_admin.
 * - Sheet Komponen Gaji (bila ada) mengganti komponen gaji karyawan yang
 *   tercantum di sheet itu; karyawan lain tidak tersentuh.
 */
class EmployeeImport
{
    use WritesExcelTables;

    public const MAX_ROWS = 2000;

    private const HEADERS = [
        'nik'       => '/^nik$/',
        'name'      => '/^nama( lengkap| karyawan)?$/',
        'firstname' => '/^nama depan$/',
        'lastname'  => '/^nama belakang$/',
        'email'     => '/^e ?mail$/',
        'phone'     => '/^(nomor hp|no hp|hp|nomor telepon|no telepon|telepon|telp|phone|whatsapp|wa)$/',
        'address'   => '/^alamat( lengkap)?$/',
        'position'  => '/^jabatan$/',
        'division'  => '/^divisi$/',
        'npwp'      => '/^npwp$/',
        'status'    => '/^(status|aktif)$/',
        'roles'     => '/^(peran|role|roles)$/',
    ];

    private const COMPONENT_HEADERS = [
        'nik'    => '/^nik$/',
        'name'   => '/^komponen$/',
        'type'   => '/^jenis$/',
        'amount' => '/^nominal/',
    ];

    private const LABELS = [
        'firstname' => 'Nama depan', 'lastname' => 'Nama belakang', 'email' => 'Email', 'phone' => 'HP',
        'address' => 'Alamat', 'position' => 'Jabatan', 'division' => 'Divisi', 'npwp' => 'NPWP', 'is_active' => 'Status',
    ];

    /**
     * Periksa file tanpa menyimpan apa pun.
     *
     * @return array{error: ?string, rows: list<array>, components: array<string, list<array{name: string, type: string, amount: float}>>, notes: list<string>, salary: bool}
     */
    public function plan(string $path, User $by): array
    {
        $result = ['error' => null, 'rows' => [], 'components' => [], 'notes' => [], 'salary' => Payroll::canManage($by)];

        try {
            $book = IOFactory::load($path);
        } catch (Throwable) {
            return ['error' => 'File tidak bisa dibaca. Simpan sebagai .xlsx atau .csv lalu coba lagi.'] + $result;
        }

        [$sheet, $header, $columns] = $this->findTable($book, self::HEADERS, ['nik']) ?? [null, null, null];

        if (! $sheet || ! (isset($columns['name']) || isset($columns['firstname']))) {
            return ['error' => 'Kolom NIK dan Nama tidak ditemukan. Pakai template atau file hasil Export Excel.'] + $result;
        }

        $seen  = [];
        $roles = Role::pluck('name')->all();

        foreach ($this->rows($sheet, $header, $columns) as $number => $cells) {
            $result['rows'][] = $this->planRow($number, $cells, $by, $seen, $roles);
        }

        if (! $result['rows']) {
            return ['error' => 'Tidak ada baris karyawan di bawah judul kolom.'] + $result;
        }

        $this->planComponents($book, $by, $result);

        return $result;
    }

    /**
     * Simpan baris yang lolos pemeriksaan.
     *
     * @return array{created: int, updated: int, unchanged: int, skipped: int, components: int}
     */
    public function import(string $path, User $by): array
    {
        $plan   = $this->plan($path, $by);
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'components' => 0];

        if ($plan['error']) {
            return $counts;
        }

        DB::transaction(function () use ($plan, &$counts) {
            $users = [];

            foreach ($plan['rows'] as $row) {
                $counts[match ($row['action']) { 'create' => 'created', 'update' => 'updated', 'same' => 'unchanged', default => 'skipped' }]++;

                if ($row['action'] === 'create') {
                    $user = User::forceCreate($row['data'] + [
                        'username'     => CreateEmployee::uniqueUsername($row['data']),
                        'password'     => bcrypt(Str::random(40)),
                        'is_active'    => $row['data']['is_active'] ?? true,
                        'push_enabled' => false,
                    ]);
                } elseif (in_array($row['action'], ['update', 'same'], true)) {
                    $user = User::find($row['user_id']);
                    $row['data'] && $user->forceFill($row['data'])->save();
                } else {
                    continue;
                }

                if ($row['roles']) {
                    $user->syncRoles($row['roles']);
                }

                $users[$row['nik']] = $user;
            }

            foreach ($plan['components'] as $nik => $components) {
                $user = $users[$nik] ?? User::where('nik', $nik)->first();

                if (! $user) {
                    continue;
                }

                $user->payrollComponents()->delete();

                foreach (array_values($components) as $i => $component) {
                    $user->payrollComponents()->create($component + ['sort' => $i + 1]);
                    $counts['components']++;
                }
            }
        });

        return $counts;
    }

    /**
     * Template Excel: kolom yang sama dengan Export Excel, satu baris contoh,
     * sheet Komponen Gaji, dan petunjuk pengisian.
     *
     * @return string path file sementara; pemanggil yang menghapusnya
     */
    public function template(): string
    {
        $book = new Spreadsheet();

        $this->templateSheet($book->getActiveSheet()->setTitle('Karyawan'), 'Template Import Karyawan', [
            ['NIK', 20], ['Nama', 24], ['Email', 28], ['Nomor HP', 16], ['Alamat', 36], ['Jabatan', 14], ['Divisi', 22], ['NPWP', 22], ['Peran', 16], ['Status', 10],
        ], [['3276012345678901', 'Budi Santoso', 'budi@contoh.co.id', '081234567890', 'Jl. Melati 1, Depok', 'Staff', 'Graphic Design', '12.345.678.9-012.000', 'Staff', 'Aktif']], textColumns: ['A', 'D', 'H']);

        $this->templateSheet($book->createSheet()->setTitle('Komponen Gaji'), 'Komponen Gaji (opsional)', [
            ['NIK', 20], ['Nama', 24], ['Komponen', 30], ['Jenis', 12], ['Nominal / bulan', 18],
        ], [
            ['3276012345678901', 'Budi Santoso', 'Gaji pokok', 'Pendapatan', 5000000],
            ['3276012345678901', 'Budi Santoso', 'Tunjangan transport', 'Pendapatan', 500000],
            ['3276012345678901', 'Budi Santoso', 'BPJS Kesehatan', 'Potongan', 50000],
        ], textColumns: ['A']);

        $help = $book->createSheet()->setTitle('Petunjuk');
        $lines = [
            'Cara mengisi',
            '• Satu baris = satu karyawan. NIK wajib; Nama dan Email wajib untuk karyawan baru.',
            '• Karyawan dicocokkan lewat NIK (lalu email): yang sudah ada diperbarui, yang belum ada dibuat baru.',
            '• Sel yang dikosongkan tidak mengubah data lama.',
            '• Kolom NIK, Nomor HP, dan NPWP diformat sebagai Teks supaya angka 0 di depan dan digit NIK tidak berubah.',
            '• Jabatan: ' . implode(', ', EmployeeResource::POSITIONS) . '.',
            '• Divisi: ' . implode(', ', EmployeeResource::DIVISIONS) . '.',
            '• Peran: nama peran di Settings → Roles, pisahkan dengan koma bila lebih dari satu. Kosong = peran tidak diubah.',
            '• Status: Aktif atau Nonaktif.',
            '• Sheet Komponen Gaji (opsional, butuh izin kelola gaji): komponen karyawan yang tercantum diganti seluruhnya dengan isi sheet ini. Jenis: Pendapatan atau Potongan.',
            '• Karyawan baru masuk ERP lewat "Lupa password" di halaman login.',
        ];
        foreach ($lines as $i => $line) {
            $help->setCellValueExplicit('A' . ($i + 1), $line, DataType::TYPE_STRING);
        }
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $help->getColumnDimension('A')->setWidth(120);

        return $this->save($book);
    }

    // =================================================================
    // Pemeriksaan baris
    // =================================================================

    private function planRow(int $number, array $cells, User $by, array &$seen, array $roles): array
    {
        $row  = ['row' => $number, 'action' => 'skip', 'nik' => '', 'name' => '', 'changes' => [], 'reason' => null, 'data' => [], 'user_id' => null, 'roles' => []];
        // Referensi: NIK dan nama yang diisi di bawah ikut tampil di baris yang dilewati.
        $skip = function (string $reason) use (&$row) {
            return ['reason' => $reason] + $row;
        };

        if (($cells['nik']['numeric'] ?? false) && strlen($cells['nik']['value']) >= 16) {
            return $skip('NIK tersimpan sebagai angka di Excel sehingga digitnya bisa berubah. Format kolom NIK sebagai Teks lalu ketik ulang.');
        }

        $nik = preg_replace('/\s+/', '', $cells['nik']['value'] ?? '');
        $row['nik'] = $nik;

        [$first, $last] = isset($cells['name'])
            ? $this->splitName($cells['name']['value'])
            : [$cells['firstname']['value'] ?? '', $cells['lastname']['value'] ?? ''];
        $row['name'] = trim("{$first} {$last}");

        if ($nik === '') {
            return $skip('NIK kosong.');
        }

        if (strlen($nik) > 30) {
            return $skip('NIK terlalu panjang.');
        }

        if (isset($seen[$nik])) {
            return $skip("NIK sama dengan baris {$seen[$nik]}.");
        }
        $seen[$nik] = $number;

        $email  = Str::lower(trim($cells['email']['value'] ?? ''));
        $person = User::where('nik', $nik)->first() ?? ($email !== '' ? User::where('email', $email)->first() : null);

        if ($person && filled($person->nik) && $person->nik !== $nik) {
            return $skip("Email {$email} sudah dipakai karyawan lain (NIK {$person->nik}).");
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $skip("Email {$email} tidak valid.");
        }

        if ($email !== '' && User::where('email', $email)->when($person, fn ($q) => $q->whereKeyNot($person->id))->exists()) {
            return $skip("Email {$email} sudah dipakai akun lain.");
        }

        $data = array_filter([
            'nik'       => $nik,
            'firstname' => $first,
            'lastname'  => $last,
            'email'     => $email,
            'phone'     => $this->phone($cells['phone'] ?? null),
            'address'   => trim($cells['address']['value'] ?? ''),
            'npwp'      => trim($cells['npwp']['value'] ?? ''),
        ], fn ($value) => $value !== '');

        foreach (['position' => EmployeeResource::POSITIONS, 'division' => EmployeeResource::DIVISIONS] as $field => $options) {
            $value = trim($cells[$field]['value'] ?? '');

            if ($value === '') {
                continue;
            }

            $match = collect($options)->first(fn (string $option) => strcasecmp($option, $value) === 0);

            if (! $match) {
                return $skip(self::LABELS[$field] . " \"{$value}\" tidak dikenal. Pilihan: " . implode(', ', $options) . '.');
            }

            $data[$field] = $match;
        }

        if (isset($data['npwp']) && User::where('npwp', $data['npwp'])->when($person, fn ($q) => $q->whereKeyNot($person->id))->exists()) {
            return $skip("NPWP {$data['npwp']} sudah dipakai karyawan lain.");
        }

        $status = Str::lower(trim($cells['status']['value'] ?? ''));
        if ($status !== '') {
            $active = match (true) {
                in_array($status, ['aktif', 'active', 'ya', 'yes', 'y', '1', 'true'], true)                         => true,
                in_array($status, ['nonaktif', 'non aktif', 'tidak aktif', 'inactive', 'tidak', 'no', 'n', '0', 'false'], true) => false,
                default                                                                                             => null,
            };

            if ($active === null) {
                return $skip("Status \"{$status}\" tidak dikenal. Isi Aktif atau Nonaktif.");
            }

            // Akun sendiri tidak bisa dinonaktifkan, sama seperti di form.
            if (! ($person?->is($by) && ! $active)) {
                $data['is_active'] = $active;
            }
        }

        $roleNames = array_values(array_filter(array_map('trim', explode(',', $cells['roles']['value'] ?? ''))));
        foreach ($roleNames as $role) {
            if (! in_array($role, $roles, true)) {
                return $skip("Peran \"{$role}\" tidak ada di Settings → Roles.");
            }

            if ($role === 'super_admin' && ! $by->hasRole('super_admin')) {
                return $skip('Peran super_admin hanya bisa diberikan oleh super_admin.');
            }
        }

        if (! $person) {
            if (! $by->can('create', User::class)) {
                return $skip('Karyawan baru: butuh izin Create User di Settings → Roles.');
            }

            if (($data['firstname'] ?? '') === '' || ($data['email'] ?? '') === '') {
                return $skip('Karyawan baru: Nama dan Email wajib diisi.');
            }

            $data['lastname'] ??= '';

            return ['action' => 'create', 'data' => $data, 'roles' => $roleNames, 'changes' => ['Karyawan baru']] + $row;
        }

        if ($person->hasRole('super_admin') && ! $by->hasRole('super_admin')) {
            return $skip('Akun super_admin hanya bisa diubah super_admin.');
        }

        if (! $by->can('update', $person)) {
            return $skip('Butuh izin Update User di Settings → Roles.');
        }

        $changes = [];
        foreach ($data as $field => $value) {
            $old = $person->{$field};

            if ($field === 'is_active' ? (bool) $old === $value : (string) $old === (string) $value) {
                unset($data[$field]);

                continue;
            }

            if ($field !== 'nik') {
                $changes[] = (self::LABELS[$field] ?? $field) . ': ' . ($field === 'is_active'
                    ? ($old ? 'Aktif' : 'Nonaktif') . ' → ' . ($value ? 'Aktif' : 'Nonaktif')
                    : (filled($old) ? Str::limit((string) $old, 30) : '—') . ' → ' . Str::limit((string) $value, 30));
            }
        }

        $currentRoles = $person->getRoleNames()->sort()->values()->all();
        if ($roleNames && $currentRoles !== collect($roleNames)->sort()->values()->all()) {
            $changes[] = 'Peran: ' . (implode(', ', $currentRoles) ?: '—') . ' → ' . implode(', ', $roleNames);
        } else {
            $roleNames = [];
        }

        return [
            'action'  => $changes ? 'update' : 'same',
            'user_id' => $person->id,
            'name'    => trim("{$person->firstname} {$person->lastname}") ?: $row['name'],
            'data'    => $data,
            'roles'   => $roleNames,
            'changes' => $changes,
        ] + $row;
    }

    private function planComponents(Spreadsheet $book, User $by, array &$result): void
    {
        [$sheet, $header, $columns] = $this->findTable($book, self::COMPONENT_HEADERS, ['nik', 'name', 'amount']) ?? [null, null, null];

        if (! $sheet) {
            return;
        }

        if (! $result['salary']) {
            $result['notes'][] = 'Sheet Komponen Gaji diabaikan: butuh izin kelola gaji (Update Payslip).';

            return;
        }

        $known = collect($result['rows'])->whereIn('action', ['create', 'update', 'same'])->pluck('nik')->flip();

        foreach ($this->rows($sheet, $header, $columns) as $number => $cells) {
            $nik  = preg_replace('/\s+/', '', $cells['nik']['value'] ?? '');
            $name = trim($cells['name']['value'] ?? '');

            if ($nik === '' && $name === '') {
                continue;
            }

            $raw    = $cells['amount']['value'] ?? '';
            $amount = is_numeric($raw) ? (float) $raw : (float) (AmountParser::parse(ltrim($raw, '-')) ?? 0) * (str_starts_with(trim($raw), '-') ? -1 : 1);
            $type   = match (Str::lower(trim($cells['type']['value'] ?? ''))) {
                'potongan', 'deduction', 'pengurang' => PayrollComponent::DEDUCTION,
                'pendapatan', 'earning', 'tunjangan' => PayrollComponent::EARNING,
                default                              => $amount < 0 ? PayrollComponent::DEDUCTION : PayrollComponent::EARNING,
            };

            if ($name === '' || $amount == 0) {
                $result['notes'][] = "Komponen Gaji baris {$number}: nama komponen atau nominal kosong, dilewati.";

                continue;
            }

            if (! isset($known[$nik]) && ! User::where('nik', $nik)->exists()) {
                $result['notes'][] = "Komponen Gaji baris {$number}: NIK {$nik} tidak ada, dilewati.";

                continue;
            }

            $result['components'][$nik][] = ['name' => Str::limit($name, 100, ''), 'type' => $type, 'amount' => abs($amount)];
        }
    }

    // =================================================================
    // Membaca file
    // =================================================================

    /**
     * Sheet pertama yang punya baris judul berisi kolom $required.
     *
     * @return array{0: Worksheet, 1: int, 2: array<string, string>}|null  sheet, baris judul, kolom per nama
     */
    private function findTable(Spreadsheet $book, array $headers, array $required): ?array
    {
        foreach ($book->getWorksheetIterator() as $sheet) {
            $maxColumn = min(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);

            for ($row = 1; $row <= min(15, $sheet->getHighestDataRow()); $row++) {
                $columns = [];

                for ($col = 1; $col <= $maxColumn; $col++) {
                    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $label  = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower((string) $sheet->getCell("{$letter}{$row}")->getValue()))));

                    foreach ($headers as $key => $pattern) {
                        if (! isset($columns[$key]) && preg_match($pattern, $label)) {
                            $columns[$key] = $letter;
                        }
                    }
                }

                if (count(array_intersect_key($columns, array_flip($required))) === count($required)) {
                    return [$sheet, $row, $columns];
                }
            }
        }

        return null;
    }

    /**
     * Baris data di bawah judul: [nomor baris => [kolom => [value, numeric]]].
     * Baris kosong, "Total", dan "Tidak ada data" dilewati.
     */
    private function rows(Worksheet $sheet, int $header, array $columns): \Generator
    {
        $last = min($sheet->getHighestDataRow(), $header + self::MAX_ROWS);

        for ($row = $header + 1; $row <= $last; $row++) {
            $cells = [];

            foreach ($columns as $key => $letter) {
                $value = $sheet->getCell("{$letter}{$row}")->getValue();

                if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    $value = $value->getPlainText();
                }

                $numeric      = is_int($value) || is_float($value);
                $cells[$key] = ['value' => $numeric ? (floor($value) == $value ? sprintf('%.0f', $value) : (string) $value) : trim((string) $value), 'numeric' => $numeric];
            }

            $values = array_filter(array_column($cells, 'value'), fn ($value) => $value !== '');
            $first  = Str::lower((string) reset($values));

            if (! $values || in_array($first, ['total', 'tidak ada data'], true)) {
                continue;
            }

            yield $row => $cells;
        }
    }

    /** "Budi Santoso Putra" → ["Budi", "Santoso Putra"]. */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** Nomor HP yang tersimpan sebagai angka kehilangan 0 di depannya: 81234… → 081234… */
    private function phone(?array $cell): string
    {
        $value = trim($cell['value'] ?? '');

        return ($cell['numeric'] ?? false) && str_starts_with($value, '8') ? '0' . $value : $value;
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $columns
     * @param  array<int, string>  $textColumns  kolom yang diformat Teks (NIK, HP, NPWP)
     */
    private function templateSheet(Worksheet $sheet, string $title, array $columns, array $examples, array $textColumns): void
    {
        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', 'Isi mulai baris 6. Baris contoh boleh diganti atau dihapus. Petunjuk ada di sheet Petunjuk.');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::ACCENT);
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB(self::MUTED);

        foreach ($columns as $i => [$label, $width]) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$letter}" . self::HEADER_ROW, $label);
            $sheet->getColumnDimension($letter)->setWidth($width);
        }

        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle('A' . self::HEADER_ROW . ":{$last}" . self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ACCENT]],
        ]);

        foreach ($textColumns as $letter) {
            $sheet->getStyle("{$letter}" . (self::HEADER_ROW + 1) . ":{$letter}" . (self::HEADER_ROW + 1000))->getNumberFormat()->setFormatCode('@');
        }

        foreach ($examples as $r => $values) {
            foreach ($values as $i => $value) {
                $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . (self::HEADER_ROW + 1 + $r);
                is_int($value) || is_float($value)
                    ? $sheet->setCellValue($coordinate, $value)
                    : $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
            }
        }

        $sheet->freezePane('A' . (self::HEADER_ROW + 1));
    }
}
