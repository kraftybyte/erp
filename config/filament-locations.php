<?php

return [
    /**
     * ----------------------------
     * Data Driver
     * ----------------------------
     *
     * json: data dari file paket lewat Sushi (SQLite sementara). Di server
     * dengan SQLite lama (batas 999 variabel, mis. CWP erp.getkrafty.id),
     * Sushi gagal "too many SQL variables" saat mengisi 157 mata uang.
     *
     * database (bawaan di sini): tabel MySQL currencies, countries, … —
     * currencies diisi migrasi 2026_11_11_160000 dengan id yang sama.
     */
    'driver' => env('FILAMENT_LOCATIONS_DRIVER', 'database'),

    'json' => [
        'areas'      => null,
        'cities'     => null,
        'countries'  => null,
        'currencies' => null,
        'languages'  => null,
    ],
];
