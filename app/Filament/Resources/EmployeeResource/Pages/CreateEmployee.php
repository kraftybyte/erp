<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Models\User;
use Illuminate\Support\Str;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Form karyawan tidak punya username & password, padahal keduanya wajib di
     * tabel users — dulu setiap "Tambah karyawan" gagal. Username dibuat dari
     * email, password acak; karyawan mengatur passwordnya lewat "Lupa password"
     * di halaman login bila perlu masuk ERP.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $base = Str::of(Str::before((string) ($data['email'] ?? ''), '@'))
            ->whenEmpty(fn () => Str::of(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? '')))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '.')
            ->trim('.')
            ->limit(40, '')
            ->whenEmpty(fn () => Str::of('karyawan'))
            ->toString();

        $username = $base;
        for ($i = 2; User::where('username', $username)->exists(); $i++) {
            $username = "{$base}{$i}";
        }

        $data['username'] = $username;
        $data['password'] = Str::random(40);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
