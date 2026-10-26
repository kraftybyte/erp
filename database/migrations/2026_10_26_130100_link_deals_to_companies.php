<?php

use App\Services\CompanyDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buat Perusahaan & PIC dari Client (deals) yang sudah ada, lalu tautkan.
 *
 * Hanya menambah: membuat baris companies/company_contacts dan mengisi
 * deals.company_id/contact_id. Kolom teks deals (nama_perusahaan, nama_pic,
 * telepon, dst.) dan updated_at-nya tidak diubah.
 *
 * Nama yang sama persis — hanya beda huruf besar/kecil atau spasi — menjadi
 * satu perusahaan. Variasi lain ("PT." vs "PT", tanda baca) sengaja dibiarkan
 * terpisah: salah gabung lebih berbahaya daripada dobel. Variasi itu muncul di
 * filter "Dugaan duplikat" pada menu Perusahaan untuk digabungkan sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        $directory = app(CompanyDirectory::class);

        // Terbaru dulu: penulisan nama, alamat, dan kontak mengikuti Client terakhir.
        DB::table('deals')
            ->whereNull('company_id')
            ->whereNotNull('nama_perusahaan')
            ->where('nama_perusahaan', '!=', '')
            ->lazyByIdDesc(200)
            ->each(function (object $deal) use ($directory) {
                $company = $directory->company($deal->nama_perusahaan, ['alamat' => $deal->alamat, 'npwp' => $deal->NPWP]);

                $contact = CompanyDirectory::isPerson($deal->nama_pic)
                    ? $directory->contact($company, $deal->nama_pic, ['telephone' => $deal->telephone, 'email' => $deal->email])
                    : null;

                DB::table('deals')->where('id', $deal->id)->update([
                    'company_id' => $company->id,
                    'contact_id' => $contact?->id,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('deals')->update(['company_id' => null, 'contact_id' => null]);
        DB::table('company_contacts')->delete();
        DB::table('companies')->update(['merged_into_id' => null]);
        DB::table('companies')->delete();
    }
};
