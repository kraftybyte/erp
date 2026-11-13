<?php

namespace App\Services;

use App\Helpers\Terbilang;
use App\Models\Contract;
use App\Settings\GeneralSettings;

/**
 * Format isi kontrak yang bisa dipilih di form kontrak ("Pakai format") dan
 * dijadikan bawaan kontrak baru di Settings → Contract Template:
 *
 * - standar: teks bawaan lama (ContractDefaultBody), tampilan tidak berubah;
 * - pks: Perjanjian Kerja Sama layanan konten/media sosial — 13 pasal,
 *   Lampiran 1 ruang lingkup dan Lampiran 2 biaya, tanda tangan dengan jabatan.
 *
 * Setiap format mengisi kolom kontrak yang sama (judul, pembukaan, pasal,
 * penutup, layout, teks lampiran); semuanya tetap bisa diedit per kontrak.
 * Nilai kontrak ditulis sebagai penanda ({nama_klien}, {cicilan}, ...).
 * Teks PKS mengikuti draft PKS kata per kata; angka dan pilihan yang bisa
 * berbeda per kontrak (batas hari, PPN, denda, forum sengketa, ...) menjadi
 * penanda Ketentuan PKS (TERMS) dengan nilai draft sebagai bawaan.
 */
final class ContractFormats
{
    public const OPTIONS = [
        'standar' => 'Standar (format lama)',
        'pks'     => 'PKS — 13 pasal, Lampiran 1 & 2',
    ];

