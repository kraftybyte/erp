<?php

namespace App\Services;

use App\Models\Contract;

/**
 * Isi kontrak bawaan untuk diedit di form: judul, pembukaan, pasal-pasal, dan
 * penutup, dalam HTML yang bisa dibuka RichEditor (div, ol/li, strong, br).
 * Teksnya sama dengan template bawaan ContractTemplateService; nilai dari form
 * ditulis sebagai penanda ({nama_klien}, {tanggal}, ...) yang diisi saat dicetak.
 */
class ContractDefaultBody
{
    /** Penanda yang bisa dipakai di isi kontrak, dengan keterangannya untuk form. */
    public const PLACEHOLDERS = [
        'nomor_kontrak'         => 'Nomor kontrak',
        'hari'                  => 'Hari tanggal efektif (mis. Senin)',
        'tanggal'               => 'Tanggal efektif (mis. 30 Oktober 2026)',
        'tanggal_berakhir'      => 'Tanggal berakhir',
        'durasi'                => 'Durasi dalam bulan (angka)',
        'hari_pengakhiran'      => 'Hari pemberitahuan pengakhiran (angka)',
        'nama_agensi'           => 'Nama perusahaan kita (Pengaturan → Invoice)',
        'alamat_agensi'         => 'Alamat perusahaan kita',
        'penanda_tangan_agensi' => 'Penanda tangan dari pihak kita',
        'jabatan_agensi'        => 'Jabatan penanda tangan pihak kita',
        'nama_klien'            => 'Nama perusahaan klien',
        'alamat_klien'          => 'Alamat klien',
        'penanda_tangan_klien'  => 'Penanda tangan dari klien',
        'jabatan_klien'         => 'Jabatan penanda tangan klien',
        'bank'                  => 'Nama bank kita',
        'no_rekening'           => 'Nomor rekening kita',
        'nama_rekening'         => 'Nama pemilik rekening',
        'nilai_kontrak'         => 'Total nilai kontrak (Rp)',
        'dp'                    => 'Nilai DP (Rp)',
        'cicilan'               => 'Nilai cicilan per bulan (Rp)',
        'deskripsi_proyek'      => 'Tabel deskripsi project (kosong bila tidak diisi)',
        'tabel_ruang_lingkup'   => 'Tabel ruang lingkup pekerjaan',
    ];

