<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'requested_by' => ['required', 'exists:users,id'],
            'item_name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string', 'max:1000'],
            'reference_link' => ['nullable', 'url', 'max:500'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:Pending,Disetujui,Ditolak'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'requested_by.required' => 'User ID harus diisi.',
            'requested_by.exists' => 'User tidak valid.',
            'item_name.required' => 'Nama barang harus diisi.',
            'quantity.required' => 'Jumlah harus diisi.',
            'quantity.numeric' => 'Jumlah harus berupa angka.',
            'quantity.min' => 'Jumlah minimal 1.',
            'reference_link.url' => 'Format link tidak valid.',
            'price.numeric' => 'Harga harus berupa angka.',
        ];
    }
}
