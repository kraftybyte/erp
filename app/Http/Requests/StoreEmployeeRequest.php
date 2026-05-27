<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasRole(['Director', 'HR', 'super_admin']);
    }

    public function rules(): array
    {
        $userId = $this->route('employee')?->user_id;

        return [
            'username' => ['required', 'string', 'max:255', 'unique:users,username' . ($userId ? ',' . $userId : '')],
            'email' => ['required', 'email', 'max:255', 'unique:users,email' . ($userId ? ',' . $userId : '')],
            'password' => [$this->is('*create') ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
            'firstname' => ['required', 'string', 'max:100'],
            'lastname' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[+]?[0-9\s\-\(\)]{6,20}$/'],
            'position' => ['nullable', 'string', 'max:100'],
            'division' => ['required', 'in:Social Media Specialist,Finance,HR,Graphic Design,Video Editor'],
            'nik' => ['nullable', 'string', 'max:50', 'unique:employees,nik' . ($this->route('employee') ? ',' . $this->route('employee')->id : '')],
            'npwp' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'ktp' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'file_npwp' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username harus diisi.',
            'username.unique' => 'Username sudah digunakan.',
            'email.required' => 'Email harus diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan.',
            'password.required' => 'Password harus diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'firstname.required' => 'Nama depan harus diisi.',
            'division.required' => 'Divisi harus dipilih.',
            'division.in' => 'Divisi tidak valid.',
            'nik.unique' => 'NIK sudah terdaftar.',
            'phone.regex' => 'Format nomor telepon tidak valid.',
            'ktp.mimes' => 'Format file harus JPG, PNG, atau PDF.',
            'ktp.max' => 'Ukuran file maksimal 2MB.',
            'file_npwp.mimes' => 'Format file harus JPG, PNG, atau PDF.',
            'file_npwp.max' => 'Ukuran file maksimal 2MB.',
        ];
    }
}
