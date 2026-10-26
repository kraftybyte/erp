<?php

namespace App\Helpers;

use Illuminate\Database\Eloquent\Builder;

/**
 * Pencarian nominal di tabel Filament: "Rp 159.920.500", "159.920.500,00",
 * "159920500", atau sebagian angkanya.
 */
class AmountSearch
{
    /**
     * Angka dari satu kata pencarian: "Rp79.960.250" dan "79.960.250,00"
     * menjadi "79960250". Null bila kata itu bukan nominal (nama perusahaan,
     * nomor invoice, dll).
     */
    public static function digits(string $word): ?string
    {
        $word = preg_replace('/^rp\.?/i', '', trim($word));
        $word = preg_replace('/,\d{1,2}$/', '', $word);

        return preg_match('/^\d[\d.,]*$/', $word)
            ? str_replace(['.', ','], '', $word)
            : null;
    }

    /**
     * Isi callback searchable(query: ...) untuk kolom nominal.
     */
    public static function apply(Builder $query, string $search, string $column): void
    {
        // Filament mencari per kata dan setiap kata harus cocok;
        // "Rp" pada "Rp 79.960.250" bukan syarat pencarian.
        if (preg_match('/^rp\.?$/i', $search)) {
            $query->whereRaw('1 = 1');

            return;
        }

        $digits = self::digits($search);

        if ($digits !== null) {
            $query->whereRaw('CAST(ROUND(' . $query->getGrammar()->wrap($column) . ') AS CHAR) LIKE ?', ["%{$digits}%"]);
        }
    }
}
