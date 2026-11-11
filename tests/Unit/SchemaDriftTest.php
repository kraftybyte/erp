<?php

namespace Tests\Unit;

use App\Support\SchemaDrift;
use PHPUnit\Framework\TestCase;

/**
 * Pembanding kolom database situs (format MariaDB/MySQL) dengan acuan:
 * menambah kolom hilang, membuat kolom lama boleh kosong, melebarkan tipe.
 */
class SchemaDriftTest extends TestCase
{
    private const EXPECTED = [
        'invoices' => [
            'id'        => ['type' => 'bigint unsigned', 'nullable' => false, 'auto' => true],
            'uuid'      => ['type' => 'varchar(255)', 'nullable' => false],
            'for_type'  => ['type' => 'varchar(255)', 'nullable' => true],
            'for_id'    => ['type' => 'bigint unsigned', 'nullable' => true],
            'user_id'   => ['type' => 'bigint unsigned', 'nullable' => false],
            'status'    => ['type' => 'varchar(255)', 'nullable' => true, 'default' => 'pending'],
            'paid'      => ['type' => 'double', 'nullable' => false, 'default' => '0'],
            'type'      => ['type' => "enum('full','dp')", 'nullable' => false, 'default' => 'full'],
            'notes'     => ['type' => 'text', 'nullable' => true],
        ],
    ];

    public function test_rencana_untuk_tabel_lama_mariadb(): void
    {
        // Baris information_schema gaya MariaDB: lebar integer, nilai bawaan berkutip, NULL sebagai teks.
        $site = SchemaDrift::siteColumns([
            ['invoices', 'id', 'bigint(20) unsigned', 'NO', null, 'auto_increment', 'PRI'],
            ['invoices', 'uuid', 'varchar(50)', 'NO', null, '', 'UNI'],
            ['invoices', 'user_id', 'bigint(20) unsigned', 'NO', null, '', 'MUL'],
            ['invoices', 'status', "enum('pending','paid')", 'NO', "'pending'", '', ''],
            ['invoices', 'paid', 'double', 'NO', null, '', ''],
            ['invoices', 'type', "enum('full','dp')", 'NO', "'full'", '', ''],
            ['invoices', 'kode_lama', 'varchar(20)', 'NO', null, '', ''],
            ['invoices', 'notes', 'text', 'YES', 'NULL', '', ''],
            ['tabel_situs', 'x', 'int(11)', 'NO', null, '', ''],
        ]);

        $this->assertSame(['type' => 'text', 'nullable' => true], $site['invoices']['notes'], 'NULL sebagai teks = tanpa nilai bawaan.');
        $this->assertSame('pending', $site['invoices']['status']['default'], 'Kutip MariaDB dibuang.');

        $plan = SchemaDrift::plan($site, self::EXPECTED);

        $this->assertSame(['invoices.for_type (varchar(255))', 'invoices.for_id (bigint unsigned)'], $plan['added']);
        $this->assertSame(['invoices.paid', 'invoices.kode_lama'], $plan['relaxed'], 'user_id dan uuid memang wajib dan diisi kode: tidak diubah.');
        $this->assertSame(["invoices.uuid: varchar(50) → varchar(255)", "invoices.status: enum('pending','paid') → varchar(255)"], $plan['widened']);
        $this->assertSame([], $plan['notes'], 'bigint(20) unsigned sama dengan bigint unsigned.');
        $this->assertArrayNotHasKey('tabel_situs', $plan['statements'], 'Tabel di luar acuan tidak disentuh.');

        $this->assertSame([
            'ADD COLUMN `for_type` varchar(255) NULL',
            'ADD COLUMN `for_id` bigint unsigned NULL',
            'MODIFY COLUMN `uuid` varchar(255) NOT NULL',
            "MODIFY COLUMN `status` varchar(255) NOT NULL DEFAULT 'pending'",
            "MODIFY COLUMN `paid` double NULL DEFAULT '0'",
            'MODIFY COLUMN `kode_lama` varchar(20) NULL',
        ], $plan['statements']['invoices']);
    }

    public function test_database_yang_sudah_sesuai_tidak_berubah(): void
    {
        $rows = [];
        foreach (self::EXPECTED['invoices'] as $column => $def) {
            $rows[] = ['invoices', $column, $def['type'], $def['nullable'] ? 'YES' : 'NO', $def['default'] ?? null, empty($def['auto']) ? '' : 'auto_increment', $column === 'id' ? 'PRI' : ''];
        }

        $plan = SchemaDrift::plan(SchemaDrift::siteColumns($rows), self::EXPECTED);

        $this->assertSame([], $plan['statements']);
        $this->assertSame([], $plan['notes']);
    }

    public function test_perbedaan_tipe_lain_hanya_dilaporkan(): void
    {
        $site = SchemaDrift::siteColumns([
            ['invoices', 'paid', 'decimal(10,2)', 'NO', "'0.00'", '', ''],
        ]);

        $plan = SchemaDrift::plan($site, ['invoices' => ['paid' => ['type' => 'double', 'nullable' => false, 'default' => '0']]]);

        $this->assertSame([], $plan['statements']);
        $this->assertSame(['invoices.paid: decimal(10,2) (acuan double)'], $plan['notes']);
    }
}
