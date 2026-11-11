<?php

namespace App\Support;

/**
 * Bandingkan kolom database sebuah situs dengan acuan
 * database/schema/expected-columns.php, lalu susun perbaikan yang aman:
 *
 * - kolom yang hilang ditambahkan (boleh kosong, nilai bawaan dari acuan);
 * - kolom wajib-isi tanpa nilai bawaan yang di acuan boleh kosong/punya bawaan,
 *   atau tidak dikenal kode sama sekali, dibuat boleh kosong (kalau tidak,
 *   simpan data baru gagal dengan "doesn't have a default value");
 * - enum yang di acuan berupa teks, dan varchar yang lebih pendek dari acuan,
 *   diperlebar (tanpa kehilangan data).
 *
 * Perbedaan tipe lain hanya dilaporkan. Tidak ada kolom atau data yang dihapus.
 *
 * Kelas PHP murni (tanpa Laravel) supaya `migrate-cwp.sh check` bisa memakainya
 * dari kode yang baru diunduh, sebelum composer dijalankan.
 */
final class SchemaDrift
{
    /**
     * Baris information_schema.COLUMNS (MySQL atau MariaDB) menjadi
     * [tabel => [kolom => [type, nullable, default?, auto?, primary?]]].
     *
     * @param  iterable<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string, 6: ?string}>  $rows
     *         TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
     */
    public static function siteColumns(iterable $rows): array
    {
        $site = [];

        foreach ($rows as [$table, $column, $type, $nullable, $default, $extra, $key]) {
            $site[$table][$column] = array_filter([
                'type'     => strtolower($type),
                'nullable' => strtoupper($nullable) === 'YES',
                'default'  => self::defaultValue($default),
                'auto'     => str_contains(strtolower((string) $extra), 'auto_increment') ?: null,
                'primary'  => strtoupper((string) $key) === 'PRI' ?: null,
            ], fn ($v) => $v !== null);
        }

        return $site;
    }

    /**
     * @return array{statements: array<string, list<string>>, added: list<string>, relaxed: list<string>, widened: list<string>, notes: list<string>}
     */
    public static function plan(array $site, array $expected): array
    {
        $plan = ['statements' => [], 'added' => [], 'relaxed' => [], 'widened' => [], 'notes' => []];

        foreach ($site as $table => $columns) {
            $wanted = $expected[$table] ?? null;

            if ($wanted === null) {
                continue; // tabel di luar acuan (milik situs itu sendiri)
            }

            foreach ($wanted as $column => $want) {
                if (isset($columns[$column]) || ! empty($want['auto'])) {
                    continue;
                }

                $plan['statements'][$table][] = sprintf('ADD COLUMN `%s` %s NULL%s', $column, $want['type'], self::defaultClause($want['default'] ?? null));
                $plan['added'][] = "{$table}.{$column} ({$want['type']})";
            }

            foreach ($columns as $column => $have) {
                if (! empty($have['auto']) || ! empty($have['primary'])) {
                    continue;
                }

                $want  = $wanted[$column] ?? null;
                $relax = ! $have['nullable'] && ! array_key_exists('default', $have)
                    && ($want === null || $want['nullable'] || array_key_exists('default', $want));
                $widen = $want !== null && self::widens(self::normalize($have['type']), self::normalize($want['type']));

                if (! $relax && ! $widen) {
                    if ($want !== null && self::normalize($have['type']) !== self::normalize($want['type'])) {
                        $plan['notes'][] = "{$table}.{$column}: {$have['type']} (acuan {$want['type']})";
                    }

                    continue;
                }

                $type     = $widen ? $want['type'] : $have['type'];
                $nullable = $have['nullable'] || $relax;
                $default  = $have['default'] ?? ($relax ? ($want['default'] ?? null) : null);

                $plan['statements'][$table][] = sprintf('MODIFY COLUMN `%s` %s %s%s', $column, $type, $nullable ? 'NULL' : 'NOT NULL', self::defaultClause($default));

                if ($relax) {
                    $plan['relaxed'][] = "{$table}.{$column}";
                }

                if ($widen) {
                    $plan['widened'][] = "{$table}.{$column}: {$have['type']} → {$want['type']}";
                }
            }
        }

        return $plan;
    }

    /** Tipe tanpa lebar tampilan integer (MariaDB: bigint(20)), json = longtext. */
    public static function normalize(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);
        $type = preg_replace('/^integer\b/', 'int', $type);

        return $type === 'json' ? 'longtext' : $type;
    }

    /** Pelebaran tanpa kehilangan data: enum → teks, varchar(n) → varchar(m > n). */
    private static function widens(string $have, string $want): bool
    {
        if (str_starts_with($have, 'enum(') && preg_match('/^(varchar|char|text|mediumtext|longtext)\b/', $want)) {
            return true;
        }

        return preg_match('/^varchar\((\d+)\)$/', $have, $h) && preg_match('/^varchar\((\d+)\)$/', $want, $w) && (int) $h[1] < (int) $w[1];
    }

    /** MariaDB menulis nilai bawaan sebagai ekspresi SQL ('teks', NULL); MySQL apa adanya. */
    private static function defaultValue(?string $default): ?string
    {
        if ($default === null || strtoupper($default) === 'NULL') {
            return null;
        }

        if (strlen($default) >= 2 && $default[0] === "'" && str_ends_with($default, "'")) {
            return str_replace("''", "'", substr($default, 1, -1));
        }

        // Ekspresi (current_timestamp(), dsb.) tidak disalin.
        return preg_match('/^[a-z_]+\(.*\)$/i', $default) ? null : $default;
    }

    private static function defaultClause(?string $default): string
    {
        return $default === null ? '' : " DEFAULT '" . str_replace(['\\', "'"], ['\\\\', "''"], $default) . "'";
    }
}
