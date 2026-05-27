<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'exists:projects,id'],
            'submission_date' => ['required', 'date', 'before_or_equal:today'],
            'keterangan_expense' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'min:100', 'max:1000000000'],
            // Files
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'attachment_pay' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => 'Project harus dipilih.',
            'project_id exists' => 'Project tidak valid.',
            'submission_date.required' => 'Tanggal pengajuan harus diisi.',
            'submission_date.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'keterangan_expense.required' => 'Keterangan expense harus diisi.',
            'keterangan_expense.max' => 'Keterangan maksimal 1000 karakter.',
            'amount.required' => 'Nominal harus diisi.',
            'amount.numeric' => 'Nominal harus berupa angka.',
            'amount.min' => 'Nominal minimal Rp 100.',
            'amount.max' => 'Nominal maksimal Rp 1 Milyar.',
            'attachment.mimes' => 'Format file harus JPG, PNG, WebP, atau PDF.',
            'attachment.max' => 'Ukuran file maksimal 5MB.',
        ];
    }
}
