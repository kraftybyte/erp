<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $rules = [
            'category' => ['required', 'in:cuti,wfa,wfh,sakit,tukar_hari,izin'],
            'description' => ['nullable', 'string', 'max:500'],
            'start_time' => ['required', 'date', 'after_or_equal:today'],
            'end_time' => ['nullable', 'date', 'after_or_equal:start_time'],
            'approval_status' => ['nullable', 'in:pending,approved,rejected'],
            'sick_letter' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'work_report' => ['nullable', 'string', 'max:2000'],
        ];

        // Sick letter required for sakit category
        if ($this->category === 'sakit') {
            $rules['sick_letter'] = ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Kategori harus dipilih.',
            'category.in' => 'Kategori tidak valid.',
            'start_time.required' => 'Tanggal mulai harus diisi.',
            'start_time.date' => 'Format tanggal mulai tidak valid.',
            'start_time.after_or_equal' => 'Tanggal mulai tidak boleh sebelum hari ini.',
            'end_time.after_or_equal' => 'Tanggal selesai harus setelah tanggal mulai.',
            'sick_letter.required' => 'Surat dokter harus diupload untuk kategori sakit.',
            'sick_letter.file' => 'File tidak valid.',
            'sick_letter.mimes' => 'Format file harus JPG, PNG, atau PDF.',
            'sick_letter.max' => 'Ukuran file maksimal 2MB.',
        ];
    }
}
