<?php

namespace App\Imports;

use App\Models\Deal;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Validators\Failure;

class DealImport implements ToModel, WithHeadingRow, WithValidation
{
    private array $errors = [];

    public function model(array $row): Deal
    {
        $data = [
            'nama_pic' => $row['nama_pic'] ?? null,
            'nama_perusahaan' => $row['nama_perusahaan'] ?? null,
            'email' => $row['email'] ?? null,
            'telephone' => $row['telephone'] ?? null,
            'source' => $row['source'] ?? null,
            'alamat' => $row['alamat'] ?? null,
            'NPWP' => $row['NPWP'] ?? null,
            'notes' => $row['notes'] ?? null,
            'status' => $row['status'] ?? null,
        ];

        $productId = $row['product_id'] ?? null;

        $deal = Deal::create($data);

        if ($productId) {
            $deal->product()->attach((int) $productId);
        }

        return $deal;
    }

    public function rules(): array
    {
        return [
            'nama_pic' => 'required',
            'nama_perusahaan' => 'required',
            'email' => 'nullable|email',
            'telephone' => 'nullable',
            'source' => 'nullable',
            'alamat' => 'nullable',
            'NPWP' => 'nullable',
            'notes' => 'nullable',
            'status' => 'nullable',
            'product_id' => 'nullable|integer',
        ];
    }

    public function onFailure(Failure ...$failures): void
    {
        foreach ($failures as $failure) {
            $this->errors[] = $failure->errors();
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function headingRow(): int
    {
        return 1;
    }
}