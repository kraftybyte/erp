<?php

namespace App\Services;

use App\Models\PayrollComponent;
use App\Models\User;
use App\Services\Concerns\WritesExcelTables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Excel data karyawan dari tabel HR → Employee (baris yang sedang tampil:
 * filter, pencarian, urutan). Kolom gaji dan lembar Komponen Gaji hanya untuk
 * yang boleh mengelola gaji (izin Shield update_payslip).
 */
class EmployeeExport
{
    use WritesExcelTables;

    /**
     * @param  array<int, string>  $filters  keterangan filter yang aktif di tabel
     * @return string path file sementara; pemanggil yang menghapusnya
     */
    public function employees(Builder $query, array $filters = []): string
    {
        $salary    = Payroll::canManage(Auth::user());
        $employees = $query->with(['roles', 'payrollComponents'])->get();
        $sum       = fn (User $user, string $type) => (float) $user->payrollComponents->where('type', $type)->sum('amount');

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Karyawan');

        $columns = [
            ['NIK', 20, 'text'],
            ['Nama', 24, 'text'],
            ['Email', 28, 'text'],
            ['Nomor HP', 16, 'text'],
            ['Alamat', 36, 'wrap'],
            ['Jabatan', 14, 'text'],
            ['Divisi', 22, 'text'],
            ['NPWP', 22, 'text'],
            ['Peran', 16, 'text'],
            ['Status', 10, 'text'],
            ['Telegram', 12, 'text'],
            ['Bergabung', 13, 'date'],
        ];

        if ($salary) {
            $columns[] = ['Pendapatan / bulan', 18, 'money'];
            $columns[] = ['Potongan / bulan', 16, 'money'];
            $columns[] = ['Take home pay', 18, 'money'];
        }

        $this->heading($sheet, 'Data Karyawan', $filters, $employees->count() . ' karyawan');
        $this->dataTable($sheet, $columns, $employees->map(fn (User $user) => [
            $user->nik,
            $this->name($user),
            $user->email,
            $user->phone,
            $user->address,
            $user->position,
            $user->division,
            $user->npwp,
            $user->roles->pluck('name')->implode(', '),
            $user->is_active ? 'Aktif' : 'Nonaktif',
            $user->telegram_chat_id ? 'Terhubung' : '-',
            $user->created_at,
            ...($salary ? [
                $sum($user, PayrollComponent::EARNING) ?: null,
                $sum($user, PayrollComponent::DEDUCTION) ?: null,
                $user->payrollComponents->isNotEmpty() ? $sum($user, PayrollComponent::EARNING) - $sum($user, PayrollComponent::DEDUCTION) : null,
            ] : []),
        ])->all());

        if ($salary) {
            $components = $book->createSheet()->setTitle('Komponen Gaji');

            $this->heading($components, 'Komponen Gaji per Karyawan', $filters, $employees->sum(fn (User $user) => $user->payrollComponents->count()) . ' komponen');
            $this->dataTable($components, [
                ['NIK', 20, 'text'],
                ['Nama', 24, 'text'],
                ['Komponen', 30, 'text'],
                ['Jenis', 12, 'text'],
                ['Nominal / bulan', 18, 'money'],
            ], $employees->flatMap(fn (User $user) => $user->payrollComponents->map(fn (PayrollComponent $component) => [
                $user->nik,
                $this->name($user),
                $component->name,
                PayrollComponent::TYPES[$component->type] ?? $component->type,
                // Potongan bertanda minus supaya total kolom = total take home pay.
                $component->type === PayrollComponent::DEDUCTION ? -(float) $component->amount : (float) $component->amount,
            ]))->all());
        }

        return $this->save($book);
    }
}
