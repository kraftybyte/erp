<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Data induk perusahaan dan PIC-nya.
 *
 * Satu perusahaan bisa punya banyak PIC; setiap Client (Deal) menunjuk
 * perusahaan dan PIC untuk order itu. Teks nama_perusahaan/nama_pic di Deal
 * tetap diisi dari sini, sehingga quotation, invoice, PDF, dan bot yang
 * membacanya tidak perlu berubah.
 */
class CompanyDirectory
{
    // Isi kolom PIC yang berarti "tanpa PIC" (klien pribadi).
    private const NO_PIC = ['', '-', 'pribadi', '(pribadi)'];

    /**
     * Kunci pencocokan nama: huruf kecil, spasi dirapatkan. Variasi lain
     * ("PT." vs "PT", tanda baca) sengaja tidak disamakan — salah gabung lebih
     * berbahaya daripada dobel; variasi itu ditangkap looseKey().
     */
    public static function key(?string $name): string
    {
        return mb_strtolower(self::clean($name));
    }

    /**
     * Kunci longgar untuk menandai dugaan duplikat: tanpa PT/CV/UD/Tbk dan
     * tanpa tanda baca, jadi "PT.ORGANIK SEHAT" dan "PT Organik Sehat" sama.
     */
    public static function looseKey(?string $name): string
    {
        $name = preg_replace('/\b(pt|cv|ud|tbk|persero)\b/u', ' ', mb_strtolower((string) $name));

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
    }

    public static function isPerson(?string $pic): bool
    {
        return ! in_array(self::key($pic), self::NO_PIC, true);
    }

    /**
     * Perusahaan bernama $name — yang sudah digabung diikuti ke tujuannya —
     * atau dibuat baru. $details (alamat, npwp) hanya mengisi kolom yang kosong.
     */
    public function company(string $name, array $details = []): Company
    {
        $found = Company::withTrashed()
            ->where('name_key', self::key($name))
            ->orderByRaw('deleted_at IS NULL DESC')
            ->first();

        $company = $this->follow($found);

        return $company
            ? $this->fillBlanks($company, $details)
            : Company::create(['name' => self::clean($name)] + self::meaningful($details));
    }

    /**
     * PIC bernama $name di perusahaan ini, atau dibuat baru. $details
     * (jabatan, telephone, email) hanya mengisi kolom yang kosong.
     */
    public function contact(Company $company, string $name, array $details = []): CompanyContact
    {
        $found = CompanyContact::withTrashed()
            ->where('company_id', $company->id)
            ->where('name_key', self::key($name))
            ->orderByRaw('deleted_at IS NULL DESC')
            ->first();

        $contact = $this->follow($found);

        return $contact
            ? $this->fillBlanks($contact, $details)
            : $company->contacts()->create(['name' => self::clean($name)] + self::meaningful($details));
    }

    /**
     * Dipanggil setiap kali Deal disimpan.
     *
     * Deal yang dibuat dari teks (konversi Leads, import Excel, import Meta)
     * ditautkan ke perusahaan & PIC yang namanya cocok, atau yang baru. Setelah
     * tertaut, teks nama_perusahaan/nama_pic selalu mengikuti datanya.
     */
    public function syncDeal(Deal $deal): void
    {
        if (! $deal->company_id) {
            if (blank($deal->nama_perusahaan)) {
                return;
            }

            $company = $this->company($deal->nama_perusahaan, ['alamat' => $deal->alamat, 'npwp' => $deal->NPWP]);

            $deal->company_id = $company->id;
            $deal->contact_id ??= self::isPerson($deal->nama_pic)
                ? $this->contact($company, $deal->nama_pic, ['telephone' => $deal->telephone, 'email' => $deal->email])->id
                : null;
        }

        $deal->unsetRelation('company')->unsetRelation('contact');

        $deal->nama_perusahaan = $deal->company?->name ?? $deal->nama_perusahaan;
        $deal->nama_pic        = $deal->contact?->name ?? '-';
    }

