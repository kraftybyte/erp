<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'deal_id' => ['nullable', 'exists:deals,id'],
            'nama_project' => ['required', 'string', 'max:255'],
            'detail_project' => ['nullable', 'string', 'max:5000'],
            'timeline_mulai' => ['required', 'date', 'date_format:Y-m-d'],
            'timeline_selesai' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:timeline_mulai'],
            'nama_perusahaan' => ['nullable', 'string', 'max:500'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'nama_pic' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'product_id' => ['nullable', 'exists:products,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'deal_id.exists' => 'Deal tidak valid.',
            'nama_project.required' => 'Nama project harus diisi.',
            'timeline_mulai.required' => 'Tanggal mulai harus diisi.',
            'timeline_mulai.date_format' => 'Format tanggal mulai tidak valid.',
            'timeline_selesai.required' => 'Tanggal selesai harus diisi.',
            'timeline_selesai.date_format' => 'Format tanggal selesai tidak valid.',
            'timeline_selesai.after_or_equal' => 'Tanggal selesai harus setelah tanggal mulai.',
            'detail_project.max' => 'Detail project maksimal 5000 karakter.',
            'product_id.exists' => 'Produk tidak valid.',
        ];
    }
}