    /**
     * Ketentuan PKS yang diisi per kontrak (form bagian "Ketentuan PKS", kolom
     * layout.terms). Penanda {kunci} di teks; kosong = nilai draft. Bawaan
     * berupa angka ditulis dengan terbilang ("7 (tujuh)"), teks dipakai apa
     * adanya dan boleh memuat penanda lain ({kota}).
     *
     * @var array<string, array{group: string, label: string, default: int|string, unit?: string}>
     */
    public const TERMS = [
        'hari_mulai_kerja'         => ['group' => 'Pasal 2 · Ruang Lingkup dan Jangka Waktu', 'label' => 'Mulai kerja setelah pembayaran pertama, brief, dan akses diterima', 'default' => 1, 'unit' => 'Hari Kerja'],
        'hari_perpanjangan'        => ['group' => 'Pasal 2 · Ruang Lingkup dan Jangka Waktu', 'label' => 'Kesepakatan perpanjangan paling lambat sebelum Tanggal Berakhir', 'default' => 30, 'unit' => 'Hari Kalender'],
        'hari_laporan'             => ['group' => 'Pasal 3–4 · Kewajiban Para Pihak', 'label' => 'Laporan kinerja bulanan setelah Bulan Layanan berakhir', 'default' => 7, 'unit' => 'Hari Kerja'],
        'hari_serah_materi'        => ['group' => 'Pasal 3–4 · Kewajiban Para Pihak', 'label' => 'Pihak Kedua menyerahkan brief, materi, dan akses akun', 'default' => 2, 'unit' => 'Hari Kerja'],
        'hari_persetujuan'         => ['group' => 'Pasal 3–4 · Kewajiban Para Pihak', 'label' => 'Persetujuan Konten (lewat dari ini dianggap disetujui)', 'default' => 2, 'unit' => 'Hari Kerja'],
        'batas_pembayaran_pertama' => ['group' => 'Pasal 5 · Biaya Jasa, Pembayaran, dan Pajak', 'label' => 'Pembayaran pertama paling lambat … sebelum Tanggal Mulai', 'default' => '[___]'],
        'hari_invoice'             => ['group' => 'Pasal 5 · Biaya Jasa, Pembayaran, dan Pajak', 'label' => 'Invoice terbit sebelum tanggal jatuh tempo', 'default' => 5, 'unit' => 'Hari Kalender'],
        'ppn'                      => ['group' => 'Pasal 5 · Biaya Jasa, Pembayaran, dan Pajak', 'label' => 'PPN', 'default' => '[11%/sesuai ketentuan yang berlaku]'],
        'denda_harian'             => ['group' => 'Pasal 5 · Biaya Jasa, Pembayaran, dan Pajak', 'label' => 'Denda keterlambatan per hari', 'default' => '0,1% (nol koma satu persen)'],
        'denda_maksimal'           => ['group' => 'Pasal 5 · Biaya Jasa, Pembayaran, dan Pajak', 'label' => 'Denda maksimal', 'default' => '5% (lima persen)'],
        'hari_penangguhan'         => ['group' => 'Pasal 5 · Biaya Jasa, Pembayaran, dan Pajak', 'label' => 'Jasa boleh ditangguhkan bila terlambat bayar lebih dari', 'default' => 7, 'unit' => 'Hari Kalender'],
        'batas_tanggung_jawab'     => ['group' => 'Pasal 8–9 · Tanggung Jawab dan Force Majeure', 'label' => 'Batas tanggung jawab total (Pasal 8 ayat 2)', 'default' => '[___]'],
        'hari_lapor_force_majeure' => ['group' => 'Pasal 8–9 · Tanggung Jawab dan Force Majeure', 'label' => 'Pemberitahuan Force Majeure sejak peristiwa terjadi', 'default' => 7, 'unit' => 'Hari Kalender'],
        'hari_force_majeure'       => ['group' => 'Pasal 8–9 · Tanggung Jawab dan Force Majeure', 'label' => 'Boleh mengakhiri bila Force Majeure berlangsung lebih dari', 'default' => 30, 'unit' => 'Hari Kalender'],
        'hari_telat_bayar'         => ['group' => 'Pasal 10 · Pengakhiran', 'label' => 'Pengakhiran sepihak bila terlambat bayar lebih dari', 'default' => 14, 'unit' => 'Hari Kalender'],
        'hari_teguran_bayar'       => ['group' => 'Pasal 10 · Pengakhiran', 'label' => '… dan tidak dibayar sejak pemberitahuan tertulis dalam', 'default' => 7, 'unit' => 'Hari Kalender'],
        'hari_bayar_pengakhiran'   => ['group' => 'Pasal 10 · Pengakhiran', 'label' => 'Pelunasan sejak pemberitahuan pengakhiran', 'default' => 14, 'unit' => 'Hari Kalender'],
        'hari_musyawarah'          => ['group' => 'Pasal 11 · Penyelesaian Sengketa', 'label' => 'Musyawarah', 'default' => 30, 'unit' => 'Hari Kalender'],
        'hari_mediasi'             => ['group' => 'Pasal 11 · Penyelesaian Sengketa', 'label' => 'Mediasi', 'default' => 30, 'unit' => 'Hari Kalender'],
        'forum_sengketa'           => ['group' => 'Pasal 11 · Penyelesaian Sengketa', 'label' => 'Forum bila mediasi tidak berhasil', 'default' => '(A) Pengadilan Negeri {kota} / (B) arbitrase BANI di Jakarta dengan 1 (satu) arbiter tunggal yang disepakati Para Pihak, dan apabila tidak sepakat dalam 14 hari, ditunjuk oleh Ketua BANI, sesuai UU No. 30 Tahun 1999 tentang Arbitrase dan Alternatif Penyelesaian Sengketa'],
        'rangkap'                  => ['group' => 'Pasal 13 dan Lampiran 1', 'label' => 'Perjanjian dibuat dalam rangkap', 'default' => 2],
        'meterai'                  => ['group' => 'Pasal 13 dan Lampiran 1', 'label' => 'Meterai', 'default' => 'Rp10.000'],
        'kuota_tidak_terpakai'     => ['group' => 'Pasal 13 dan Lampiran 1', 'label' => 'Kuota yang tidak terpakai (Lampiran 1)', 'default' => '[hangus / dapat dibawa ke bulan berikutnya paling lama 1 bulan]'],
    ];

    /**
     * Nilai penanda Ketentuan PKS (HTML) dari isian kontrak (layout.terms).
     *
     * @param  array<string, string>  $placeholders  penanda lain ('{kota}' => ...) untuk teks yang memuatnya
     * @return array<string, string>
     */
    public static function termValues(array $custom, array $placeholders): array
    {
        $values = [];

        foreach (self::TERMS as $key => $term) {
            $value = $custom[$key] ?? null;
            $value = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : (string) $term['default'];

            $values[$key] = is_int($term['default']) && ctype_digit($value)
                ? Terbilang::withNumber((int) $value)
                : strtr(nl2br(e($value)), $placeholders);
        }

        return $values;
    }

    /** Apakah teks kontrak (isi form atau kolom) memakai penanda Ketentuan PKS. */
    public static function usesTerms(mixed ...$texts): bool
    {
        $haystack = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        foreach (array_keys(self::TERMS) as $key) {
            if (str_contains($haystack, '{' . $key . '}')) {
                return true;
            }
        }

        return false;
    }

