<?php

namespace App\Services;

use App\Helpers\Terbilang;
use App\Models\Contract;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContractTemplateService
{
    /** Judul kolom bawaan Tabel A (biaya jasa) dan Tabel B (pihak ketiga); bisa diganti per kontrak. */
    public const TABLE_A_COLUMNS = ['no' => 'No', 'item' => 'Item', 'estimate' => 'Estimasi', 'date' => 'Tanggal', 'amount' => 'Jumlah'];

    public const TABLE_B_COLUMNS = ['no' => 'No', 'item' => 'Item', 'amount' => 'Amount (IDR)'];

    public const TABLE_A_DESCRIPTION = 'Biaya yang perlu dibayarkan oleh Pihak Kedua kepada Pihak Pertama selama {durasi} bulan';

    public const TABLE_B_DESCRIPTION = 'Biaya yang perlu dibayarkan oleh Pihak Kedua kepada Pihak Ketiga (Pihak yang menyediakan third-party yang dibutuhkan selama project berjalan)';

    public const TOTAL_LABEL = 'TOTAL';

    /**
     * Tampilan kontrak yang bisa diatur (kolom layout); kosong = tampilan lama.
     * scope_*: ruang lingkup sebagai halaman Lampiran sendiri (judul, pembuka,
     * judul kolom, penutup) alih-alih tabel di dalam pasal.
     */
    public const LAYOUT_DEFAULTS = [
        'number_line'              => '{nomor_kontrak}',
        'signature_first_label'    => 'Pihak Pertama,',
        'signature_second_label'   => 'Pihak Kedua,',
        'signature_show_title'     => false,
        'scope_appendix'           => false,
        'scope_title'              => 'Lampiran 1 — Ruang Lingkup Pekerjaan',
        'scope_intro'              => '',
        'scope_columns'            => ['no' => 'No', 'item' => 'Layanan', 'detail' => 'Rincian'],
        'scope_outro'              => '',
        'table_a_note'             => '',
        'table_a_show_description' => true,
    ];

    /** Layout kontrak lengkap: isian kontrak, kolom yang kosong memakai bawaan. */
    public static function layout(Contract $contract): array
    {
        $custom = is_array($contract->layout) ? $contract->layout : [];
        $layout = array_merge(self::LAYOUT_DEFAULTS, array_filter($custom, fn ($value) => $value !== null && $value !== ''));

        $layout['scope_columns']            = self::columnLabels(is_array($custom['scope_columns'] ?? null) ? $custom['scope_columns'] : null, self::LAYOUT_DEFAULTS['scope_columns']);
        $layout['signature_show_title']     = (bool) $layout['signature_show_title'];
        $layout['scope_appendix']           = (bool) $layout['scope_appendix'];
        $layout['table_a_show_description'] = (bool) $layout['table_a_show_description'];

        return $layout;
    }

    /**
     * Angka pembayaran kontrak, satu sumber untuk isi kontrak, Tabel A, dan
     * tombol "Isi otomatis dari Pembayaran" di form.
     *
     * @return array{total: float, dp: float, remaining: float, months: int, monthly: float, duration: int}
     */
    public function pricing(Contract $contract): array
    {
        $duration  = (int) ($contract->duration_months ?? 12);
        $total     = (float) ($contract->total_value ?? 0);
        $dp        = ($total * (float) ($contract->down_payment_percent ?? 0)) / 100;
        $remaining = $total - $dp;
        $months    = (int) ($contract->installment_months ?? $duration);

        return [
            'total'     => $total,
            'dp'        => $dp,
            'remaining' => $remaining,
            'months'    => $months,
            'monthly'   => $months > 0 ? $remaining / $months : 0,
            'duration'  => $duration,
        ];
    }

    /**
     * Baris Tabel A bila kontrak belum punya item biaya: dari tipe pembayaran
     * (lunas, DP + cicilan, cicilan per bulan).
     *
     * @return list<array{item: string, estimate: string, due_date: string, amount: float}>
     */
    public function defaultFeeRows(Contract $contract): array
    {
        $p    = $this->pricing($contract);
        $date = ($contract->effective_date ? Carbon::parse($contract->effective_date) : now())->format('d/m/Y');

        return match ($contract->payment_type ?? 'full') {
            'dp_monthly' => [
                ['item' => 'Fase 1 - Down Payment', 'estimate' => $this->formatRupiah($p['dp']), 'due_date' => $date, 'amount' => $p['dp']],
                ['item' => "Sisa Pembayaran ({$p['months']}x)", 'estimate' => $this->formatRupiah($p['remaining']), 'due_date' => 'Setiap tanggal 15', 'amount' => $p['remaining']],
            ],
            'monthly' => [
                ['item' => "Pembayaran per Bulan ({$p['months']}x)", 'estimate' => $this->formatRupiah($p['monthly']), 'due_date' => 'Setiap tanggal 15', 'amount' => $p['total']],
            ],
            default => [
                ['item' => 'Pembayaran Lunas', 'estimate' => $this->formatRupiah($p['total']), 'due_date' => $date, 'amount' => $p['total']],
            ],
        };
    }

    /**
     * Judul kolom: isian kontrak, kolom yang kosong memakai bawaan.
     *
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    public static function columnLabels(?array $custom, array $defaults): array
    {
        $labels = [];

        foreach ($defaults as $key => $default) {
            $labels[$key] = filled($custom[$key] ?? null) ? trim((string) $custom[$key]) : $default;
        }

        return $labels;
    }

    /**
     * Penanda tangan pihak kita (Pihak Pertama) bila kontraknya tidak mengisi
     * sendiri: Settings → Contract Template, lalu penanda tangan invoice;
     * jabatannya "Direksi".
     *
     * @return array{name: ?string, title: string}
     */
    public static function defaultAgencySignatory(?GeneralSettings $settings = null): array
    {
        $settings ??= app(GeneralSettings::class);

        return [
            'name'  => collect([$settings->contract_signature_name, $settings->inv_signature_name])->first(fn ($name) => filled($name)),
            'title' => filled($settings->contract_signature_title) ? $settings->contract_signature_title : 'Direksi',
        ];
    }

    public function generate(Contract $contract): string
    {
        $settings = app(GeneralSettings::class);

        // Eager-load relations used in this template so we don't trigger N+1
        // queries when generating documents for many contracts in a batch.
        $contract->loadMissing([
            'feeItems',
            'thirdPartyItems',
            'sowItems',
            'sowCategories.children',
        ]);

        // === COMPANY INFO (dari Invoice Settings) ===
        $logoHtml = $this->getLogoHtml($settings);
        $agencyName = $settings->inv_company_name ?? 'PT. Nama Perusahaan';
        $agencyAddress = $settings->inv_company_address ?? '';
        $agencyPhone = $settings->inv_company_phone ?? '';
        $agencyShortName = $this->getAgencyShortName($agencyName);

        // === CONTRACT DETAILS ===
        $effectiveDate = $contract->effective_date ? Carbon::parse($contract->effective_date) : now();
        $expirationDate = $contract->expiration_date ? Carbon::parse($contract->expiration_date) : now()->addMonths(12);
        $durationMonths = (int)($contract->duration_months ?? 12);
        // Use the contract's own effective date (not "now") when auto-generating
        // a contract number, so regenerating the same contract's PDF later
        // (e.g. in a different month/year) doesn't produce a different number.
        $contractNumber = $contract->contract_number ?? $this->generateContractNumber($contract, $agencyShortName, $effectiveDate);

        // === CLIENT INFO (PIHAK KEDUA) ===
        $clientName = $contract->client_name_full ?? '..................';
        $clientAddress = $contract->client_address ?? '..................';
        $clientPhone = $contract->client_phone ?? '';
        $clientEmail = $contract->client_email ?? '';
        $clientSignName = $contract->client_signatory_name ?? '..................';
        $clientSignTitle = $contract->client_signatory_title ?? '..................';
        $agencyDefault = self::defaultAgencySignatory($settings);
        $agencySignName = filled($contract->agency_signatory_name) ? $contract->agency_signatory_name : ($agencyDefault['name'] ?? '..................');
        $agencySignTitle = filled($contract->agency_signatory_title) ? $contract->agency_signatory_title : $agencyDefault['title'];

        // === PRICING ===
        $pricing = $this->pricing($contract);
        $totalValue = $pricing['total'];
        $formattedTotal = $this->formatRupiah($totalValue);
        $downPaymentPercent = (float)($contract->down_payment_percent ?? 0);
        $downPaymentValue = $pricing['dp'];
        $formattedDp = $this->formatRupiah($downPaymentValue);
        $remainingValue = $pricing['remaining'];
        $formattedRemaining = $this->formatRupiah($remainingValue);
        $installmentMonths = $pricing['months'];
        $monthlyValue = $pricing['monthly'];
        $formattedMonthly = $this->formatRupiah($monthlyValue);

        // === SCOPE OF WORK ===
        $scopeOfWork = $contract->scope_of_work ?? '';
        $projectDescription = $contract->project_description ?? '';

        // === ADDITIONAL CLAUSES ===
        $additionalClauses = $contract->content ?? '';
        $forceMajeureExtended = $contract->force_majeure_extended ?? false;
        $terminationDays = $contract->termination_notice_days ?? 30;
        $penaltyClause = $contract->penalty_clause ?? '';

        // === LAMPIRAN CUSTOMIZATION ===
        // Isian kosong memakai teks bawaan; {durasi} (atau {duration}) = jumlah bulan.
        $lampiranTitle = filled($contract->lampiran_title) ? $contract->lampiran_title : 'Lampiran 2 — Rincian Biaya Jasa';
        $lampiranDescription = filled($contract->lampiran_description) ? $contract->lampiran_description : self::TABLE_A_DESCRIPTION;
        $tableATitle = filled($contract->table_a_title) ? $contract->table_a_title : 'Tabel A';
        $tableBTitle = filled($contract->table_b_title) ? $contract->table_b_title : 'Tabel B';
        $tableBDescription = filled($contract->table_b_description) ? $contract->table_b_description : self::TABLE_B_DESCRIPTION;
        $tableAColumns = self::columnLabels($contract->table_a_columns, self::TABLE_A_COLUMNS);
        $tableBColumns = self::columnLabels($contract->table_b_columns, self::TABLE_B_COLUMNS);

        // === FORMAT: layout, kota penandatanganan, dasar kewenangan Para Pihak ===
        $layout = self::layout($contract);
        $signingCity = filled($contract->signing_city) ? $contract->signing_city : (filled($settings->contract_city ?? null) ? $settings->contract_city : '..................');
        $agencyLegalBasis = filled($settings->contract_agency_legal_basis ?? null) ? $settings->contract_agency_legal_basis : '..................';
        $clientLegalBasis = filled($contract->client_legal_basis) ? $contract->client_legal_basis : '..................';

        // === BANK INFO (dari Invoice Settings) ===
        $agencyBankName = $settings->inv_bank_name ?? 'Bank';
        $agencyBankAccount = $settings->inv_bank_account ?? '';
        $agencyBankAccountName = $settings->inv_bank_account_name ?? '';

        // === PAYMENT TYPE ===
        $paymentType = $contract->payment_type ?? 'full';

        // === KOP & KAKI SURAT: milik kontrak, lalu default di Pengaturan, lalu file bawaan ===
        $headerSrc = $this->letterhead($contract->header_image, $settings->contract_header ?? null, 'sites/header.png');
        $footerSrc = $this->letterhead($contract->footer_image, $settings->contract_footer ?? null, 'sites/footer.png');

        // === BUILD HTML ===
        return $this->buildHtml(compact(
            'logoHtml', 'agencyName', 'agencyAddress', 'agencyPhone', 'agencyShortName',
            'contractNumber', 'effectiveDate', 'expirationDate', 'durationMonths',
            'clientName', 'clientAddress', 'clientPhone', 'clientEmail',
            'clientSignName', 'clientSignTitle', 'agencySignName', 'agencySignTitle',
            'totalValue', 'formattedTotal', 'downPaymentPercent', 'formattedDp',
            'remainingValue', 'formattedRemaining', 'formattedMonthly', 'installmentMonths',
            'scopeOfWork', 'additionalClauses', 'paymentType', 'contract',
            'projectDescription', 'forceMajeureExtended', 'terminationDays', 'penaltyClause',
            'agencyBankName', 'agencyBankAccount', 'agencyBankAccountName',
            'lampiranTitle', 'lampiranDescription', 'tableATitle', 'tableBTitle', 'tableBDescription', 'tableAColumns', 'tableBColumns',
            'headerSrc', 'footerSrc', 'layout', 'signingCity', 'agencyLegalBasis', 'clientLegalBasis'
        ));
    }

    /**
     * HTML kontrak untuk dilihat di browser (pratinjau di samping form dan
     * tombol Preview HTML). Di PDF, kop dan kaki surat ditempel dompdf di setiap
     * halaman lewat path file dan position: fixed di area margin; browser tidak
     * bisa memuat path file dan menyembunyikan kop di luar layar. Di sini
     * keduanya tampil sekali di atas dan bawah, dan isinya selebar A4.
     */
    public function preview(Contract $contract): string
    {
        // Gambar di disk public (kop bawaan dan upload) lewat URL /storage/...
        $html = str_replace(
            Storage::disk('public')->path(''),
            parse_url(Storage::disk('public')->url(''), PHP_URL_PATH),
            $this->generate($contract),
        );

        return str_replace('</head>', '<style>
@media screen {
    html { background: #e5e7eb; }
    body { box-sizing: border-box; width: 794px; margin: 24px auto; padding: 0 70px 32px; background: #fff;
           box-shadow: 0 1px 4px rgba(0, 0, 0, .2); display: flex; flex-direction: column; }
    .header, .footer { position: static; height: auto; margin: 0 -70px; }
    .header { margin-bottom: 24px; }
    .footer { order: 1; margin-top: 32px; }
}
</style>
</head>', $html);
    }

    /**
     * Judul s.d. pasal terakhir dari template bawaan, untuk kontrak yang isinya
     * belum pernah diedit.
     */
    private function legacyBody(array $data): string
    {
        extract($data);

        $dayName = $this->getDayName($effectiveDate);
        $formattedEffectiveDate = $this->formatDateIndonesia($effectiveDate);

        // Running counter for "Pasal N" numbers in the optional/dynamic
        // clauses appended after Pasal 10, so numbering stays sequential
        // even if one of the optional clauses (additional / penalty) is empty.
        $nextArticleNumber = 11;

        $html = '  <div class="title">Perjanjian Kerja Sama</div>
  <div class="contract-number">' . htmlspecialchars($contractNumber) . '</div>

  <p>Perjanjian Kerja Sama ini (untuk selanjutnya disebut "Perjanjian") dibuat pada hari <strong>' . $dayName . '</strong>, tanggal <strong>' . $formattedEffectiveDate . '</strong>, oleh dan antara Para Pihak di bawah ini:</p>

  <div class="party-title">1. PIHAK PERTAMA</div>
  <div class="indent">
    <strong>' . htmlspecialchars($agencyName) . '</strong>, suatu perseroan terbatas yang didirikan menurut dan berdasarkan hukum Indonesia, berkedudukan di ' . nl2br(htmlspecialchars($agencyAddress)) . ' yang dalam hal ini diwakili oleh <strong>' . htmlspecialchars($agencySignName) . '</strong> dalam kedudukannya selaku <strong>' . htmlspecialchars($agencySignTitle) . '</strong> dan oleh karena itu sah bertindak untuk dan atas nama <strong>' . htmlspecialchars($agencyName) . '</strong>. Untuk selanjutnya disebut "<strong>Pihak Pertama</strong>", dan
  </div>

  <div class="party-title" style="margin-top: 0.2cm;">2. PIHAK KEDUA</div>
  <div class="indent">
    <strong>' . htmlspecialchars($clientName) . '</strong>, berkedudukan di ' . nl2br(htmlspecialchars($clientAddress)) . '. Dalam hal ini diwakili oleh <strong>' . htmlspecialchars($clientSignName) . '</strong>, dalam kedudukannya selaku <strong>' . htmlspecialchars($clientSignTitle) . '</strong>, dan oleh karena itu sah bertindak untuk dan atas nama <strong>' . htmlspecialchars($clientName) . '</strong>. Untuk selanjutnya disebut "<strong>Pihak Kedua</strong>",
  </div>

  <p style="margin-top: 0.2cm;">Pihak Pertama dan Pihak Kedua untuk selanjutnya secara bersama-sama dalam Perjanjian ini disebut sebagai "<strong>Para Pihak</strong>" dan masing-masing disebut sebagai "<strong>Pihak</strong>".</p>

  <!-- PASAL 1 -->
  <div class="article">
    <div class="article-title">Pasal 1 <br> Definisi</div>
    <p>Istilah-istilah berikut yang digunakan dalam Perjanjian ini memiliki pengertian sebagai berikut:</p>
    <ol class="article-list">
      <li><strong>Jasa</strong> adalah seluruh lingkup pekerjaan yang dilakukan oleh Pihak Pertama kepada Pihak Kedua sesuai dengan Lampiran 1 Perjanjian ini.</li>
      <li><strong>Biaya Jasa</strong> adalah besaran biaya yang harus dibayarkan oleh Pihak Kedua kepada Pihak Pertama sesuai dengan Lampiran 2 Perjanjian ini.</li>
      <li><strong>Pihak Ketiga</strong> adalah penyedia third-party yang dibutuhkan selama pekerjaan berjalan.</li>
      <li><strong>Invoice</strong> adalah surat penagihan yang dikeluarkan oleh Pihak Pertama kepada Pihak Kedua.</li>
      <li><strong>Hari Kerja</strong> adalah hari di luar hari Sabtu, Minggu dan hari libur nasional Indonesia.</li>
      <li><strong>Hari Kalender</strong> adalah setiap hari di kalender Gregorius termasuk Sabtu, Minggu dan hari libur nasional Indonesia.</li>
    </ol>
  </div>

  <!-- PASAL 2 -->
  <div class="article">
    <div class="article-title">Pasal 2 <br> Kewajiban Pihak Pertama</div>' .

(!empty($projectDescription) ? '

<table class="contract-table" style="margin-bottom:15px;">
    <tr>
        <th style="width:180px;">Deskripsi Proyek</th>
        <td>' . nl2br(htmlspecialchars($projectDescription)) . '</td>
    </tr>
</table>

' : '') . '
    <ol class="article-list">
      <li>Pihak Pertama akan memberikan Jasa untuk kepentingan Pihak Kedua sesuai dengan ruang lingkup pengerjaan yang tercantum dalam Lampiran 1 Perjanjian ini' . (!empty($scopeOfWork) ? ', yaitu: ' . nl2br(htmlspecialchars($scopeOfWork)) : '') . '.</li>
      <li>Pihak Pertama akan memberikan Jasa kepada Pihak Kedua dengan didasarkan pada jangka waktu <strong>' . $durationMonths . ' bulan</strong> pengerjaan terhitung dari pembayaran diterima oleh Pihak Pertama.</li>
      <li>Pekerjaan Jasa akan dimulai oleh Pihak Pertama dalam waktu 1 (satu) hari kerja setelah pembayaran pertama dibayarkan oleh Pihak Kedua.</li>
      <li>Dengan ditandatanganinya Perjanjian ini, Pihak Kedua dianggap telah memesan Jasa kepada Pihak Kedua, yang mana pemesanan tersebut tidak dapat dibatalkan tanpa persetujuan tertulis dari Pihak Pertama.</li>
      <li>Kegiatan kunjungan yang bersifat pengambilan konten yang berada di wilayah kerja di luar Jabodetabek, maka biaya operasional menjadi kewajiban Pihak Kedua.</li>
    </ol>
  </div>

  <!-- SOW TABLE -->
  ' . $this->buildSowTable($contract) . '

  <!-- PASAL 3 -->
  <div class="article">
    <div class="article-title">Pasal 3 <br> Kewajiban Pihak Kedua</div>
    <p>Untuk memastikan Pihak Pertama dapat memberikan Jasa kepada Pihak Kedua sesuai dengan kewajiban dan jangka waktu pengerjaan yang diatur di dalam Perjanjian ini maka Pihak Kedua wajib memenuhi kewajiban pembayaran biaya jasa sebagaimana diatur di dalam Lampiran 1.</p>
  </div>

  <!-- PASAL 4 -->
  <div class="article">
    <div class="article-title">Pasal 4 <br> Biaya Jasa dan Pembayaran</div>
    <ol class="article-list">
      <li>Para Pihak sepakat bahwa untuk Biaya Jasa adalah mengacu pada Lampiran 2 Tabel A dari Perjanjian ini.</li>
      <li>Biaya Jasa tidak dapat diubah tanpa persetujuan tertulis terlebih dahulu dari Para Pihak.</li>
      <li>Pembayaran wajib dilakukan oleh Pihak Kedua kepada Pihak Pertama sesuai dengan jangka waktu pembayaran yang diatur di dalam Lampiran 2 Tabel A dari Perjanjian ini.</li>
      <li>Pembayaran wajib dilakukan oleh Pihak Kedua ke rekening Pihak Pertama sebagai berikut:<br>
        <strong>Nama Bank</strong> : ' . htmlspecialchars($agencyBankName) . '<br>
        <strong>No. Rek</strong> : ' . htmlspecialchars($agencyBankAccount) . '<br>
        <strong>Nama Perusahaan</strong> : ' . htmlspecialchars($agencyBankAccountName) . '
      </li>
      <li>Pembayaran dari Pihak Kedua dianggap sah apabila dana telah diterima di rekening pihak pertama.</li>
      <li>Segala pembayaran yang telah diterima oleh Pihak Pertama tidak dapat dikembalikan kepada Pihak Kedua dengan alasan apapun juga.</li>
    </ol>
  </div>

  <!-- PASAL 5 -->
  <div class="article">
    <div class="article-title">Pasal 5 <br> Kerahasiaan</div>
    <ol class="article-list">
      <li>Para Pihak wajib untuk merahasiakan seluruh informasi, data dan fakta yang diperoleh selama berlangsungnya Perjanjian ini sehingga hanya dipergunakan untuk keperluan internal dalam pelaksanaan pekerjaan sejak tanggal Perjanjian ini sampai dengan selesai, kecuali dipersyaratkan atau diperintahkan oleh aparat berwenang termasuk pengadilan atau berdasarkan peraturan yang berlaku.</li>
      <li>Para Pihak sepakat dan saling menjamin bahwa karyawannya, agennya, penasihatnya dan/atau pihak-pihak yang terkait lainnya tidak akan memberikan Informasi Rahasia kepada siapapun tanpa persetujuan tertulis dari Pihak lainnya, baik selama berlangsungnya Perjanjian sampai dengan 3 (tiga) tahun setelah Perjanjian ini berakhir atau diakhiri.</li>
    </ol>
  </div>

  <!-- PASAL 6 -->
  <div class="article">
    <div class="article-title">Pasal 6 <br> Force Majeure</div>
    <ol class="article-list">
      <li>Tidak dilaksanakannya atau tertundanya pelaksanaan sebagian atau keseluruhan ketentuan Perjanjian ini oleh salah satu pihak tidak termasuk sebagai pelanggaran Perjanjian ini apabila hal tersebut disebabkan oleh adanya Force Majeure, maka Para Pihak sepakat untuk menyelesaikannya melalui musyawarah mufakat.</li>
      <li>Yang termasuk sebagai peristiwa Force Majeure adalah sebagai berikut:<br>
        a. Bencana alam / wabah penyakit / angin taufan / banjir / tanah longsor / gempa bumi<br>
        b. Huru-hara / perang / pemberontakan<br>
        c. Pemogokan umum / sabotase / kebakaran<br>
        d. Perubahan peraturan perundang-undangan atau kebijaksanaan pemerintah yang mengakibatkan Perjanjian ini tidak dapat dilaksanakan<br>
        e. Hal-hal lain di luar kontrol dari salah satu pihak
      </li>
      <li>Dalam hal terjadi peristiwa sebagaimana dimaksud dalam ayat 1 Pasal ini, Para Pihak secara bersama-sama maupun sendiri-sendiri dengan dilandasi iktikad baik akan melakukan upaya semaksimal mungkin agar akibat dari peristiwa tersebut dapat ditekan seminimal mungkin.</li>
      <li>Semua kerugian dan biaya yang diderita oleh salah satu Pihak sebagai akibat terjadinya Force Majeure tidak menjadi tanggung jawab pihak lainnya.</li>
      <li>Untuk menghindari keragu-raguan, kewajiban Pihak Kedua untuk melakukan pembayaran kepada Pihak Pertama, termasuk pembayaran denda, tidak dapat ditunda, dikurangi dengan alasan Force Majeure.</li>' .
      ($forceMajeureExtended ? '
      <li>Termasuk pula sebagai peristiwa Force Majeure adalah gangguan yang bersifat sistemik di luar kendali Para Pihak, antara lain namun tidak terbatas pada gangguan siber berskala luas, gangguan infrastruktur telekomunikasi/internet nasional, kebijakan pembatasan wilayah (lockdown) oleh pemerintah, dan gangguan rantai pasok global, yang secara material menghalangi pelaksanaan kewajiban salah satu Pihak.</li>' : '') . '
    </ol>
  </div>

  <!-- PASAL 7 -->
  <div class="article">
    <div class="article-title">Pasal 7 <br> Jangka Waktu dan Pengakhiran Perjanjian</div>
    <ol class="article-list">
      <li>Perjanjian ini mulai berlaku dari <strong>' . $dayName . ', ' . $formattedEffectiveDate . '</strong> sampai dengan seluruh kewajiban dari masing-masing Pihak sebagaimana diatur di dalam Perjanjian ini telah terpenuhi kecuali diakhiri menurut ketentuan ayat 2 di bawah ini.</li>
      <li>Para Pihak memiliki hak untuk mengakhiri Perjanjian dengan pemberitahuan tertulis kepada pihak lain, <strong>' . $terminationDays . ' hari kalender</strong> sebelum tanggal efektif pengakhiran Perjanjian dengan alasan sebagai berikut:<br>
        a. Salah satu pihak gagal untuk memperbaiki kesalahannya selama ' . $terminationDays . ' hari kalender sejak menerima surat peringatan dari pihak lain, atau<br>
        b. Perjanjian diakhiri berdasarkan kesepakatan Para Pihak.
      </li>
      <li>Perjanjian layanan ini dapat diakhiri oleh Pihak Pertama secara sepihak dengan pemberitahuan tertulis apabila Pihak Kedua lalai membayar lebih dari 14 (empat belas) hari kalender setelah jatuh tempo, kecuali disepakati lain oleh Pihak Pertama.</li>
      <li>Para Pihak sepakat untuk mengesampingkan ketentuan Pasal 1266 Kitab Undang-Undang Hukum Perdata Republik Indonesia untuk mengakhiri Perjanjian ini.</li>
      <li>Dalam hal terjadi pengakhiran Perjanjian maka segala tagihan dari Pihak Pertama kepada Pihak Kedua menjadi jatuh tempo dan Pihak Kedua berkewajiban untuk melakukan pembayaran kepada Pihak Pertama dalam jangka waktu paling lambat 14 hari kalender sejak tanggal surat pemberitahuan dikirimkan.</li>
      <li>Setelah pengakhiran, pembayaran apa pun yang telah dilakukan oleh Pihak Kedua kepada Pihak Pertama tidak dapat dikembalikan.</li>
      <li>Kewajiban Pihak Kedua untuk melakukan pembayaran kepada Pihak Pertama tetap mengikat Pihak Kedua meski Perjanjian ini diakhiri.</li>
    </ol>
  </div>

  <!-- PASAL 8 -->
  <div class="article">
    <div class="article-title">Pasal 8 <br> Pilihan Hukum dan Penyelesaian Perselisihan</div>
    <ol class="article-list">
      <li>Perjanjian ini wajib ditafsirkan dan dilaksanakan berdasarkan hukum Republik Indonesia.</li>
      <li>Jika terjadi perselisihan atau sengketa di antara Para Pihak mengenai atau sehubungan dengan Perjanjian ini, para Pihak akan berusaha untuk menyelesaikan perselisihan atau sengketa tersebut secara musyawarah.</li>
      <li>Apabila sengketa tidak dapat diselesaikan dalam jangka waktu 30 (tiga puluh) hari kalender sejak Para Pihak memulai negosiasi, maka salah satu Pihak atau Para Pihak secara bersama-sama dapat mengajukan perselisihan atau sengketa tersebut kepada arbitrase untuk penyelesaian secara eksklusif dan terakhir. Arbitrase dilaksanakan di Jakarta, Indonesia, sesuai dengan peraturan Badan Arbitrase Nasional Indonesia (BANI), dengan menggunakan Bahasa Indonesia di hadapan 3 (tiga) orang arbiter; masing-masing Pihak akan menunjuk 1 (satu) orang arbiter dan kedua orang arbiter tersebut yang nantinya akan menunjuk arbiter ketiga yang akan bertindak sebagai ketua arbitrase.</li>
      <li>Ketentuan ayat (3) mengikat Para Pihak untuk menyerahkan semua Perselisihan yang tidak dapat diselesaikan secara musyawarah kepada keputusan Badan Arbitrase yang bersifat final dan mengikat sesuai dengan ketentuan Undang-Undang Arbitrase. Keputusan Badan Arbitrase bersifat final, mengikat dan tidak dapat dilakukan upaya banding, serta dapat digunakan sebagai dasar untuk pelaksanaan putusan di Indonesia dan dimanapun juga.</li>
      <li>Selama jangka waktu pengajuan penyelesaian Perselisihan kepada Badan Arbitrase dan sampai dihasilkan keputusan Badan Arbitrase, Para Pihak akan melanjutkan pemenuhan kewajibannya berdasarkan Perjanjian, tanpa mengurangi keputusan final yang diputuskan oleh Badan Arbitrase tersebut.</li>
    </ol>
  </div>

  <!-- PASAL 9 -->
  <div class="article">
    <div class="article-title">Pasal 9 <br> Pengalihan Perjanjian</div>
    <p>Para Pihak sepakat untuk tidak mengalihkan sebagian atau keseluruhan dari isi Perjanjian ini kepada Pihak Lainnya kecuali atas kesepakatan tertulis Para Pihak.</p>
  </div>

  <!-- PASAL 10 -->
  <div class="article">
    <div class="article-title">Pasal 10 <br> Lain-Lain</div>
    <ol class="article-list">
      <li>Segala sesuatu yang belum diatur di dalam Perjanjian ini, atau adanya tambahan / perubahan-perubahan yang disepakati oleh Para Pihak, maka akan dibuatkan perjanjian tambahan / addendum / atau amandemen perjanjian secara tertulis yang disetujui dan ditandatangani oleh Para Pihak di atas serta merupakan bagian yang tak terpisahkan dari isi Perjanjian ini.</li>
      <li>Apabila suatu ketentuan dari Perjanjian ini atau bagian darinya dinyatakan tidak sah, tidak berlaku, tidak dapat dilaksanakan, bertentangan dengan ketentuan hukum yang berlaku, maka ketidakabsahan, ketidakberlakuan, tidak dapat dilaksanakannya, pertentangan, atau ketidaksesuaian tersebut hanya berlaku terhadap ketentuan tersebut atau bagian dari ketentuan tersebut, sedangkan keabsahan, keberlakuan, dan pelaksanaan bagian lain dari ketentuan tersebut serta ketentuan-ketentuan lain dari Perjanjian ini tidak terpengaruh atau terganggu dan oleh karenanya tetap berlaku secara penuh dan sah.</li>
      <li>Perjanjian ini akan mengikat atas dan timbul untuk kepentingan Para Pihak berikut pengganti dan penerus masing-masing Pihak. Hak-hak dan kewajiban-kewajiban Para Pihak yang timbul berdasarkan Perjanjian ini tidak dapat dipindahkan atau dengan cara apapun dialihkan kepada pihak ketiga lain manapun tanpa adanya persetujuan tertulis dari Pihak lainnya dalam Perjanjian ini.</li>
      <li>Tiada satu pihak pun yang bertanggung jawab untuk semua kerugian baik langsung maupun tidak langsung yang diderita oleh pihak lain sehubungan dengan pelaksanaan Perjanjian ini.</li>
      <li>Segala lampiran dari Perjanjian ini merupakan bagian yang tidak terpisahkan dari Perjanjian ini.</li>
      <li>Sehubungan dengan Perjanjian ini dan akibat hukumnya, masing-masing pihak menyatakan dan menjamin hal-hal sebagai berikut:<br>
        a. Masing-masing pihak adalah pihak-pihak yang berhak, berwenang, memiliki izin-izin yang diperlukan dan/atau mempunyai kemampuan untuk melaksanakan kegiatan usahanya dan/atau kewajibannya berdasarkan Perjanjian ini.<br>
        b. Perwakilan masing-masing pihak yang menandatangani Perjanjian ini adalah pihak yang berwenang bertindak untuk dan atas nama masing-masing pihak dan telah memperoleh izin-izin yang diperlukan untuk mewakili masing-masing pihak termasuk untuk menandatangani dan melaksanakan Perjanjian ini.<br>
        c. Penandatanganan dan pelaksanaan Perjanjian ini tidak melanggar Perjanjian-perjanjian lain yang mengikat Para Pihak.
      </li>
      <li>Apabila pernyataan-pernyataan pada ayat 6 di atas, ternyata di kemudian hari terbukti tidak benar dan/atau menyesatkan dan menimbulkan kerugian terhadap pihak lainnya, maka pihak yang mengakibatkan timbulnya kerugian tersebut wajib mengganti segala kerugian pihak lainnya sebagai akibat dari pernyataan yang tidak benar dan/atau menyesatkan tersebut.</li>
    </ol>
  </div>';

        // Additional clauses (Pasal number assigned dynamically so it stays
        // sequential even if the penalty clause below is the only one present)
        if (!empty($additionalClauses)) {
            $html .= '
  <div class="article">
    <div class="article-title">Pasal ' . $nextArticleNumber . ' <br> Ketentuan Tambahan</div>
    <div style="white-space: pre-wrap; text-align: justify;">' . nl2br(htmlspecialchars($additionalClauses)) . '</div>
  </div>';
            $nextArticleNumber++;
        }

        // Penalty clause
        if (!empty($penaltyClause)) {
            $html .= '
  <div class="article">
    <div class="article-title">Pasal ' . $nextArticleNumber . ' <br> Klausul Denda</div>
    <div style="white-space: pre-wrap; text-align: justify;">' . nl2br(htmlspecialchars($penaltyClause)) . '</div>
  </div>';
            $nextArticleNumber++;
        }

        return $html;
    }

    /**
     * Judul, pembukaan, dan pasal yang diedit di form. Nomor pasal mengikuti
     * urutannya; pasal tanpa judul dan isi dilewati.
     */
    private function customBody(array $data): string
    {
        $contract = $data['contract'];

        $html = '
  <div class="title">' . htmlspecialchars($contract->document_title ?: 'Perjanjian Kerja Sama') . '</div>
  <div class="contract-number">' . $this->fillText($data['layout']['number_line'], $data) . '</div>
  <div class="rich">' . $this->fillPlaceholders($contract->opening_text ?? '', $data) . '</div>';

        $number = 1;
        foreach ($contract->articles as $article) {
            $title   = trim((string) ($article['title'] ?? ''));
            $content = (string) ($article['content'] ?? '');

            if ($title === '' && trim(strip_tags($content)) === '') {
                continue;
            }

            $html .= '
  <div class="article">
    <div class="article-title">Pasal ' . $number++ . ($title !== '' ? ' <br> ' . htmlspecialchars($title) : '') . '</div>
    <div class="rich">' . $this->fillPlaceholders($content, $data) . '</div>
  </div>';
        }

        return $html;
    }

    /**
     * HTML dari RichEditor, dibersihkan dulu (tanpa skrip/atribut berbahaya),
     * lalu penanda {nama_klien} dkk. diganti nilai kontrak.
     */
    private function fillPlaceholders(string $html, array $data): string
    {
        extract($data);

        $projectTable = filled($projectDescription) ? '<table class="contract-table" style="margin-bottom:15px;">
    <tr>
        <th style="width:180px;">Deskripsi Proyek</th>
        <td>' . nl2br(htmlspecialchars($projectDescription)) . '</td>
    </tr>
</table>' : '';

        $values = [
            'nomor_kontrak'         => htmlspecialchars($contractNumber),
            'hari'                  => $this->getDayName($effectiveDate),
            'tanggal'               => $this->formatDateIndonesia($effectiveDate),
            'tanggal_berakhir'      => $this->formatDateIndonesia($expirationDate),
            'durasi'                => (string) $durationMonths,
            'hari_pengakhiran'      => htmlspecialchars((string) $terminationDays),
            'nama_agensi'           => htmlspecialchars($agencyName),
            'alamat_agensi'         => nl2br(htmlspecialchars($agencyAddress)),
            'penanda_tangan_agensi' => htmlspecialchars($agencySignName),
            'jabatan_agensi'        => htmlspecialchars($agencySignTitle),
            'nama_klien'            => htmlspecialchars($clientName),
            'alamat_klien'          => nl2br(htmlspecialchars($clientAddress)),
            'penanda_tangan_klien'  => htmlspecialchars($clientSignName),
            'jabatan_klien'         => htmlspecialchars($clientSignTitle),
            'bank'                  => htmlspecialchars($agencyBankName),
            'no_rekening'           => htmlspecialchars($agencyBankAccount),
            'nama_rekening'         => htmlspecialchars($agencyBankAccountName),
            'nilai_kontrak'         => $formattedTotal,
            'dp'                    => $formattedDp,
            'cicilan'               => $formattedMonthly,
            'deskripsi_proyek'      => $projectTable,
            'deskripsi_singkat'     => filled($projectDescription) ? nl2br(htmlspecialchars($projectDescription)) : '..................',
            'tabel_ruang_lingkup'   => $this->buildSowTable($contract),
            'kota'                  => htmlspecialchars($signingCity),
            'dasar_hukum_agensi'    => nl2br(htmlspecialchars($agencyLegalBasis)),
            'dasar_hukum_klien'     => nl2br(htmlspecialchars($clientLegalBasis)),
            'durasi_terbilang'      => Terbilang::withNumber($durationMonths),
            'jumlah_cicilan'        => (string) $installmentMonths,
            'duration'              => (string) $durationMonths, // nama lama di keterangan lampiran
        ];

        return strtr(Str::sanitizeHtml($html), array_combine(
            array_map(fn (string $key) => '{' . $key . '}', array_keys($values)),
            $values,
        ));
    }

    /** Teks biasa (judul, catatan, baris nomor) dengan penanda isian. */
    private function fillText(?string $text, array $data): string
    {
        return $this->fillPlaceholders(nl2br(htmlspecialchars((string) $text)), $data);
    }

    /**
     * Path file gambar (disk public) untuk dompdf. URL (pratinjau upload yang
     * belum disimpan) dipakai apa adanya.
     */
    private function letterhead(?string ...$candidates): ?string
    {
        foreach ($candidates as $path) {
            if (blank($path)) {
                continue;
            }

            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }

            // Disk bawaan lebih dulu: kop lama dibaca lewat Storage::path() (disk bawaan),
            // upload Filament ada di disk Filament.
            foreach (array_unique([config('filesystems.default'), config('filament.default_filesystem_disk', 'public'), 'public']) as $disk) {
                if (Storage::disk($disk)->exists($path)) {
                    return Storage::disk($disk)->path($path);
                }
            }
        }

        return null;
    }

    private function getLogoHtml(GeneralSettings $settings): string
    {
        // Use inv_logo (Invoice logo) first, then contract_logo, then brand_logo
        $logoPath = $settings->inv_logo ?? $settings->contract_logo ?? $settings->brand_logo ?? null;

        if ($logoPath && Storage::exists($logoPath)) {
            $url = Storage::url($logoPath);
            return '<img src="' . htmlspecialchars($url) . '" alt="Logo" style="height: 90px;">';
        }

        // Try alternative path for media library
        if ($logoPath) {
            $altPath = str_replace('sites/', 'public/sites/', $logoPath);
            if (Storage::exists($altPath)) {
                $url = Storage::url($altPath);
                return '<img src="' . htmlspecialchars($url) . '" alt="Logo" style="height: 90px;">';
            }
        }

        return '';
    }

    private function getAgencyShortName(string $name): string
    {
        // Extract acronym from company name
        $words = explode(' ', $name);
        $short = '';
        foreach ($words as $word) {
            $upper = strtoupper($word);
            if (strlen($word) > 2 && !in_array($upper, ['PT', 'PT.', 'CV', 'LTD', 'LLC'])) {
                $short .= $upper[0];
            }
        }
        return $short ?: strtoupper(substr($name, 0, 5));
    }

    private function breakEveryWords(string $text, int $wordsPerLine = 8): string
    {
        $words = preg_split('/\s+/', trim($text));

        return implode('<br>', array_map(
            fn ($chunk) => implode(' ', $chunk),
            array_chunk($words, $wordsPerLine)
        ));
    }

    /**
     * Format a numeric value as Indonesian Rupiah (e.g. "Rp 1.000.000").
     * Centralized here so every place that prints a money value formats
     * it identically.
     */
    private function formatRupiah(float $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }

    private function generateContractNumber(Contract $contract, string $shortName, ?Carbon $referenceDate = null): string
    {
        $referenceDate ??= now();
        $prefix = '001';
        $month = $referenceDate->format('m');
        $year = $referenceDate->format('y');
        return $prefix . '/SPK/' . $month . $year . '/' . strtoupper($shortName);
    }

    private function buildHtml(array $data): string
    {
        extract($data);

        // Isi yang diedit di form (articles terisi) atau template bawaan untuk
        // kontrak yang belum pernah diedit, supaya PDF lamanya tidak berubah.
        $custom = is_array($contract->articles);
        $body = $custom ? $this->customBody($data) : $this->legacyBody($data);
        // Ruang lingkup sebagai halaman Lampiran 1 (format PKS), hanya untuk isi yang diedit di form.
        $scopeAppendix = $custom && $layout['scope_appendix'] ? $this->buildScopeAppendix($data) : '';
        $closing = $custom
            ? '<div class="rich" style="margin-top: 0.8cm;">' . $this->fillPlaceholders($contract->closing_text ?? '', $data) . '</div>'
            : '<p style="margin-top: 0.8cm;">Perjanjian ini dibuat dalam rangkap 2 (dua) asli yang masing-masing dibubuhi materai secukupnya dan ditandatangani sebagaimana mestinya oleh Para Pihak, dan masing-masing mempunyai kekuatan hukum yang sama.</p>';

        $html = '<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Perjanjian Kerja Sama</title>
  <style>
    @page {
    size: A4;
        margin: 120px 70px 90px 70px;

}

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11pt;
    line-height: 1.6;
    color: #000;
    margin: 0;
    padding: 0;
}

/* =========================
   HEADER
========================= */

.header {
    position: fixed;
    top: -120px;
    left: -40px;
    right: -40px;
    height: 110px;
}

.header img {
    width: 100%;
    height: auto;
    display: block;
}

/* =========================
   FOOTER
========================= */

.footer {
    position: fixed;
    bottom: -60px;
    left: -40px;
    right: -40px;
    height: 80px;
}

.footer img {
    width: 100%;
    height: auto;
    display: block;
}

/* =========================
   CONTENT
========================= */

.content {
    padding: 0;
    margin: 0;
}

/* =========================
   TITLE
========================= */

.title {
    font-size: 14pt;
    font-weight: bold;
    text-align: center;
    text-transform: uppercase;
    margin: 0 0 0.2cm 0;
}

.contract-number {
    font-size: 10pt;
    text-align: center;
    margin-bottom: 0.3cm;
}

/* =========================
   PARAGRAPH
========================= */

p {
    text-align: justify;
    margin: 0 0 0.15cm 0;
    line-height: 1.6;
}

.party-title {
    font-weight: bold;
    margin: 0.35cm 0 0.15cm 0;
}

.indent {
    text-align: justify;
    text-indent: 1cm;
    line-height: 1.6;
    margin-bottom: 0.25cm;
}

.indent-2 {
    text-align: justify;
    text-indent: 2cm;
    line-height: 1.6;
    margin-bottom: 0.25cm;
}

/* =========================
   ARTICLE
========================= */

.article {
    margin-bottom: 0.35cm;
    text-align: justify;
    line-height: 1.6;
    page-break-inside: avoid;
}

.article-title {
    font-weight: bold;
    text-transform: uppercase;
    text-align: center;
    font-size: 11pt;
    line-height: 1.5;
    margin: 0 0 0.3cm 0;
}

/* =========================
   LIST
========================= */

.article-list {
    margin: 0;
    padding-left: 0.8cm;
}

.article-list li {
    margin-bottom: 0.18cm;
    text-align: justify;
    line-height: 1.6;
    padding-left: 0.2cm;
}

.sub-list {
    margin-top: 0.15cm;
    margin-bottom: 0.15cm;
    padding-left: 0.8cm;
}

.sub-list li {
    margin-bottom: 0.12cm;
    text-align: justify;
    line-height: 1.6;
    padding-left: 0.2cm;
}

/* =========================
   TABLE
========================= */

table {
    width: 100%;
    border-collapse: collapse;
    margin: 0.15cm 0;
    font-size: 10pt;
}

table th,
table td {
    border: 1px solid #000;
    padding: 0.12cm 0.15cm;
    text-align: left;
    line-height: 1.5;
    vertical-align: top;
}

table th {
    background: #f5f5f5;
    font-weight: bold;
    text-align: center;
}

/* =========================
   SIGNATURE
========================= */

.signature-section {
    margin-top: 1cm;
    page-break-inside: avoid;
    break-inside: avoid;
}

.signature-line {
    margin-top: 1.5cm;
    padding-bottom: 2px;
    border-bottom: 1px solid #000;
    display: inline-block;
    min-width: 4cm;
}

/* =========================
   LAMPIRAN
========================= */

.lampiran {
    page-break-before: always;
}

.lampiran-continue {
    page-break-before: auto;
    margin-top: 0.8cm;
}

.scope-heading {
    font-weight: bold;
    margin: 0.35cm 0 0.1cm;
}

.scope-note {
    margin: 0 0 0.15cm;
}

.lampiran-title {
    font-size: 12pt;
    font-weight: bold;
    text-transform: uppercase;
    text-align: center;
    margin: 0.5cm 0 0.3cm;
}

.table-a-title,
.table-b-title {
    font-weight: bold;
    font-size: 10pt;
    margin: 0.3cm 0 0.2cm;
}

/* =========================
   UTILITIES
========================= */

.text-center {
    text-align: center;
}

.text-right {
    text-align: right;
}

.font-bold {
    font-weight: bold;
}

.note {
    font-size: 9pt;
    margin-top: 0.2cm;
    line-height: 1.5;
}

.contract-table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
    margin-bottom:10px;
    font-size:10pt;
}

.contract-table th,
.contract-table td{
    border:1px solid #000;
    padding:6px;
    vertical-align:top;
}

.contract-table th{
    background:#f2f2f2;
    text-align:center;
    font-weight:bold;
}

/* =========================
   ISI YANG DIEDIT DI FORM (RichEditor)
========================= */

.rich > div { text-align: justify; margin: 0 0 0.15cm 0; line-height: 1.6; }
.rich ol, .rich ul { margin: 0 0 0.15cm 0; padding-left: 0.8cm; }
.rich li { margin-bottom: 0.18cm; text-align: justify; line-height: 1.6; padding-left: 0.2cm; }
.rich h1, .rich h2, .rich h3 { font-size: 11pt; margin: 0.2cm 0 0.1cm 0; }
.rich blockquote { margin: 0 0 0.15cm 0.8cm; }
  </style>
</head>
<body>

' . ($headerSrc ? '<div class="header">
    <img src="' . htmlspecialchars($headerSrc) . '" alt="Header">
</div>' : '') . '

' . ($footerSrc ? '<div class="footer">
    <img src="' . htmlspecialchars($footerSrc) . '" alt="Footer">
</div>' : '') . '

<!-- CONTENT -->
<div class="content">
' . $body;

        // SIGNATURE
        $html .= '
  <div class="signature-section">
    ' . $closing . '

    <table style="width: 100%; border: none; margin-top: 0.8cm;">
      <tr>
        ' . $this->signatureCell($layout['signature_first_label'], $agencyName, $agencySignName, $agencySignTitle, $layout['signature_show_title'], 'padding-right: 20px;') . '
        ' . $this->signatureCell($layout['signature_second_label'], $clientName, $clientSignName, $clientSignTitle, $layout['signature_show_title'], 'padding-left: 20px;') . '
      </tr>
    </table>
  </div>
</div>
' . $scopeAppendix . '
<!-- LAMPIRAN (page baru; menyambung Lampiran 1 bila ruang lingkup jadi lampiran) -->
<div class="lampiran' . ($scopeAppendix !== '' ? ' lampiran-continue' : '') . '">
  <div class="lampiran-title">' . $this->fillText($lampiranTitle, $data) . '</div>

  <div class="table-a-title">' . $this->fillText($tableATitle, $data) . '</div>
  ' . ($layout['table_a_show_description'] ? '<div class="note">' . $this->fillText($lampiranDescription, $data) . '</div>' : '') . '

  <table>
    <thead>
      <tr>
        <th style="width: 25px; text-align: center;">' . htmlspecialchars($tableAColumns['no']) . '</th>
        <th>' . htmlspecialchars($tableAColumns['item']) . '</th>
        <th style="width: 150px;">' . htmlspecialchars($tableAColumns['estimate']) . '</th>
        <th style="width: 100px;">' . htmlspecialchars($tableAColumns['date']) . '</th>
        <th style="width: 120px; text-align: right;">' . htmlspecialchars($tableAColumns['amount']) . '</th>
      </tr>
    </thead>
    <tbody>
      ' . $this->buildFeeTableFromItems($contract) . '
    </tbody>
  </table>' . (filled($layout['table_a_note']) ? '
  <div class="note">' . $this->fillText($layout['table_a_note'], $data) . '</div>' : '');

        // Table a (Third Party) - only show if has_third_party is true
        if ($contract->has_third_party) {
            $html .= '
  <div class="table-b-title">' . $this->fillText($tableBTitle, $data) . '</div>
  <div class="note">' . $this->fillText($tableBDescription, $data) . '</div>

  <table>
    <thead>
      <tr>
        <th style="width: 25px; text-align: center;">' . htmlspecialchars($tableBColumns['no']) . '</th>
        <th>' . htmlspecialchars($tableBColumns['item']) . '</th>
        <th style="width: 120px; text-align: right;">' . htmlspecialchars($tableBColumns['amount']) . '</th>
      </tr>
    </thead>
    <tbody>
      ' . $this->buildThirdPartyTableFromItems($contract) . '
    </tbody>
  </table>';
        }

        $html .= '
</div>

</body>
</html>';

        return $html;
    }

    /**
     * Baris Tabel A: item biaya kontrak (Estimasi = teks sendiri atau sama
     * dengan Jumlah), atau baris bawaan dari tipe pembayaran bila belum ada.
     * Baris total memakai nilai kontrak dan labelnya bisa diganti.
     */
    private function buildFeeTableFromItems(Contract $contract): string
    {
        $items = $contract->feeItems ?? collect();

        $rows = $items->isEmpty()
            ? $this->defaultFeeRows($contract)
            : $items->values()->map(function ($item) {
                $amount = $item->amount ? $this->formatRupiah((float) $item->amount) : '-';

                return [
                    'item'     => $item->item ?? '-',
                    'estimate' => filled($item->estimate) ? $item->estimate : $amount,
                    'due_date' => $item->due_date ?? '-',
                    'amount'   => $item->amount ? (float) $item->amount : null,
                ];
            })->all();

        $html = '';

        foreach ($rows as $index => $row) {
            $html .= '<tr>
                    <td class="text-center">' . ($index + 1) . '</td>
                    <td>' . htmlspecialchars((string) $row['item']) . '</td>
                    <td>' . htmlspecialchars((string) $row['estimate']) . '</td>
                    <td>' . htmlspecialchars((string) $row['due_date']) . '</td>
                    <td class="text-right">' . ($row['amount'] !== null ? $this->formatRupiah($row['amount']) : '-') . '</td>
                  </tr>';
        }

        $label = filled($contract->table_a_total_label) ? $contract->table_a_total_label : self::TOTAL_LABEL;
        $html .= '<tr>
            <td colspan="4" class="text-left font-bold">' . htmlspecialchars($label) . '</td>
            <td class="text-right font-bold">' . $this->formatRupiah((float) ($contract->total_value ?? 0)) . '</td>
          </tr>';

        return $html;
    }

    private function buildThirdPartyTableFromItems(Contract $contract): string
    {
        $items = $contract->thirdPartyItems ?? collect();
        $html = '';

        if ($items->isEmpty()) {
            $html .= '<tr>
                <td class="text-center">-</td>
                <td>-</td>
                <td class="text-right">-</td>
              </tr>';
        } else {
            foreach ($items as $index => $item) {
                $amount = $item->amount ? $this->formatRupiah((float)$item->amount) : '-';
                $html .= '<tr>
                    <td class="text-center">' . ($index + 1) . '</td>
                    <td>' . htmlspecialchars($item->item ?? '-');
                if ($item->notes) {
                    $html .= '<br><small style="color: #666;">' . htmlspecialchars($item->notes) . '</small>';
                }
                $html .= '</td>
                    <td class="text-right">' . $amount . '</td>
                  </tr>';
            }
        }

        return $html;
    }

    private function getDayName(Carbon $date): string
    {
        $days = [
            'Sunday' => 'Minggu',
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
        ];
        return $days[$date->format('l')] ?? $date->format('l');
    }

    private function formatDateIndonesia(Carbon $date): string
    {
        $months = [
            'January' => 'Januari',
            'February' => 'Februari',
            'March' => 'Maret',
            'April' => 'April',
            'May' => 'Mei',
            'June' => 'Juni',
            'July' => 'Juli',
            'August' => 'Agustus',
            'September' => 'September',
            'October' => 'Oktober',
            'November' => 'November',
            'December' => 'Desember',
        ];

        $day = $date->format('d');
        $month = $months[$date->format('F')] ?? $date->format('F');
        $year = $date->format('Y');

        return $day . ' ' . $month . ' ' . $year;
    }

    /**
     * Satu kolom tanda tangan. Tanpa jabatan: label, perusahaan, nama, garis
     * (tampilan lama). Dengan jabatan (format PKS): label, perusahaan, ruang
     * tanda tangan, lalu nama tebal dan jabatannya.
     */
    private function signatureCell(string $label, string $company, string $name, string $title, bool $showTitle, string $padding): string
    {
        $line = '<div style="margin-top: 1.2cm; padding-bottom: 0.05cm; border-bottom: 1px solid #000; display: inline-block; min-width: 4cm;"></div>';

        return '<td style="width: 50%; border: none; vertical-align: top; ' . $padding . '">
          <p style="margin-bottom: 0.3cm;"><strong>' . htmlspecialchars($label) . '</strong></p>
          <p style="margin-bottom: 0.05cm;">' . htmlspecialchars($company) . '</p>'
            . ($showTitle
                ? '
          ' . $line . '
          <p style="margin: 0.15cm 0 0;"><strong>' . htmlspecialchars($name) . '</strong></p>
          <p style="margin: 0;">' . htmlspecialchars($title) . '</p>'
                : '
          <p style="margin-bottom: 0.05cm;">' . htmlspecialchars($name) . '</p>
          ' . $line) . '
        </td>';
    }

    /**
     * Lampiran ruang lingkup di halaman sendiri: judul, pembuka, lalu tiap
     * kategori bernomor (1., 2., ...) dengan catatan kategori dan tabel
     * sub-item (1.1, 1.2, ...), lalu penutup (mis. pengecualian).
     */
    private function buildScopeAppendix(array $data): string
    {
        $contract   = $data['contract'];
        $layout     = $data['layout'];
        $columns    = $layout['scope_columns'];
        $categories = ($contract->sowCategories ?? collect())->values();

        $html = '
<div class="lampiran">
  <div class="lampiran-title">' . $this->fillText($layout['scope_title'], $data) . '</div>';

        if (filled(trim(strip_tags((string) $layout['scope_intro'])))) {
            $html .= '
  <div class="rich">' . $this->fillPlaceholders($layout['scope_intro'], $data) . '</div>';
        }

        $table = function (iterable $rows, string $prefix) use ($columns): string {
            $html = '
  <table class="contract-table">
    <thead><tr><th style="width: 40px;">' . htmlspecialchars($columns['no']) . '</th><th style="width: 32%;">' . htmlspecialchars($columns['item']) . '</th><th>' . htmlspecialchars($columns['detail']) . '</th></tr></thead>
    <tbody>';

            foreach ($rows as $index => $row) {
                $html .= '
      <tr><td style="text-align: center;">' . $prefix . ($index + 1) . '</td><td>' . htmlspecialchars((string) $row->item) . '</td><td>' . nl2br(htmlspecialchars((string) ($row->description ?? ''))) . '</td></tr>';
            }

            return $html . '
    </tbody>
  </table>';
        };

        foreach ($categories as $index => $category) {
            $number   = $index + 1;
            $children = ($category->children ?? collect())->values();

            $html .= '
  <div class="scope-heading">' . $number . '. ' . htmlspecialchars((string) $category->item) . '</div>';

            if (filled($category->description)) {
                $html .= '
  <div class="scope-note">' . nl2br(htmlspecialchars((string) $category->description)) . '</div>';
            }

            if ($children->isNotEmpty()) {
                $html .= $table($children, $number . '.');
            }
        }

        // Kontrak lama tanpa kategori: daftar item datar.
        $flat = ($contract->sowItems ?? collect())->values();
        if ($categories->isEmpty() && $flat->isNotEmpty()) {
            $html .= $table($flat, '');
        }

        if (filled(trim(strip_tags((string) $layout['scope_outro'])))) {
            $html .= '
  <div class="rich" style="margin-top: 0.3cm;">' . $this->fillPlaceholders($layout['scope_outro'], $data) . '</div>';
        }

        return $html . '
</div>';
    }

    private function buildSowTable(Contract $contract): string
    {
        // Get hierarchical SOW categories with children
        $categories = $contract->sowCategories ?? collect();

        // Also check for legacy flat sowItems
        $flatItems = $contract->sowItems ?? collect();

        if ($categories->isEmpty() && $flatItems->isEmpty()) {
            return '';
        }

        // Both branches below render exactly 3 columns (No, Layanan, Rincian)
        // to match this header. Quantity/unit (when present) are folded into
        // the "Rincian" cell instead of being dropped or rendered as extra
        // columns that don't exist in the header.
        $html = '
  <div class="article">
    <div class="article-title">Lampiran 1 <br> Ruang Lingkup Pekerjaan</div>
    <table style="width: 100%; border-collapse: collapse; margin: 0.15cm 0; font-size: 10pt;">
      <thead>
          <tr>
              <th style="width:25px; border:1px solid #000; padding:6px; background-color:#f5f5f5; text-align:center;">No</th>
              <th style="border:1px solid #000; padding:6px; background-color:#f5f5f5; text-align:center;">Layanan</th>
              <th style="border:1px solid #000; padding:6px; background-color:#f5f5f5; text-align:center;">Rincian</th>
          </tr>
      </thead>
      <tbody>';

        // Render hierarchical categories
        if ($categories->isNotEmpty()) {
            $rowNum = 1;
            foreach ($categories as $category) {
                // Category header row (merged, bold)
                $categoryName = htmlspecialchars($category->item ?? 'Layanan');
                $children = $category->children ?? collect();

                $html .= '
        <tr style="background-color: #f0f0f0;">
          <td style="border: 1px solid #000; padding: 6px; text-align: center; vertical-align: top; font-weight: bold;">' . $rowNum . '</td>
          <td colspan="2" style="border:1px solid #000; padding:6px; font-weight:bold; vertical-align:top;">
            ' . $categoryName . (filled($category->description) ? '<div style="font-weight: normal; font-size: 9pt;">' . nl2br(htmlspecialchars((string) $category->description)) . '</div>' : '') . '
        </td>
        </tr>';

                // Sub-items (children)
                $childNum = 1;
                foreach ($children as $child) {
                    $childItem = htmlspecialchars($child->item ?? '-');
                    $childDesc = nl2br(htmlspecialchars($child->description ?? '-'));
                    $childQty = $child->quantity ?? null;
                    $childUnit = $child->unit ?? null;

                    // Append quantity/unit to the description cell (when set)
                    // so this data is still shown to the reader instead of
                    // being silently discarded.
                   // if ($childQty !== null && $childQty !== '') {
                   //     $childDesc .= '<br><em>Jumlah: ' . htmlspecialchars((string)$childQty) . ($childUnit ? ' ' . htmlspecialchars($childUnit) : '') . '</em>';
                  //  }

                    $html .= '
        <tr>
            <td style="border:1px solid #000; padding:6px; text-align:center; vertical-align:top;">
                ' . $rowNum . '.' . $childNum . '
            </td>

            <td style="border:1px solid #000; padding:6px 6px 6px 20px; vertical-align:top;">
                ' . $childItem . '
            </td>

            <td style="border:1px solid #000; padding:6px; vertical-align:top; line-height:1.4;">
                ' . $childDesc . '
            </td>
        </tr>';
                    $childNum++;
                }

                $rowNum++;
            }
        }
        // Legacy flat items (no hierarchy)
        elseif ($flatItems->isNotEmpty()) {
            foreach ($flatItems as $index => $item) {
                $itemName = htmlspecialchars($item->item ?? '-');
                $itemDesc = nl2br(htmlspecialchars($item->description ?? '-'));
                $itemQty = $item->quantity ?? null;
                $itemUnit = $item->unit ?? null;

                if ($itemQty !== null && $itemQty !== '') {
                    $itemDesc .= '<br><em>Jumlah: ' . htmlspecialchars((string)$itemQty) . ($itemUnit ? ' ' . htmlspecialchars($itemUnit) : '') . '</em>';
                }

                $html .= '
        <tr>
          <td style="border: 1px solid #000; padding: 6px; text-align: center; vertical-align: top;">' . ($index + 1) . '</td>
          <td style="border: 1px solid #000; padding: 6px; font-weight: bold; vertical-align: top;">' . $itemName . '</td>
          <td style="border: 1px solid #000; padding: 6px; vertical-align: top; line-height: 1.4;">' . $itemDesc . '</td>
        </tr>';
            }
        }

        $html .= '
      </tbody>
    </table>';

        // Notes from all items (legacy support)
        $notes = $flatItems->whereNotNull('notes')->where('notes', '!=', '')->pluck('notes')->filter();
        if ($notes->isNotEmpty()) {
            $html .= '
    <div style="margin-top: 0.15cm; font-size: 9pt;">
      <strong>Catatan:</strong>
      <ul style="margin: 5px 0 0 20px; padding: 0;">';
            foreach ($notes as $note) {
                $html .= '<li>' . htmlspecialchars($note) . '</li>';
            }
            $html .= '
      </ul>
    </div>';
        }

        $html .= '
  </div>';

        return $html;
    }
}