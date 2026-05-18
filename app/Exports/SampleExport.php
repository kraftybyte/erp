<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class SampleExport implements FromCollection, WithHeadings
{
    /**
     * Menentukan header kolom di file Excel.
     */
    public function headings(): array
    {
        return [
            'nama_pic',
            'nama_perusahaan',
            'email',
            'telephone',
            'source',
            'alamat',
            'NPWP',
            'notes',
            'status',
            'product_id'
        ];
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return new Collection([
            [
                'nama_pic' => 'John Doe',
                'nama_perusahaan' => 'PT. Contoh Sukses',
                'email' => 'john@contoh.com',
                'telephone' => '08123456789',
                'source' => 'Website',
                'alamat' => 'Jl. Contoh No. 123, Jakarta',
                'NPWP' => '123456789012345',
                'notes' => 'Pelanggan potensial',
                'status' => 'Negosiasi',
                'product_id' => 1,
            ],
            [
                'nama_pic' => 'Jane Doe',
                'nama_perusahaan' => 'CV. Maju Jaya',
                'email' => 'jane@majujaya.com',
                'telephone' => '08987654321',
                'source' => 'Referral',
                'alamat' => 'Jl. Sukses No. 456, Bandung',
                'NPWP' => null,
                'notes' => 'Follow up dalam 2 minggu',
                'status' => 'Menunggu Pembayaran',
                'product_id' => 2,
            ],
        ]);
    }
}
