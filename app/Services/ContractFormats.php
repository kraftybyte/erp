<?php

namespace App\Services;

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
 * Teks PKS mengikuti draft PKS kata per kata; [kurung siku] adalah isian atau
 * pilihan dari draft yang diputuskan per kontrak.
 */
final class ContractFormats
{
    public const OPTIONS = [
        'standar' => 'Standar (format lama)',
        'pks'     => 'PKS — 13 pasal, Lampiran 1 & 2',
    ];

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
                'scope_intro'              => '<div>Seluruh kuantitas di bawah ini berlaku <strong>per Bulan Layanan</strong>, kecuali dinyatakan lain. Kuota yang tidak terpakai pada suatu Bulan Layanan <strong>[hangus / dapat dibawa ke bulan berikutnya paling lama 1 bulan]</strong>, kecuali keterlambatan disebabkan Pihak Kedua.</div>',
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
                <ol><li><strong>Deskripsi proyek:</strong> {deskripsi_singkat}</li><li>Pihak Pertama memberikan Jasa sesuai ruang lingkup dalam <strong>Lampiran 1</strong>.</li><li>Jangka waktu layanan adalah <strong>{durasi_terbilang} bulan</strong>, dimulai pada <strong>{tanggal}</strong> dan berakhir pada <strong>{tanggal_berakhir}</strong></li><li>Pihak Pertama mulai mengerjakan Jasa paling lambat 1 (satu) Hari Kerja setelah <strong>seluruh</strong> hal berikut terpenuhi: (a) pembayaran pertama diterima di rekening Pihak Pertama, dan (b) Pihak Kedua menyerahkan brief, materi, dan akses akun sebagaimana diatur dalam Pasal 4. Jika salah satu terlambat, Tanggal Mulai dan Tanggal Berakhir tidak otomatis bergeser kecuali disepakati tertulis, dan kuota Bulan Layanan tetap berjalan.</li><li>Dengan ditandatanganinya Perjanjian ini, Pihak Kedua memesan Jasa dari Pihak Pertama. Pemesanan tidak dapat dibatalkan secara sepihak, kecuali melalui mekanisme pengakhiran dalam Pasal 10.</li><li>Perjanjian dapat diperpanjang berdasarkan kesepakatan tertulis Para Pihak paling lambat 30 (tiga puluh) Hari Kalender sebelum Tanggal Berakhir. Tanpa kesepakatan tersebut, Perjanjian berakhir pada Tanggal Berakhir dan tidak diperpanjang otomatis.</li></ol>
                HTML],
            ['Kewajiban Pihak Pertama', <<<'HTML'
                <div>Pihak Pertama wajib:</div><ol><li>Melaksanakan Jasa dengan profesional, dengan itikad baik, dan sesuai jadwal yang disepakati dalam kalender konten bulanan.</li><li>Menyusun kalender konten dan mengirimkannya kepada Pihak Kedua untuk persetujuan sebelum tayang.</li><li>Menyampaikan laporan kinerja bulanan paling lambat 7 (tujuh) Hari Kerja setelah Bulan Layanan berakhir.</li><li>Menjaga keamanan akses akun dan data Pihak Kedua yang diberikan kepadanya.</li><li>Menunjuk PIC untuk berkomunikasi dengan Pihak Kedua.</li></ol>
                HTML],
            ['Kewajiban Pihak Kedua', <<<'HTML'
                <div>Pihak Kedua wajib:</div><ol><li>Membayar Biaya Jasa tepat waktu sesuai Pasal 5 dan Lampiran 2.</li><li>Menyerahkan brief, materi (logo, foto produk, informasi produk, dan lainnya), serta akses akun media sosial dan website yang diperlukan paling lambat 2 (dua) Hari Kerja setelah Tanggal Mulai atau permintaan Pihak Pertama.</li><li>Memberikan persetujuan atau masukan atas Konten paling lambat <strong>2 (dua) Hari Kerja</strong> sejak Konten dikirim. Jika tidak ada tanggapan dalam batas waktu tersebut, Konten dianggap disetujui dan dapat ditayangkan.</li><li>Menjamin bahwa materi yang diserahkan tidak melanggar hak cipta, merek, atau hak pihak lain, dan membebaskan Pihak Pertama dari tuntutan pihak ketiga atas materi tersebut.</li><li>Menunjuk PIC dan menyediakan kanal komunikasi resmi.</li><li>Menanggung biaya operasional kegiatan pengambilan konten di luar Jabodetabek, termasuk transportasi dan akomodasi, yang harus disetujui tertulis oleh Pihak Kedua sebelum dilaksanakan.</li></ol>
                HTML],
            ['Biaya Jasa, Pembayaran, dan Pajak', <<<'HTML'
                <ol><li>Biaya Jasa adalah biaya tetap sebesar <strong>{cicilan} per bulan</strong>, total <strong>{nilai_kontrak}</strong> untuk {durasi} bulan, sebagaimana Lampiran 2.</li><li>Pembayaran dilakukan <strong>di muka</strong>, paling lambat setiap <strong>tanggal {tanggal_jatuh_tempo}</strong> sebelum Bulan Layanan berikutnya dimulai. Pembayaran pertama dilakukan paling lambat [___] sebelum Tanggal Mulai. Jika tanggal {tanggal_jatuh_tempo} jatuh pada hari bukan Hari Kerja, pembayaran dilakukan paling lambat pada Hari Kerja sebelumnya.</li><li>Pihak Pertama menerbitkan Invoice paling lambat 5 (lima) Hari Kalender sebelum tanggal jatuh tempo.</li><li>Pembayaran dilakukan melalui transfer ke rekening berikut: <strong>Bank:</strong> {bank}; <strong>No. Rekening:</strong> {no_rekening}; <strong>Atas nama:</strong> {nama_rekening}. Pembayaran dianggap sah apabila dana telah diterima efektif di rekening tersebut. Perubahan rekening hanya sah jika diberitahukan tertulis oleh Direktur Pihak Pertama.</li><li>Biaya Jasa <strong>Belum</strong> termasuk PPN. PPN [11%/sesuai ketentuan yang berlaku] ditambahkan pada Invoice.</li><li><strong>Denda keterlambatan:</strong> pembayaran yang terlambat dikenai denda sebesar 0,1% (nol koma satu persen) per hari dari jumlah yang terlambat, maksimal 5% (lima persen) dari jumlah tersebut.</li><li><strong>Penangguhan:</strong> jika pembayaran terlambat lebih dari 7 (tujuh) Hari Kalender, Pihak Pertama berhak menangguhkan Jasa setelah pemberitahuan tertulis (termasuk melalui email atau pesan resmi) sampai pembayaran diterima. Penangguhan tidak memperpanjang masa Perjanjian dan tidak mengurangi kewajiban pembayaran.</li><li>Biaya Jasa tidak dapat diubah tanpa persetujuan tertulis Para Pihak.</li><li>Pembayaran yang telah diterima tidak dapat dikembalikan, <strong>kecuali</strong> Jasa pada Bulan Layanan yang telah dibayar tidak dilaksanakan karena kelalaian Pihak Pertama. Dalam hal itu, Para Pihak menyelesaikannya dengan memberikan pelaksanaan pengganti atau pengembalian secara proporsional atas bagian Jasa yang tidak dilaksanakan.</li><li>Biaya yang timbul dari Pihak Ketiga (misalnya iklan, stok foto, musik berlisensi, talent, hosting, domain) <strong>tidak termasuk</strong> Biaya Jasa dan menjadi tanggungan Pihak Kedua setelah disetujui tertulis sebelum dikeluarkan.</li></ol>
                HTML],
            ['Hak Kekayaan Intelektual', <<<'HTML'
                <ol><li>Hak cipta atas Konten beralih kepada Pihak Kedua <strong>setelah</strong> Biaya Jasa untuk Bulan Layanan terkait dibayar lunas, kecuali elemen pihak ketiga, template, font, musik, atau materi berlisensi, yang tetap tunduk pada lisensi masing-masing.</li><li>Pihak Pertama berhak mencantumkan Konten yang telah tayang secara publik sebagai portofolio, kecuali Pihak Kedua menyatakan keberatan secara tertulis.</li><li>Pihak Pertama tetap memiliki hak atas metode kerja, proses, dan alat bantu yang ada sebelum atau dikembangkan di luar Perjanjian ini.</li><li>Akun media sosial dan website tetap menjadi milik Pihak Kedua.</li></ol>
                HTML],
            ['Kerahasiaan dan Pelindungan Data Pribadi', <<<'HTML'
                <ol><li>Para Pihak wajib menjaga kerahasiaan Informasi Rahasia dan hanya menggunakannya untuk melaksanakan Perjanjian ini.</li><li>Kewajiban kerahasiaan tidak berlaku atas informasi yang: (a) sudah menjadi pengetahuan publik bukan karena pelanggaran Perjanjian; (b) sudah diketahui secara sah oleh Pihak penerima sebelumnya; (c) diperoleh secara sah dari pihak ketiga; atau (d) wajib diungkapkan berdasarkan peraturan perundang-undangan atau perintah pengadilan atau instansi berwenang, dengan pemberitahuan terlebih dahulu bila diizinkan hukum.</li><li>Para Pihak dapat mengungkapkan Informasi Rahasia hanya kepada karyawan, agen, atau penasihat yang perlu mengetahuinya dan terikat kewajiban kerahasiaan yang setara, dan bertanggung jawab atas pelanggaran oleh mereka.</li><li>Setelah Perjanjian berakhir dan atas permintaan tertulis, Para Pihak mengembalikan atau memusnahkan Informasi Rahasia, serta Pihak Pertama menghentikan akses ke akun Pihak Kedua.</li><li>Dalam hal pelaksanaan Jasa melibatkan data pribadi (misalnya orang yang tampil dalam konten), Para Pihak mematuhi UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi. Pihak Kedua bertanggung jawab memperoleh persetujuan yang diperlukan dari subjek data atas orang yang tampil dalam materi miliknya.</li></ol>
                HTML],
            ['Pembatasan Tanggung Jawab', <<<'HTML'
                <ol><li>Pihak Pertama tidak menjamin hasil tertentu atas performa media sosial dan website (termasuk jumlah views, jangkauan, atau penjualan), karena bergantung pada algoritma dan kebijakan platform serta faktor di luar kendali Pihak Pertama. Target dalam Lampiran 1 merupakan target upaya terbaik (<em>best effort</em>), bukan jaminan. Jika target tidak tercapai, Pihak Pertama tidak wajib memberikan pengembalian dana, tetapi wajib menyampaikan analisis dan rencana perbaikan pada laporan bulanan berikutnya.</li><li>Tanggung jawab total masing-masing Pihak kepada Pihak lainnya berdasarkan Perjanjian ini dibatasi paling banyak sebesar [___].</li><li>Tidak ada Pihak yang bertanggung jawab atas kerugian tidak langsung, kehilangan keuntungan, atau kehilangan peluang usaha.</li><li>Pembatasan pada ayat 2 dan 3 tidak berlaku untuk kesengajaan, kelalaian berat, pelanggaran kerahasiaan, pelanggaran hak kekayaan intelektual pihak ketiga, dan kewajiban pembayaran Pihak Kedua.</li><li>Pihak Pertama tidak bertanggung jawab atas gangguan, pemblokiran, atau pembatasan akun oleh platform yang bukan disebabkan kelalaiannya.</li></ol>
                HTML],
            ['Force Majeure', <<<'HTML'
                <ol><li>Tidak dilaksanakan atau tertundanya kewajiban oleh salah satu Pihak bukan pelanggaran Perjanjian apabila disebabkan Force Majeure, dan Para Pihak menyelesaikannya secara musyawarah untuk mufakat.</li><li>Force Majeure meliputi: (a) bencana alam, wabah penyakit, angin topan, banjir, tanah longsor, gempa bumi; (b) huru-hara, perang, pemberontakan; (c) pemogokan umum, sabotase, kebakaran; (d) perubahan peraturan perundang-undangan atau kebijakan pemerintah yang menyebabkan Perjanjian tidak dapat dilaksanakan; (e) gangguan listrik, internet, atau platform digital berskala luas; dan (f) peristiwa lain di luar kendali wajar Pihak yang terdampak yang tidak dapat dihindari dengan upaya yang wajar.</li><li>Pihak yang terdampak wajib memberitahukan secara tertulis paling lambat 7 (tujuh) Hari Kalender sejak peristiwa terjadi, disertai bukti yang wajar, serta berupaya sebaik-baiknya meminimalkan dampaknya.</li><li>Kerugian dan biaya akibat Force Majeure ditanggung masing-masing Pihak.</li><li>Jika Force Majeure berlangsung lebih dari 30 (tiga puluh) Hari Kalender berturut-turut, salah satu Pihak dapat mengakhiri Perjanjian dengan pemberitahuan tertulis. Pembayaran atas Jasa yang telah dilaksanakan sampai tanggal tersebut tetap wajib dibayar.</li><li>Kewajiban pembayaran Pihak Kedua atas Jasa yang telah dilaksanakan atau yang periodenya telah berjalan tidak dapat ditunda atau dikurangi karena Force Majeure.</li></ol>
                HTML],
            ['Pengakhiran Perjanjian', <<<'HTML'
                <ol><li>Perjanjian berlaku sejak tanggal penandatanganan dan berakhir pada Tanggal Berakhir, kecuali diakhiri lebih awal berdasarkan pasal ini.</li><li>Perjanjian dapat diakhiri lebih awal: (a) berdasarkan kesepakatan tertulis Para Pihak; atau (b) oleh salah satu Pihak apabila Pihak lainnya melakukan pelanggaran material dan tidak memperbaikinya dalam <strong>{hari_pengakhiran_terbilang} Hari Kalender</strong> sejak menerima surat peringatan tertulis (masa perbaikan ini sudah termasuk masa pemberitahuan, sehingga tidak ditambah lagi).</li><li>Pihak Pertama dapat mengakhiri Perjanjian secara sepihak dengan pemberitahuan tertulis apabila Pihak Kedua terlambat membayar lebih dari 14 (empat belas) Hari Kalender setelah jatuh tempo dan tidak memperbaikinya dalam 7 (tujuh) Hari Kalender sejak pemberitahuan tertulis.</li><li>Para Pihak sepakat mengesampingkan berlakunya Pasal 1266 Kitab Undang-Undang Hukum Perdata sepanjang diperlukan agar pengakhiran tidak memerlukan putusan pengadilan.</li><li>Akibat pengakhiran: (a) seluruh Biaya Jasa atas Jasa yang telah dilaksanakan sampai tanggal efektif pengakhiran jatuh tempo dan wajib dibayar paling lambat 14 (empat belas) Hari Kalender sejak pemberitahuan pengakhiran; (b) apabila pengakhiran disebabkan pelanggaran Pihak Kedua, Biaya Jasa sisa periode yang belum dilaksanakan tidak wajib dibayar kecuali disepakati lain, namun pembayaran yang telah diterima tidak dikembalikan; (c) apabila pengakhiran disebabkan pelanggaran Pihak Pertama, Pihak Pertama mengembalikan bagian pembayaran untuk Jasa yang belum dilaksanakan secara proporsional.</li><li>Ketentuan yang menurut sifatnya tetap berlaku setelah pengakhiran (pembayaran, kerahasiaan, hak kekayaan intelektual, pembatasan tanggung jawab, dan penyelesaian sengketa) tetap mengikat.</li></ol>
                HTML],
            ['Hukum yang Berlaku dan Penyelesaian Sengketa', <<<'HTML'
                <ol><li>Perjanjian ini ditafsirkan dan dilaksanakan berdasarkan hukum Republik Indonesia.</li><li>Setiap perselisihan terkait Perjanjian ini diselesaikan terlebih dahulu secara musyawarah untuk mufakat dalam waktu <strong>30 (tiga puluh) Hari Kalender</strong> sejak pemberitahuan tertulis tentang adanya perselisihan.</li><li>Jika tidak tercapai kesepakatan, Para Pihak menempuh mediasi lembaga mediasi yang disepakati dalam waktu 30 (tiga puluh) Hari Kalender berikutnya.</li><li>Jika mediasi tidak berhasil, perselisihan diselesaikan melalui <strong>(A) Pengadilan Negeri {kota} / (B) arbitrase BANI di Jakarta dengan 1 (satu) arbiter tunggal yang disepakati Para Pihak, dan apabila tidak sepakat dalam 14 hari, ditunjuk oleh Ketua BANI, sesuai UU No. 30 Tahun 1999 tentang Arbitrase dan Alternatif Penyelesaian Sengketa</strong>. Putusan arbitrase bersifat final dan mengikat.</li><li>Selama proses penyelesaian sengketa, Para Pihak tetap melaksanakan kewajibannya yang tidak dipersengketakan.</li></ol>
                HTML],
            ['Pemberitahuan dan Komunikasi', <<<'HTML'
                <ol><li>Komunikasi operasional sehari-hari (brief, persetujuan Konten, revisi) dapat dilakukan melalui kanal yang disepakati PIC kedua belah pihak, tetapi pemberitahuan pelanggaran, penangguhan, dan pengakhiran wajib melalui ayat 1.</li><li>Perubahan alamat atau PIC wajib diberitahukan tertulis.</li></ol>
                HTML],
            ['Ketentuan Lain-lain', <<<'HTML'
                <ol><li><strong>Perubahan:</strong> setiap perubahan atau tambahan atas Perjanjian dilakukan melalui addendum tertulis yang ditandatangani Para Pihak dan menjadi bagian tidak terpisahkan dari Perjanjian.</li><li><strong>Pekerjaan tambahan:</strong> permintaan di luar Lampiran 1 hanya dikerjakan setelah ada persetujuan tertulis (termasuk email) atas ruang lingkup dan biaya tambahannya, dan biaya tambahan tersebut dibayar sesuai kesepakatan.</li><li><strong>Pengalihan:</strong> hak dan kewajiban berdasarkan Perjanjian tidak dapat dialihkan kepada pihak lain tanpa persetujuan tertulis Pihak lainnya. Perjanjian mengikat penerus hukum masing-masing Pihak.</li><li><strong>Keterpisahan:</strong> jika suatu ketentuan dinyatakan tidak sah atau tidak dapat dilaksanakan, ketentuan lainnya tetap berlaku penuh.</li><li><strong>Tidak ada pelepasan hak:</strong> kelalaian atau keterlambatan salah satu Pihak menggunakan haknya bukan merupakan pelepasan hak tersebut.</li><li><strong>Keseluruhan perjanjian:</strong> Perjanjian ini beserta lampirannya merupakan keseluruhan kesepakatan Para Pihak dan menggantikan seluruh kesepakatan atau penawaran sebelumnya mengenai hal yang sama.</li><li><strong>Lampiran:</strong> Lampiran 1 dan Lampiran 2 merupakan bagian tidak terpisahkan dari Perjanjian.</li><li><strong>Pernyataan dan jaminan:</strong> masing-masing Pihak menyatakan dan menjamin bahwa (a) berwenang, memiliki izin yang diperlukan, dan mampu melaksanakan kewajibannya; (b) perwakilannya yang menandatangani berwenang mewakilinya; dan (c) penandatanganan dan pelaksanaan Perjanjian tidak melanggar perjanjian lain yang mengikat. Pihak yang pernyataannya terbukti tidak benar dan menimbulkan kerugian wajib mengganti kerugian Pihak lainnya, dengan memperhatikan batas tanggung jawab pada Pasal 8.</li><li><strong>Tanda tangan elektronik:</strong> Para Pihak dapat menandatangani Perjanjian secara elektronik sesuai UU No. 11 Tahun 2008 sebagaimana diubah terakhir dengan UU No. 1 Tahun 2024 tentang ITE, dengan kekuatan hukum yang sama.</li><li>Perjanjian ini dibuat dalam rangkap 2 (dua), masing-masing bermeterai cukup (Rp10.000) dan mempunyai kekuatan hukum yang sama.</li></ol>
                HTML],
        ];
    }
}
