<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'nama_pic' => ['required', 'string', 'max:255'],
            'nama_perusahaan' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:leads,email' . ($this->route('lead') ? ',' . $this->route('lead')->id : '')],
            'telephone' => ['required', 'string', 'max:20', 'regex:/^[+]?[0-9\s\-\(\)]{6,20}$/'],
            'source' => ['required', 'in:Website,Social Media,Email Marketing,Event,Referral,Partner'],
            'status' => ['required', 'in:Dalam Komunikasi,Proposal Sudah Dikirim,Menang,Kalah,Converted'],
            'product_id' => ['nullable', 'array'],
            'product_id.*' => ['exists:products,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_pic.required' => 'Nama PIC harus diisi.',
            'nama_perusahaan.required' => 'Nama perusahaan harus diisi.',
            'email.required' => 'Email harus diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah terdaftar.',
            'telephone.required' => 'Nomor telepon harus diisi.',
            'telephone.regex' => 'Format nomor telepon tidak valid.',
            'source.required' => 'Source harus dipilih.',
            'source.in' => 'Source tidak valid.',
            'status.required' => 'Status harus dipilih.',
            'status.in' => 'Status tidak valid.',
            'product_id.*.exists' => 'Produk tidak valid.',
        ];
    }
}