    /**
     * Gabungkan $from ke $into: PIC dan Client-nya pindah, PIC yang namanya
     * sama dijadikan satu. $from dihapus (soft delete) dan diingat sebagai
     * nama lain $into, sehingga ketikan nama lama berikutnya masuk ke $into.
     */
    public function merge(Company $from, Company $into): void
    {
        if ($from->is($into)) {
            throw new InvalidArgumentException('Perusahaan tidak bisa digabung ke dirinya sendiri.');
        }

        DB::transaction(function () use ($from, $into) {
            Deal::where('company_id', $from->id)->update(['company_id' => $into->id, 'nama_perusahaan' => $into->name]);

            foreach ($from->contacts as $contact) {
                $same = $into->contacts()->where('name_key', $contact->name_key)->first();

                $same
                    ? $this->mergeContact($contact, $same)
                    : $contact->update(['company_id' => $into->id]);
            }

            $this->fillBlanks($into, ['alamat' => $from->alamat, 'npwp' => $from->npwp]);
            $this->retire($from, $into);
        });
    }

    /**
     * Gabungkan PIC $from ke $into; Client-nya pindah ke $into.
     */
    public function mergeContact(CompanyContact $from, CompanyContact $into): void
    {
        if ($from->is($into)) {
            throw new InvalidArgumentException('PIC tidak bisa digabung ke dirinya sendiri.');
        }

        DB::transaction(function () use ($from, $into) {
            Deal::where('contact_id', $from->id)->update([
                'contact_id'      => $into->id,
                'company_id'      => $into->company_id,
                'nama_perusahaan' => $into->company->name,
                'nama_pic'        => $into->name,
            ]);

            $this->fillBlanks($into, ['jabatan' => $from->jabatan, 'telephone' => $from->telephone, 'email' => $from->email]);
            $this->retire($from, $into);
        });
    }

    /**
     * Id perusahaan yang namanya mirip perusahaan lain (looseKey sama),
     * berurutan per kelompok supaya yang mirip tampil berdampingan.
     *
     * @return array<int, int>
     */
    public function suspectedDuplicateIds(): array
    {
        return Company::query()
            ->get(['id', 'name'])
            ->groupBy(fn (Company $company) => self::looseKey($company->name))
            ->filter(fn ($group, $key) => $key !== '' && $group->count() > 1)
            ->sortKeys()
            ->flatten()
            ->pluck('id')
            ->all();
    }

    /**
     * Isian yang benar-benar berisi: tanpa kosong, "-", dan email placeholder import Meta.
     *
     * @return array<string, string>
     */
    private static function meaningful(array $details): array
    {
        return array_filter(
            array_map(fn ($value) => trim((string) $value), $details),
            fn (string $value) => $value !== '' && $value !== '-' && ! str_ends_with($value, '@placeholder.com')
        );
    }

    private static function clean(?string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $name));
    }

    /**
     * Ikuti rantai penggabungan ke data yang masih aktif.
     *
     * @template T of Model
     * @param  T|null  $model
     * @return T|null
     */
    private function follow(?Model $model): ?Model
    {
        for ($hops = 0; $model?->trashed() && $hops < 10; $hops++) {
            $model = $model->merged_into_id ? $model::withTrashed()->find($model->merged_into_id) : null;
        }

        return $model?->trashed() ? null : $model;
    }

    /**
     * @template T of Model
     * @param  T  $model
     * @return T
     */
    private function fillBlanks(Model $model, array $details): Model
    {
        foreach (self::meaningful($details) as $column => $value) {
            if (blank($model->{$column})) {
                $model->{$column} = $value;
            }
        }

        if ($model->isDirty()) {
            $model->save();
        }

        return $model;
    }

    private function retire(Model $from, Model $into): void
    {
        $from->merged_into_id = $into->id;
        $from->save();
        $from->delete();
    }
}
