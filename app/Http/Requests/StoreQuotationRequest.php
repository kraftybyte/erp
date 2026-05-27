<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'nama_perusahaan' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:20', 'regex:/^[+]?[0-9\s\-\(\)]{6,20}$/'],
            'nama_pic' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'date' => ['required', 'date', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:date'],
            'status' => ['nullable', 'in:Pending,Approved,Rejected,Converted'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item' => ['required', 'string', 'max:500'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.qty' => ['required', 'numeric', 'min:1', 'max:9999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.vat_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_perusahaan.required' => 'Nama perusahaan harus diisi.',
            'email.email' => 'Format email tidak valid.',
            'telephone.regex' => 'Format nomor telepon tidak valid.',
            'date.required' => 'Tanggal quotation harus diisi.',
            'date.date_format' => 'Format tanggal quotation tidak valid.',
            'due_date.required' => 'Tanggal jatuh tempo harus diisi.',
            'due_date.date_format' => 'Format tanggal jatuh tempo tidak valid.',
            'due_date.after_or_equal' => 'Tanggal jatuh tempo harus setelah tanggal quotation.',
            'items.required' => 'Minimal harus ada 1 item.',
            'items.min' => 'Minimal harus ada 1 item.',
            'items.max' => 'Maksimal 50 item.',
            'items.*.item.required' => 'Nama item harus diisi.',
            'items.*.item.max' => 'Nama item maksimal 500 karakter.',
            'items.*.price.required' => 'Harga harus diisi.',
            'items.*.price.numeric' => 'Harga harus berupa angka.',
            'items.*.price.max' => 'Harga terlalu besar.',
            'items.*.qty.required' => 'Jumlah harus diisi.',
            'items.*.qty.min' => 'Jumlah minimal 1.',
            'items.*.qty.max' => 'Jumlah maksimal 9999.',
        ];
    }
}
