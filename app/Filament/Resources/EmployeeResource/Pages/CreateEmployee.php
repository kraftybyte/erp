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
        $data['username'] = self::uniqueUsername($data);
        $data['password'] = Str::random(40);

        return $data;
    }

    /**
     * Username dari email (atau nama), unik di tabel users. Dipakai juga
     * Import Excel karyawan.
     */
    public static function uniqueUsername(array $data): string
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

        return $username;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