    /** Kolom kontrak yang diisi sebuah format. */
    public const FIELDS = [
        'document_title', 'opening_text', 'articles', 'closing_text', 'layout',
        'lampiran_title', 'lampiran_description', 'table_a_title', 'table_a_columns', 'table_a_total_label',
    ];

    /** Format bawaan kontrak baru (Settings → Contract Template). */
    public static function forNewContract(): array
    {
        return self::make((string) app(GeneralSettings::class)->contract_default_format);
    }

    /** @return array<string, mixed> nilai untuk kolom-kolom FIELDS */
    public static function make(string $key, ?Contract $contract = null): array
    {
        return $key === 'pks' ? self::pks() : self::standar($contract);
    }

    private static function standar(?Contract $contract): array
    {
        return ContractDefaultBody::make($contract) + [
            'layout'               => null,
            'lampiran_title'       => null,
            'lampiran_description' => null,
            'table_a_title'        => null,
            'table_a_columns'      => null,
            'table_a_total_label'  => null,
        ];
    }

    private static function pks(): array
    {
        return [
            'document_title' => 'Perjanjian Kerja Sama',
            'opening_text'   => <<<'HTML'
                <div>Perjanjian Kerja Sama ini (selanjutnya disebut "<strong>Perjanjian</strong>") dibuat pada hari {hari} {tanggal}, di {kota}, oleh dan antara:</div><div><strong>1. {nama_agensi}</strong>, perseroan terbatas yang didirikan berdasarkan hukum Republik Indonesia, berkedudukan di {alamat_agensi}, dalam hal ini diwakili oleh <strong>{penanda_tangan_agensi}</strong> selaku <strong>{jabatan_agensi}</strong>, yang berwenang bertindak untuk dan atas nama perseroan tersebut berdasarkan {dasar_hukum_agensi}. Selanjutnya disebut "<strong>Pihak Pertama</strong>"; dan</div><div><strong>2. {nama_klien}</strong>, perseroan terbatas yang didirikan berdasarkan hukum Republik Indonesia, berkedudukan di {alamat_klien}, dalam hal ini diwakili oleh <strong>{penanda_tangan_klien}</strong> selaku <strong>{jabatan_klien}</strong>, yang berwenang bertindak untuk dan atas nama perseroan tersebut berdasarkan {dasar_hukum_klien}. Selanjutnya disebut "<strong>Pihak Kedua</strong>".</div><div>Pihak Pertama dan Pihak Kedua secara bersama-sama disebut "<strong>Para Pihak</strong>" dan masing-masing disebut "<strong>Pihak</strong>".</div>
                HTML,
            'articles'       => array_map(fn (array $a) => ['title' => $a[0], 'content' => trim($a[1])], self::pksArticles()),
            'closing_text'   => '<div>Demikian Perjanjian ini dibuat dan ditandatangani oleh Para Pihak pada hari dan tanggal sebagaimana disebutkan di awal.</div>',
            'layout'         => [
                'number_line'              => 'Nomor: {nomor_kontrak}',
                'signature_first_label'    => 'PIHAK PERTAMA',
                'signature_second_label'   => 'PIHAK KEDUA',
                'signature_show_title'     => true,
                'scope_appendix'           => true,
                'scope_title'              => "Lampiran 1\nRuang Lingkup Pekerjaan",
                'scope_intro'              => '<div>Seluruh kuantitas di bawah ini berlaku <strong>per Bulan Layanan</strong>, kecuali dinyatakan lain. Kuota yang tidak terpakai pada suatu Bulan Layanan <strong>{kuota_tidak_terpakai}</strong>, kecuali keterlambatan disebabkan Pihak Kedua.</div>',
                'scope_columns'            => ['no' => 'No', 'item' => 'Layanan', 'detail' => 'Rincian'],
                'scope_outro'              => '<div><strong>{nomor_berikut}. Pengecualian (tidak termasuk dalam Biaya Jasa)</strong></div><div>Biaya iklan berbayar (boosting/ads), stok foto dan musik berlisensi, talent, sewa lokasi, hosting, domain, biaya operasional di luar Jabodetabek, serta pekerjaan lain di luar daftar di atas. Pekerjaan tambahan mengikuti Pasal 13 ayat 2.</div>',
                'table_a_note'             => 'Catatan: Jumlah di atas belum termasuk PPN.',
                'table_a_show_description' => false,
                'date_leading_zero'        => false,
                'rupiah_space'             => false,
                'fee_monthly_label'        => 'Biaya Jasa per Bulan',
                'fee_due_text'             => 'Setiap tanggal {tanggal_jatuh_tempo}, dibayar di muka',
            ],
            'lampiran_title'       => "Lampiran 2\nRincian Biaya Jasa",
            'lampiran_description' => null,
            'table_a_title'        => 'Tabel A. Biaya Jasa selama {durasi} bulan',
            'table_a_columns'      => ['no' => 'No', 'item' => 'Item', 'estimate' => 'Biaya Tetap per Bulan', 'date' => 'Jatuh Tempo', 'amount' => 'Jumlah'],
            'table_a_total_label'  => 'TOTAL',
        ];
    }

