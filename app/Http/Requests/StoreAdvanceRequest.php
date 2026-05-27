<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'exists:projects,id'],
            'submission_date' => ['required', 'date', 'before_or_equal:today'],
            'keterangan_advance' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'min:100', 'max:1000000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.exists' => 'Project tidak valid.',
            'submission_date.required' => 'Tanggal pengajuan harus diisi.',
            'submission_date.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'keterangan_advance.required' => 'Keterangan advance harus diisi.',
            'keterangan_advance.max' => 'Keterangan maksimal 1000 karakter.',
            'amount.required' => 'Nominal harus diisi.',
            'amount.numeric' => 'Nominal harus berupa angka.',
            'amount.min' => 'Nominal minimal Rp 100.',
            'amount.max' => 'Nominal maksimal Rp 1 Milyar.',
        ];
    }
}