    /**
     * @return array{document_title: string, opening_text: string, articles: array<int, array{title: string, content: string}>, closing_text: string}
     */
    public static function make(?Contract $contract = null): array
    {
        // Kontrak lama yang pertama kali diedit: isian lamanya ikut masuk ke teks.
        $scope = filled($contract?->scope_of_work) ? ', yaitu: ' . nl2br(e($contract->scope_of_work)) : '';

        $articles = [
            ['title' => 'Definisi', 'content' => <<<'HTML'
                <div>Istilah-istilah berikut yang digunakan dalam Perjanjian ini memiliki pengertian sebagai berikut:</div><ol><li><strong>Jasa</strong> adalah seluruh lingkup pekerjaan yang dilakukan oleh Pihak Pertama kepada Pihak Kedua sesuai dengan Lampiran 1 Perjanjian ini.</li><li><strong>Biaya Jasa</strong> adalah besaran biaya yang harus dibayarkan oleh Pihak Kedua kepada Pihak Pertama sesuai dengan Lampiran 2 Perjanjian ini.</li><li><strong>Pihak Ketiga</strong> adalah penyedia third-party yang dibutuhkan selama pekerjaan berjalan.</li><li><strong>Invoice</strong> adalah surat penagihan yang dikeluarkan oleh Pihak Pertama kepada Pihak Kedua.</li><li><strong>Hari Kerja</strong> adalah hari di luar hari Sabtu, Minggu dan hari libur nasional Indonesia.</li><li><strong>Hari Kalender</strong> adalah setiap hari di kalender Gregorius termasuk Sabtu, Minggu dan hari libur nasional Indonesia.</li></ol>
                HTML],
            ['title' => 'Kewajiban Pihak Pertama', 'content' => '<div>{deskripsi_proyek}</div><ol><li>Pihak Pertama akan memberikan Jasa untuk kepentingan Pihak Kedua sesuai dengan ruang lingkup pengerjaan yang tercantum dalam Lampiran 1 Perjanjian ini' . $scope . '.</li>' . <<<'HTML'
                <li>Pihak Pertama akan memberikan Jasa kepada Pihak Kedua dengan didasarkan pada jangka waktu <strong>{durasi} bulan</strong> pengerjaan terhitung dari pembayaran diterima oleh Pihak Pertama.</li><li>Pekerjaan Jasa akan dimulai oleh Pihak Pertama dalam waktu 1 (satu) hari kerja setelah pembayaran pertama dibayarkan oleh Pihak Kedua.</li><li>Dengan ditandatanganinya Perjanjian ini, Pihak Kedua dianggap telah memesan Jasa kepada Pihak Kedua, yang mana pemesanan tersebut tidak dapat dibatalkan tanpa persetujuan tertulis dari Pihak Pertama.</li><li>Kegiatan kunjungan yang bersifat pengambilan konten yang berada di wilayah kerja di luar Jabodetabek, maka biaya operasional menjadi kewajiban Pihak Kedua.</li></ol><div>{tabel_ruang_lingkup}</div>
                HTML],
            ['title' => 'Kewajiban Pihak Kedua', 'content' => <<<'HTML'
                <div>Untuk memastikan Pihak Pertama dapat memberikan Jasa kepada Pihak Kedua sesuai dengan kewajiban dan jangka waktu pengerjaan yang diatur di dalam Perjanjian ini maka Pihak Kedua wajib memenuhi kewajiban pembayaran biaya jasa sebagaimana diatur di dalam Lampiran 1.</div>
                HTML],
            ['title' => 'Biaya Jasa dan Pembayaran', 'content' => <<<'HTML'
                <ol><li>Para Pihak sepakat bahwa untuk Biaya Jasa adalah mengacu pada Lampiran 2 Tabel A dari Perjanjian ini.</li><li>Biaya Jasa tidak dapat diubah tanpa persetujuan tertulis terlebih dahulu dari Para Pihak.</li><li>Pembayaran wajib dilakukan oleh Pihak Kedua kepada Pihak Pertama sesuai dengan jangka waktu pembayaran yang diatur di dalam Lampiran 2 Tabel A dari Perjanjian ini.</li><li>Pembayaran wajib dilakukan oleh Pihak Kedua ke rekening Pihak Pertama sebagai berikut:<br><strong>Nama Bank</strong> : {bank}<br><strong>No. Rek</strong> : {no_rekening}<br><strong>Nama Perusahaan</strong> : {nama_rekening}</li><li>Pembayaran dari Pihak Kedua dianggap sah apabila dana telah diterima di rekening pihak pertama.</li><li>Segala pembayaran yang telah diterima oleh Pihak Pertama tidak dapat dikembalikan kepada Pihak Kedua dengan alasan apapun juga.</li></ol>
                HTML],
            ['title' => 'Kerahasiaan', 'content' => <<<'HTML'
                <ol><li>Para Pihak wajib untuk merahasiakan seluruh informasi, data dan fakta yang diperoleh selama berlangsungnya Perjanjian ini sehingga hanya dipergunakan untuk keperluan internal dalam pelaksanaan pekerjaan sejak tanggal Perjanjian ini sampai dengan selesai, kecuali dipersyaratkan atau diperintahkan oleh aparat berwenang termasuk pengadilan atau berdasarkan peraturan yang berlaku.</li><li>Para Pihak sepakat dan saling menjamin bahwa karyawannya, agennya, penasihatnya dan/atau pihak-pihak yang terkait lainnya tidak akan memberikan Informasi Rahasia kepada siapapun tanpa persetujuan tertulis dari Pihak lainnya, baik selama berlangsungnya Perjanjian sampai dengan 3 (tiga) tahun setelah Perjanjian ini berakhir atau diakhiri.</li></ol>
                HTML],
            ['title' => 'Force Majeure', 'content' => <<<'HTML'
                <ol><li>Tidak dilaksanakannya atau tertundanya pelaksanaan sebagian atau keseluruhan ketentuan Perjanjian ini oleh salah satu pihak tidak termasuk sebagai pelanggaran Perjanjian ini apabila hal tersebut disebabkan oleh adanya Force Majeure, maka Para Pihak sepakat untuk menyelesaikannya melalui musyawarah mufakat.</li><li>Yang termasuk sebagai peristiwa Force Majeure adalah sebagai berikut:<br>a. Bencana alam / wabah penyakit / angin taufan / banjir / tanah longsor / gempa bumi<br>b. Huru-hara / perang / pemberontakan<br>c. Pemogokan umum / sabotase / kebakaran<br>d. Perubahan peraturan perundang-undangan atau kebijaksanaan pemerintah yang mengakibatkan Perjanjian ini tidak dapat dilaksanakan<br>e. Hal-hal lain di luar kontrol dari salah satu pihak</li><li>Dalam hal terjadi peristiwa sebagaimana dimaksud dalam ayat 1 Pasal ini, Para Pihak secara bersama-sama maupun sendiri-sendiri dengan dilandasi iktikad baik akan melakukan upaya semaksimal mungkin agar akibat dari peristiwa tersebut dapat ditekan seminimal mungkin.</li><li>Semua kerugian dan biaya yang diderita oleh salah satu Pihak sebagai akibat terjadinya Force Majeure tidak menjadi tanggung jawab pihak lainnya.</li><li>Untuk menghindari keragu-raguan, kewajiban Pihak Kedua untuk melakukan pembayaran kepada Pihak Pertama, termasuk pembayaran denda, tidak dapat ditunda, dikurangi dengan alasan Force Majeure.</li>
                HTML . ($contract?->force_majeure_extended ? <<<'HTML'
                <li>Termasuk pula sebagai peristiwa Force Majeure adalah gangguan yang bersifat sistemik di luar kendali Para Pihak, antara lain namun tidak terbatas pada gangguan siber berskala luas, gangguan infrastruktur telekomunikasi/internet nasional, kebijakan pembatasan wilayah (lockdown) oleh pemerintah, dan gangguan rantai pasok global, yang secara material menghalangi pelaksanaan kewajiban salah satu Pihak.</li>
                HTML : '') . '</ol>'],
            ['title' => 'Jangka Waktu dan Pengakhiran Perjanjian', 'content' => <<<'HTML'
                <ol><li>Perjanjian ini mulai berlaku dari <strong>{hari}, {tanggal}</strong> sampai dengan seluruh kewajiban dari masing-masing Pihak sebagaimana diatur di dalam Perjanjian ini telah terpenuhi kecuali diakhiri menurut ketentuan ayat 2 di bawah ini.</li><li>Para Pihak memiliki hak untuk mengakhiri Perjanjian dengan pemberitahuan tertulis kepada pihak lain, <strong>{hari_pengakhiran} hari kalender</strong> sebelum tanggal efektif pengakhiran Perjanjian dengan alasan sebagai berikut:<br>a. Salah satu pihak gagal untuk memperbaiki kesalahannya selama {hari_pengakhiran} hari kalender sejak menerima surat peringatan dari pihak lain, atau<br>b. Perjanjian diakhiri berdasarkan kesepakatan Para Pihak.</li><li>Perjanjian layanan ini dapat diakhiri oleh Pihak Pertama secara sepihak dengan pemberitahuan tertulis apabila Pihak Kedua lalai membayar lebih dari 14 (empat belas) hari kalender setelah jatuh tempo, kecuali disepakati lain oleh Pihak Pertama.</li><li>Para Pihak sepakat untuk mengesampingkan ketentuan Pasal 1266 Kitab Undang-Undang Hukum Perdata Republik Indonesia untuk mengakhiri Perjanjian ini.</li><li>Dalam hal terjadi pengakhiran Perjanjian maka segala tagihan dari Pihak Pertama kepada Pihak Kedua menjadi jatuh tempo dan Pihak Kedua berkewajiban untuk melakukan pembayaran kepada Pihak Pertama dalam jangka waktu paling lambat 14 hari kalender sejak tanggal surat pemberitahuan dikirimkan.</li><li>Setelah pengakhiran, pembayaran apa pun yang telah dilakukan oleh Pihak Kedua kepada Pihak Pertama tidak dapat dikembalikan.</li><li>Kewajiban Pihak Kedua untuk melakukan pembayaran kepada Pihak Pertama tetap mengikat Pihak Kedua meski Perjanjian ini diakhiri.</li></ol>
                HTML],
            ['title' => 'Pilihan Hukum dan Penyelesaian Perselisihan', 'content' => <<<'HTML'
                <ol><li>Perjanjian ini wajib ditafsirkan dan dilaksanakan berdasarkan hukum Republik Indonesia.</li><li>Jika terjadi perselisihan atau sengketa di antara Para Pihak mengenai atau sehubungan dengan Perjanjian ini, para Pihak akan berusaha untuk menyelesaikan perselisihan atau sengketa tersebut secara musyawarah.</li><li>Apabila sengketa tidak dapat diselesaikan dalam jangka waktu 30 (tiga puluh) hari kalender sejak Para Pihak memulai negosiasi, maka salah satu Pihak atau Para Pihak secara bersama-sama dapat mengajukan perselisihan atau sengketa tersebut kepada arbitrase untuk penyelesaian secara eksklusif dan terakhir. Arbitrase dilaksanakan di Jakarta, Indonesia, sesuai dengan peraturan Badan Arbitrase Nasional Indonesia (BANI), dengan menggunakan Bahasa Indonesia di hadapan 3 (tiga) orang arbiter; masing-masing Pihak akan menunjuk 1 (satu) orang arbiter dan kedua orang arbiter tersebut yang nantinya akan menunjuk arbiter ketiga yang akan bertindak sebagai ketua arbitrase.</li><li>Ketentuan ayat (3) mengikat Para Pihak untuk menyerahkan semua Perselisihan yang tidak dapat diselesaikan secara musyawarah kepada keputusan Badan Arbitrase yang bersifat final dan mengikat sesuai dengan ketentuan Undang-Undang Arbitrase. Keputusan Badan Arbitrase bersifat final, mengikat dan tidak dapat dilakukan upaya banding, serta dapat digunakan sebagai dasar untuk pelaksanaan putusan di Indonesia dan dimanapun juga.</li><li>Selama jangka waktu pengajuan penyelesaian Perselisihan kepada Badan Arbitrase dan sampai dihasilkan keputusan Badan Arbitrase, Para Pihak akan melanjutkan pemenuhan kewajibannya berdasarkan Perjanjian, tanpa mengurangi keputusan final yang diputuskan oleh Badan Arbitrase tersebut.</li></ol>
                HTML],
            ['title' => 'Pengalihan Perjanjian', 'content' => <<<'HTML'
                <div>Para Pihak sepakat untuk tidak mengalihkan sebagian atau keseluruhan dari isi Perjanjian ini kepada Pihak Lainnya kecuali atas kesepakatan tertulis Para Pihak.</div>
                HTML],
            ['title' => 'Lain-Lain', 'content' => <<<'HTML'
                <ol><li>Segala sesuatu yang belum diatur di dalam Perjanjian ini, atau adanya tambahan / perubahan-perubahan yang disepakati oleh Para Pihak, maka akan dibuatkan perjanjian tambahan / addendum / atau amandemen perjanjian secara tertulis yang disetujui dan ditandatangani oleh Para Pihak di atas serta merupakan bagian yang tak terpisahkan dari isi Perjanjian ini.</li><li>Apabila suatu ketentuan dari Perjanjian ini atau bagian darinya dinyatakan tidak sah, tidak berlaku, tidak dapat dilaksanakan, bertentangan dengan ketentuan hukum yang berlaku, maka ketidakabsahan, ketidakberlakuan, tidak dapat dilaksanakannya, pertentangan, atau ketidaksesuaian tersebut hanya berlaku terhadap ketentuan tersebut atau bagian dari ketentuan tersebut, sedangkan keabsahan, keberlakuan, dan pelaksanaan bagian lain dari ketentuan tersebut serta ketentuan-ketentuan lain dari Perjanjian ini tidak terpengaruh atau terganggu dan oleh karenanya tetap berlaku secara penuh dan sah.</li><li>Perjanjian ini akan mengikat atas dan timbul untuk kepentingan Para Pihak berikut pengganti dan penerus masing-masing Pihak. Hak-hak dan kewajiban-kewajiban Para Pihak yang timbul berdasarkan Perjanjian ini tidak dapat dipindahkan atau dengan cara apapun dialihkan kepada pihak ketiga lain manapun tanpa adanya persetujuan tertulis dari Pihak lainnya dalam Perjanjian ini.</li><li>Tiada satu pihak pun yang bertanggung jawab untuk semua kerugian baik langsung maupun tidak langsung yang diderita oleh pihak lain sehubungan dengan pelaksanaan Perjanjian ini.</li><li>Segala lampiran dari Perjanjian ini merupakan bagian yang tidak terpisahkan dari Perjanjian ini.</li><li>Sehubungan dengan Perjanjian ini dan akibat hukumnya, masing-masing pihak menyatakan dan menjamin hal-hal sebagai berikut:<br>a. Masing-masing pihak adalah pihak-pihak yang berhak, berwenang, memiliki izin-izin yang diperlukan dan/atau mempunyai kemampuan untuk melaksanakan kegiatan usahanya dan/atau kewajibannya berdasarkan Perjanjian ini.<br>b. Perwakilan masing-masing pihak yang menandatangani Perjanjian ini adalah pihak yang berwenang bertindak untuk dan atas nama masing-masing pihak dan telah memperoleh izin-izin yang diperlukan untuk mewakili masing-masing pihak termasuk untuk menandatangani dan melaksanakan Perjanjian ini.<br>c. Penandatanganan dan pelaksanaan Perjanjian ini tidak melanggar Perjanjian-perjanjian lain yang mengikat Para Pihak.</li><li>Apabila pernyataan-pernyataan pada ayat 6 di atas, ternyata di kemudian hari terbukti tidak benar dan/atau menyesatkan dan menimbulkan kerugian terhadap pihak lainnya, maka pihak yang mengakibatkan timbulnya kerugian tersebut wajib mengganti segala kerugian pihak lainnya sebagai akibat dari pernyataan yang tidak benar dan/atau menyesatkan tersebut.</li></ol>
                HTML],
        ];

        // Klausul tambahan dan denda kontrak lama menjadi pasal biasa.
        foreach (['content' => 'Ketentuan Tambahan', 'penalty_clause' => 'Klausul Denda'] as $field => $title) {
            if (filled($contract?->{$field})) {
                $articles[] = ['title' => $title, 'content' => '<div>' . nl2br(e($contract->{$field})) . '</div>'];
            }
        }

        return [
            'document_title' => 'Perjanjian Kerja Sama',
            'opening_text'   => <<<'HTML'
                <div>Perjanjian Kerja Sama ini (untuk selanjutnya disebut "Perjanjian") dibuat pada hari <strong>{hari}</strong>, tanggal <strong>{tanggal}</strong>, oleh dan antara Para Pihak di bawah ini:</div><div><strong>1. PIHAK PERTAMA</strong></div><div><strong>{nama_agensi}</strong>, suatu perseroan terbatas yang didirikan menurut dan berdasarkan hukum Indonesia, berkedudukan di {alamat_agensi} yang dalam hal ini diwakili oleh <strong>{penanda_tangan_agensi}</strong> dalam kedudukannya selaku <strong>{jabatan_agensi}</strong> dan oleh karena itu sah bertindak untuk dan atas nama <strong>{nama_agensi}</strong>. Untuk selanjutnya disebut "<strong>Pihak Pertama</strong>", dan</div><div><strong>2. PIHAK KEDUA</strong></div><div><strong>{nama_klien}</strong>, berkedudukan di {alamat_klien}. Dalam hal ini diwakili oleh <strong>{penanda_tangan_klien}</strong>, dalam kedudukannya selaku <strong>{jabatan_klien}</strong>, dan oleh karena itu sah bertindak untuk dan atas nama <strong>{nama_klien}</strong>. Untuk selanjutnya disebut "<strong>Pihak Kedua</strong>",</div><div>Pihak Pertama dan Pihak Kedua untuk selanjutnya secara bersama-sama dalam Perjanjian ini disebut sebagai "<strong>Para Pihak</strong>" dan masing-masing disebut sebagai "<strong>Pihak</strong>".</div>
                HTML,
            'articles'     => array_map(fn (array $article) => ['title' => $article['title'], 'content' => trim($article['content'])], $articles),
            'closing_text' => '<div>Perjanjian ini dibuat dalam rangkap 2 (dua) asli yang masing-masing dibubuhi materai secukupnya dan ditandatangani sebagaimana mestinya oleh Para Pihak, dan masing-masing mempunyai kekuatan hukum yang sama.</div>',
        ];
    }
}