    /** @return list<array{0: string, 1: string}> [judul pasal, isi HTML] */
    private static function pksArticles(): array
    {
        return [
            ['Definisi', <<<'HTML'
                <div>Istilah berikut dalam Perjanjian ini memiliki pengertian sebagai berikut:</div><ol><li><strong>Jasa</strong> adalah seluruh pekerjaan yang dilakukan Pihak Pertama untuk Pihak Kedua sebagaimana diuraikan dalam Lampiran 1.</li><li><strong>Biaya Jasa</strong> adalah biaya yang wajib dibayarkan Pihak Kedua kepada Pihak Pertama sebagaimana diatur dalam Lampiran 2.</li><li><strong>Pihak Ketiga</strong> adalah pihak selain Para Pihak yang layanannya dibutuhkan selama pelaksanaan Jasa, termasuk penyedia iklan, stok foto dan musik, talent, hosting, dan domain.</li><li><strong>Invoice</strong> adalah surat penagihan yang diterbitkan Pihak Pertama kepada Pihak Kedua.</li><li><strong>Hari Kerja</strong> adalah hari selain Sabtu, Minggu, dan hari libur nasional Indonesia.</li><li><strong>Hari Kalender</strong> adalah setiap hari dalam kalender Gregorius, termasuk Sabtu, Minggu, dan hari libur nasional Indonesia.</li><li><strong>Informasi Rahasia</strong> adalah seluruh informasi, data, dan dokumen, baik tertulis, lisan, maupun elektronik, yang bersifat non-publik dan diperoleh salah satu Pihak dari Pihak lainnya sehubungan dengan Perjanjian ini, termasuk kredensial akun, data pelanggan, strategi, dan harga.</li><li><strong>Konten</strong> adalah seluruh hasil kerja berupa gambar, video, tulisan, desain, dan materi lain yang dibuat Pihak Pertama dalam rangka Jasa.</li><li><strong>Jabodetabek</strong> adalah wilayah Daerah Khusus Jakarta, Kota Bogor, Kabupaten Bogor, Kota Depok, Kota Tangerang, Kabupaten Tangerang, Kota Tangerang Selatan, Kota Bekasi, dan Kabupaten Bekasi.</li><li><strong>Bulan Layanan</strong> adalah periode 1 (satu) bulan kalender yang dihitung sejak Tanggal Mulai dan setiap tanggal yang sama pada bulan-bulan berikutnya.</li><li><strong>PIC</strong> adalah perwakilan yang ditunjuk masing-masing Pihak sebagai penghubung operasional.</li></ol>
                HTML],
            ['Ruang Lingkup dan Jangka Waktu Layanan', <<<'HTML'
                <ol><li><strong>Deskripsi proyek:</strong> {deskripsi_singkat}</li><li>Pihak Pertama memberikan Jasa sesuai ruang lingkup dalam <strong>Lampiran 1</strong>.</li><li>Jangka waktu layanan adalah <strong>{durasi_terbilang} bulan</strong>, dimulai pada <strong>{tanggal}</strong> dan berakhir pada <strong>{tanggal_berakhir}</strong></li><li>Pihak Pertama mulai mengerjakan Jasa paling lambat {hari_mulai_kerja} Hari Kerja setelah <strong>seluruh</strong> hal berikut terpenuhi: (a) pembayaran pertama diterima di rekening Pihak Pertama, dan (b) Pihak Kedua menyerahkan brief, materi, dan akses akun sebagaimana diatur dalam Pasal 4. Jika salah satu terlambat, Tanggal Mulai dan Tanggal Berakhir tidak otomatis bergeser kecuali disepakati tertulis, dan kuota Bulan Layanan tetap berjalan.</li><li>Dengan ditandatanganinya Perjanjian ini, Pihak Kedua memesan Jasa dari Pihak Pertama. Pemesanan tidak dapat dibatalkan secara sepihak, kecuali melalui mekanisme pengakhiran dalam Pasal 10.</li><li>Perjanjian dapat diperpanjang berdasarkan kesepakatan tertulis Para Pihak paling lambat {hari_perpanjangan} Hari Kalender sebelum Tanggal Berakhir. Tanpa kesepakatan tersebut, Perjanjian berakhir pada Tanggal Berakhir dan tidak diperpanjang otomatis.</li></ol>
                HTML],
            ['Kewajiban Pihak Pertama', <<<'HTML'
                <div>Pihak Pertama wajib:</div><ol><li>Melaksanakan Jasa dengan profesional, dengan itikad baik, dan sesuai jadwal yang disepakati dalam kalender konten bulanan.</li><li>Menyusun kalender konten dan mengirimkannya kepada Pihak Kedua untuk persetujuan sebelum tayang.</li><li>Menyampaikan laporan kinerja bulanan paling lambat {hari_laporan} Hari Kerja setelah Bulan Layanan berakhir.</li><li>Menjaga keamanan akses akun dan data Pihak Kedua yang diberikan kepadanya.</li><li>Menunjuk PIC untuk berkomunikasi dengan Pihak Kedua.</li></ol>
                HTML],
            ['Kewajiban Pihak Kedua', <<<'HTML'
                <div>Pihak Kedua wajib:</div><ol><li>Membayar Biaya Jasa tepat waktu sesuai Pasal 5 dan Lampiran 2.</li><li>Menyerahkan brief, materi (logo, foto produk, informasi produk, dan lainnya), serta akses akun media sosial dan website yang diperlukan paling lambat {hari_serah_materi} Hari Kerja setelah Tanggal Mulai atau permintaan Pihak Pertama.</li><li>Memberikan persetujuan atau masukan atas Konten paling lambat <strong>{hari_persetujuan} Hari Kerja</strong> sejak Konten dikirim. Jika tidak ada tanggapan dalam batas waktu tersebut, Konten dianggap disetujui dan dapat ditayangkan.</li><li>Menjamin bahwa materi yang diserahkan tidak melanggar hak cipta, merek, atau hak pihak lain, dan membebaskan Pihak Pertama dari tuntutan pihak ketiga atas materi tersebut.</li><li>Menunjuk PIC dan menyediakan kanal komunikasi resmi.</li><li>Menanggung biaya operasional kegiatan pengambilan konten di luar Jabodetabek, termasuk transportasi dan akomodasi, yang harus disetujui tertulis oleh Pihak Kedua sebelum dilaksanakan.</li></ol>
                HTML],
            ['Biaya Jasa, Pembayaran, dan Pajak', <<<'HTML'
                <ol><li>Biaya Jasa adalah biaya tetap sebesar <strong>{cicilan} per bulan</strong>, total <strong>{nilai_kontrak}</strong> untuk {durasi} bulan, sebagaimana Lampiran 2.</li><li>Pembayaran dilakukan <strong>di muka</strong>, paling lambat setiap <strong>tanggal {tanggal_jatuh_tempo}</strong> sebelum Bulan Layanan berikutnya dimulai. Pembayaran pertama dilakukan paling lambat {batas_pembayaran_pertama} sebelum Tanggal Mulai. Jika tanggal {tanggal_jatuh_tempo} jatuh pada hari bukan Hari Kerja, pembayaran dilakukan paling lambat pada Hari Kerja sebelumnya.</li><li>Pihak Pertama menerbitkan Invoice paling lambat {hari_invoice} Hari Kalender sebelum tanggal jatuh tempo.</li><li>Pembayaran dilakukan melalui transfer ke rekening berikut: <strong>Bank:</strong> {bank}; <strong>No. Rekening:</strong> {no_rekening}; <strong>Atas nama:</strong> {nama_rekening}. Pembayaran dianggap sah apabila dana telah diterima efektif di rekening tersebut. Perubahan rekening hanya sah jika diberitahukan tertulis oleh Direktur Pihak Pertama.</li><li>Biaya Jasa <strong>Belum</strong> termasuk PPN. PPN {ppn} ditambahkan pada Invoice.</li><li><strong>Denda keterlambatan:</strong> pembayaran yang terlambat dikenai denda sebesar {denda_harian} per hari dari jumlah yang terlambat, maksimal {denda_maksimal} dari jumlah tersebut.</li><li><strong>Penangguhan:</strong> jika pembayaran terlambat lebih dari {hari_penangguhan} Hari Kalender, Pihak Pertama berhak menangguhkan Jasa setelah pemberitahuan tertulis (termasuk melalui email atau pesan resmi) sampai pembayaran diterima. Penangguhan tidak memperpanjang masa Perjanjian dan tidak mengurangi kewajiban pembayaran.</li><li>Biaya Jasa tidak dapat diubah tanpa persetujuan tertulis Para Pihak.</li><li>Pembayaran yang telah diterima tidak dapat dikembalikan, <strong>kecuali</strong> Jasa pada Bulan Layanan yang telah dibayar tidak dilaksanakan karena kelalaian Pihak Pertama. Dalam hal itu, Para Pihak menyelesaikannya dengan memberikan pelaksanaan pengganti atau pengembalian secara proporsional atas bagian Jasa yang tidak dilaksanakan.</li><li>Biaya yang timbul dari Pihak Ketiga (misalnya iklan, stok foto, musik berlisensi, talent, hosting, domain) <strong>tidak termasuk</strong> Biaya Jasa dan menjadi tanggungan Pihak Kedua setelah disetujui tertulis sebelum dikeluarkan.</li></ol>
                HTML],
            ['Hak Kekayaan Intelektual', <<<'HTML'
                <ol><li>Hak cipta atas Konten beralih kepada Pihak Kedua <strong>setelah</strong> Biaya Jasa untuk Bulan Layanan terkait dibayar lunas, kecuali elemen pihak ketiga, template, font, musik, atau materi berlisensi, yang tetap tunduk pada lisensi masing-masing.</li><li>Pihak Pertama berhak mencantumkan Konten yang telah tayang secara publik sebagai portofolio, kecuali Pihak Kedua menyatakan keberatan secara tertulis.</li><li>Pihak Pertama tetap memiliki hak atas metode kerja, proses, dan alat bantu yang ada sebelum atau dikembangkan di luar Perjanjian ini.</li><li>Akun media sosial dan website tetap menjadi milik Pihak Kedua.</li></ol>
                HTML],
            ['Kerahasiaan dan Pelindungan Data Pribadi', <<<'HTML'
                <ol><li>Para Pihak wajib menjaga kerahasiaan Informasi Rahasia dan hanya menggunakannya untuk melaksanakan Perjanjian ini.</li><li>Kewajiban kerahasiaan tidak berlaku atas informasi yang: (a) sudah menjadi pengetahuan publik bukan karena pelanggaran Perjanjian; (b) sudah diketahui secara sah oleh Pihak penerima sebelumnya; (c) diperoleh secara sah dari pihak ketiga; atau (d) wajib diungkapkan berdasarkan peraturan perundang-undangan atau perintah pengadilan atau instansi berwenang, dengan pemberitahuan terlebih dahulu bila diizinkan hukum.</li><li>Para Pihak dapat mengungkapkan Informasi Rahasia hanya kepada karyawan, agen, atau penasihat yang perlu mengetahuinya dan terikat kewajiban kerahasiaan yang setara, dan bertanggung jawab atas pelanggaran oleh mereka.</li><li>Setelah Perjanjian berakhir dan atas permintaan tertulis, Para Pihak mengembalikan atau memusnahkan Informasi Rahasia, serta Pihak Pertama menghentikan akses ke akun Pihak Kedua.</li><li>Dalam hal pelaksanaan Jasa melibatkan data pribadi (misalnya orang yang tampil dalam konten), Para Pihak mematuhi UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi. Pihak Kedua bertanggung jawab memperoleh persetujuan yang diperlukan dari subjek data atas orang yang tampil dalam materi miliknya.</li></ol>
                HTML],
            ['Pembatasan Tanggung Jawab', <<<'HTML'
                <ol><li>Pihak Pertama tidak menjamin hasil tertentu atas performa media sosial dan website (termasuk jumlah views, jangkauan, atau penjualan), karena bergantung pada algoritma dan kebijakan platform serta faktor di luar kendali Pihak Pertama. Target dalam Lampiran 1 merupakan target upaya terbaik (<em>best effort</em>), bukan jaminan. Jika target tidak tercapai, Pihak Pertama tidak wajib memberikan pengembalian dana, tetapi wajib menyampaikan analisis dan rencana perbaikan pada laporan bulanan berikutnya.</li><li>Tanggung jawab total masing-masing Pihak kepada Pihak lainnya berdasarkan Perjanjian ini dibatasi paling banyak sebesar {batas_tanggung_jawab}.</li><li>Tidak ada Pihak yang bertanggung jawab atas kerugian tidak langsung, kehilangan keuntungan, atau kehilangan peluang usaha.</li><li>Pembatasan pada ayat 2 dan 3 tidak berlaku untuk kesengajaan, kelalaian berat, pelanggaran kerahasiaan, pelanggaran hak kekayaan intelektual pihak ketiga, dan kewajiban pembayaran Pihak Kedua.</li><li>Pihak Pertama tidak bertanggung jawab atas gangguan, pemblokiran, atau pembatasan akun oleh platform yang bukan disebabkan kelalaiannya.</li></ol>
                HTML],
            ['Force Majeure', <<<'HTML'
                <ol><li>Tidak dilaksanakan atau tertundanya kewajiban oleh salah satu Pihak bukan pelanggaran Perjanjian apabila disebabkan Force Majeure, dan Para Pihak menyelesaikannya secara musyawarah untuk mufakat.</li><li>Force Majeure meliputi: (a) bencana alam, wabah penyakit, angin topan, banjir, tanah longsor, gempa bumi; (b) huru-hara, perang, pemberontakan; (c) pemogokan umum, sabotase, kebakaran; (d) perubahan peraturan perundang-undangan atau kebijakan pemerintah yang menyebabkan Perjanjian tidak dapat dilaksanakan; (e) gangguan listrik, internet, atau platform digital berskala luas; dan (f) peristiwa lain di luar kendali wajar Pihak yang terdampak yang tidak dapat dihindari dengan upaya yang wajar.</li><li>Pihak yang terdampak wajib memberitahukan secara tertulis paling lambat {hari_lapor_force_majeure} Hari Kalender sejak peristiwa terjadi, disertai bukti yang wajar, serta berupaya sebaik-baiknya meminimalkan dampaknya.</li><li>Kerugian dan biaya akibat Force Majeure ditanggung masing-masing Pihak.</li><li>Jika Force Majeure berlangsung lebih dari {hari_force_majeure} Hari Kalender berturut-turut, salah satu Pihak dapat mengakhiri Perjanjian dengan pemberitahuan tertulis. Pembayaran atas Jasa yang telah dilaksanakan sampai tanggal tersebut tetap wajib dibayar.</li><li>Kewajiban pembayaran Pihak Kedua atas Jasa yang telah dilaksanakan atau yang periodenya telah berjalan tidak dapat ditunda atau dikurangi karena Force Majeure.</li></ol>
                HTML],
            ['Pengakhiran Perjanjian', <<<'HTML'
                <ol><li>Perjanjian berlaku sejak tanggal penandatanganan dan berakhir pada Tanggal Berakhir, kecuali diakhiri lebih awal berdasarkan pasal ini.</li><li>Perjanjian dapat diakhiri lebih awal: (a) berdasarkan kesepakatan tertulis Para Pihak; atau (b) oleh salah satu Pihak apabila Pihak lainnya melakukan pelanggaran material dan tidak memperbaikinya dalam <strong>{hari_pengakhiran_terbilang} Hari Kalender</strong> sejak menerima surat peringatan tertulis (masa perbaikan ini sudah termasuk masa pemberitahuan, sehingga tidak ditambah lagi).</li><li>Pihak Pertama dapat mengakhiri Perjanjian secara sepihak dengan pemberitahuan tertulis apabila Pihak Kedua terlambat membayar lebih dari {hari_telat_bayar} Hari Kalender setelah jatuh tempo dan tidak memperbaikinya dalam {hari_teguran_bayar} Hari Kalender sejak pemberitahuan tertulis.</li><li>Para Pihak sepakat mengesampingkan berlakunya Pasal 1266 Kitab Undang-Undang Hukum Perdata sepanjang diperlukan agar pengakhiran tidak memerlukan putusan pengadilan.</li><li>Akibat pengakhiran: (a) seluruh Biaya Jasa atas Jasa yang telah dilaksanakan sampai tanggal efektif pengakhiran jatuh tempo dan wajib dibayar paling lambat {hari_bayar_pengakhiran} Hari Kalender sejak pemberitahuan pengakhiran; (b) apabila pengakhiran disebabkan pelanggaran Pihak Kedua, Biaya Jasa sisa periode yang belum dilaksanakan tidak wajib dibayar kecuali disepakati lain, namun pembayaran yang telah diterima tidak dikembalikan; (c) apabila pengakhiran disebabkan pelanggaran Pihak Pertama, Pihak Pertama mengembalikan bagian pembayaran untuk Jasa yang belum dilaksanakan secara proporsional.</li><li>Ketentuan yang menurut sifatnya tetap berlaku setelah pengakhiran (pembayaran, kerahasiaan, hak kekayaan intelektual, pembatasan tanggung jawab, dan penyelesaian sengketa) tetap mengikat.</li></ol>
                HTML],
            ['Hukum yang Berlaku dan Penyelesaian Sengketa', <<<'HTML'
                <ol><li>Perjanjian ini ditafsirkan dan dilaksanakan berdasarkan hukum Republik Indonesia.</li><li>Setiap perselisihan terkait Perjanjian ini diselesaikan terlebih dahulu secara musyawarah untuk mufakat dalam waktu <strong>{hari_musyawarah} Hari Kalender</strong> sejak pemberitahuan tertulis tentang adanya perselisihan.</li><li>Jika tidak tercapai kesepakatan, Para Pihak menempuh mediasi lembaga mediasi yang disepakati dalam waktu {hari_mediasi} Hari Kalender berikutnya.</li><li>Jika mediasi tidak berhasil, perselisihan diselesaikan melalui <strong>{forum_sengketa}</strong>. Putusan arbitrase bersifat final dan mengikat.</li><li>Selama proses penyelesaian sengketa, Para Pihak tetap melaksanakan kewajibannya yang tidak dipersengketakan.</li></ol>
                HTML],
            ['Pemberitahuan dan Komunikasi', <<<'HTML'
                <ol><li>Komunikasi operasional sehari-hari (brief, persetujuan Konten, revisi) dapat dilakukan melalui kanal yang disepakati PIC kedua belah pihak, tetapi pemberitahuan pelanggaran, penangguhan, dan pengakhiran wajib melalui ayat 1.</li><li>Perubahan alamat atau PIC wajib diberitahukan tertulis.</li></ol>
                HTML],
            ['Ketentuan Lain-lain', <<<'HTML'
                <ol><li><strong>Perubahan:</strong> setiap perubahan atau tambahan atas Perjanjian dilakukan melalui addendum tertulis yang ditandatangani Para Pihak dan menjadi bagian tidak terpisahkan dari Perjanjian.</li><li><strong>Pekerjaan tambahan:</strong> permintaan di luar Lampiran 1 hanya dikerjakan setelah ada persetujuan tertulis (termasuk email) atas ruang lingkup dan biaya tambahannya, dan biaya tambahan tersebut dibayar sesuai kesepakatan.</li><li><strong>Pengalihan:</strong> hak dan kewajiban berdasarkan Perjanjian tidak dapat dialihkan kepada pihak lain tanpa persetujuan tertulis Pihak lainnya. Perjanjian mengikat penerus hukum masing-masing Pihak.</li><li><strong>Keterpisahan:</strong> jika suatu ketentuan dinyatakan tidak sah atau tidak dapat dilaksanakan, ketentuan lainnya tetap berlaku penuh.</li><li><strong>Tidak ada pelepasan hak:</strong> kelalaian atau keterlambatan salah satu Pihak menggunakan haknya bukan merupakan pelepasan hak tersebut.</li><li><strong>Keseluruhan perjanjian:</strong> Perjanjian ini beserta lampirannya merupakan keseluruhan kesepakatan Para Pihak dan menggantikan seluruh kesepakatan atau penawaran sebelumnya mengenai hal yang sama.</li><li><strong>Lampiran:</strong> Lampiran 1 dan Lampiran 2 merupakan bagian tidak terpisahkan dari Perjanjian.</li><li><strong>Pernyataan dan jaminan:</strong> masing-masing Pihak menyatakan dan menjamin bahwa (a) berwenang, memiliki izin yang diperlukan, dan mampu melaksanakan kewajibannya; (b) perwakilannya yang menandatangani berwenang mewakilinya; dan (c) penandatanganan dan pelaksanaan Perjanjian tidak melanggar perjanjian lain yang mengikat. Pihak yang pernyataannya terbukti tidak benar dan menimbulkan kerugian wajib mengganti kerugian Pihak lainnya, dengan memperhatikan batas tanggung jawab pada Pasal 8.</li><li><strong>Tanda tangan elektronik:</strong> Para Pihak dapat menandatangani Perjanjian secara elektronik sesuai UU No. 11 Tahun 2008 sebagaimana diubah terakhir dengan UU No. 1 Tahun 2024 tentang ITE, dengan kekuatan hukum yang sama.</li><li>Perjanjian ini dibuat dalam rangkap {rangkap}, masing-masing bermeterai cukup ({meterai}) dan mempunyai kekuatan hukum yang sama.</li></ol>
                HTML],
        ];
    }
}
