<?php

namespace App\Services;

use App\Models\Contract;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ContractTemplateService
{
    public function generate(Contract $contract): string
    {
        $settings = app(GeneralSettings::class);

        // === COMPANY INFO (dari Invoice Settings) ===
        $logoHtml = $this->getLogoHtml($settings);
        $agencyName = $settings->inv_company_name ?? 'PT. Nama Perusahaan';
        $agencyAddress = $settings->inv_company_address ?? '';
        $agencyPhone = $settings->inv_company_phone ?? '';
        $agencyShortName = $this->getAgencyShortName($agencyName);

        // === CONTRACT DETAILS ===
        $contractNumber = $contract->contract_number ?? $this->generateContractNumber($contract, $agencyShortName);
        $effectiveDate = $contract->effective_date ? Carbon::parse($contract->effective_date) : now();
        $expirationDate = $contract->expiration_date ? Carbon::parse($contract->expiration_date) : now()->addMonths(12);
        $durationMonths = (int)($contract->duration_months ?? 12);

        // === CLIENT INFO (PIHAK KEDUA) ===
        $clientName = $contract->client_name_full ?? '..................';
        $clientAddress = $contract->client_address ?? '..................';
        $clientPhone = $contract->client_phone ?? '';
        $clientEmail = $contract->client_email ?? '';
        $clientSignName = $contract->client_signatory_name ?? '..................';
        $clientSignTitle = $contract->client_signatory_title ?? '..................';
        $agencySignName = $contract->agency_signatory_name ?? $settings->inv_signature_name ?? '..................';
        $agencySignTitle = $contract->agency_signatory_title ?? 'Direksi';

        // === PRICING ===
        $totalValue = (float)($contract->total_value ?? 0);
        $formattedTotal = 'Rp ' . number_format($totalValue, 0, ',', '.');
        $downPaymentPercent = (float)($contract->down_payment_percent ?? 0);
        $downPaymentValue = ($totalValue * $downPaymentPercent) / 100;
        $formattedDp = 'Rp ' . number_format($downPaymentValue, 0, ',', '.');
        $remainingValue = $totalValue - $downPaymentValue;
        $formattedRemaining = 'Rp ' . number_format($remainingValue, 0, ',', '.');
        $installmentMonths = (int)($contract->installment_months ?? $durationMonths);
        $monthlyValue = $installmentMonths > 0 ? $remainingValue / $installmentMonths : 0;
        $formattedMonthly = 'Rp ' . number_format($monthlyValue, 0, ',', '.');

        // === SCOPE OF WORK ===
        $scopeOfWork = $contract->scope_of_work ?? '';
        $projectDescription = $contract->project_description ?? '';

        // === ADDITIONAL CLAUSES ===
        $additionalClauses = $contract->content ?? '';
        $forceMajeureExtended = $contract->force_majeure_extended ?? false;
        $terminationDays = $contract->termination_notice_days ?? 30;
        $penaltyClause = $contract->penalty_clause ?? '';

        // === LAMPIRAN CUSTOMIZATION ===
        $lampiranTitle = $contract->lampiran_title ?? 'Lampiran 1 — Rincian Biaya Jasa';
        $lampiranDescription = str_replace('{duration}', $durationMonths, $contract->lampiran_description ?? 'Biaya yang perlu dibayarkan oleh Pihak Kedua kepada Pihak Pertama selama ' . $durationMonths . ' bulan');
        $tableATitle = $contract->table_a_title ?? 'Tabel A';
        $tableBTitle = $contract->table_b_title ?? 'Tabel a';
        $tableBDescription = $contract->table_b_description ?? 'Biaya yang perlu dibayarkan oleh Pihak Kedua kepada Pihak Ketiga (Pihak yang menyediakan third-party yang dibutuhkan selama project berjalan)';

        // === BANK INFO (dari Invoice Settings) ===
        $agencyBankName = $settings->inv_bank_name ?? 'Bank';
        $agencyBankAccount = $settings->inv_bank_account ?? '';
        $agencyBankAccountName = $settings->inv_bank_account_name ?? '';

        // === PAYMENT TYPE ===
        $paymentType = $contract->payment_type ?? 'full';

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
            'lampiranTitle', 'lampiranDescription', 'tableATitle', 'tableBTitle', 'tableBDescription'
        ));
    }

    private function getLogoHtml(GeneralSettings $settings): string
    {
        // Use inv_logo (Invoice logo) first, then contract_logo, then brand_logo
        $logoPath = $settings->inv_logo ?? $settings->contract_logo ?? $settings->brand_logo ?? null;

        if ($logoPath && Storage::exists($logoPath)) {
            $url = Storage::url($logoPath);
            return '<img src="' . $url . '" alt="Logo" style="height: 45px;">';
        }

        // Try alternative path for media library
        if ($logoPath) {
            $altPath = str_replace('sites/', 'public/sites/', $logoPath);
            if (Storage::exists($altPath)) {
                $url = Storage::url($altPath);
                return '<img src="' . $url . '" alt="Logo" style="height: 45px;">';
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

    private function generateContractNumber(Contract $contract, string $shortName): string
    {
        $prefix = '001';
        $month = now()->format('m');
        $year = now()->format('y');
        return $prefix . '/SPK/' . $month . $year . '/' . strtoupper($shortName);
    }

    private function buildHtml(array $data): string
    {
        extract($data);

        $dayName = $this->getDayName($effectiveDate);
        $formattedEffectiveDate = $this->formatDateIndonesia($effectiveDate);
        $formattedExpirationDate = $this->formatDateIndonesia($expirationDate);

        $html = '<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Perjanjian Kerja Sama</title>
  <style>
    @page {
      margin: 4cm 2cm 2cm 2cm;
      size: A4;
    }
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 11pt;
      line-height: 1.6;
      color: #000;
      margin: 0;
      padding: 0;
    }
    /* HEADER - fixed, bersih tanpa garis */
    .header {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      width: 100%;
      padding: 0.3cm 2cm 0.3cm 2cm;
      background: white;
    }
    .header-inner {
      width: 100%;
    }
    .header-logo {
      vertical-align: middle;
      text-align: left;
    }
    .header-logo img {
      height: 40px;
    }
    .header-info {
      text-align: right;
      font-size: 9pt;
      color: #555;
      line-height: 1.4;
      vertical-align: middle;
    }
    .header-info strong {
      font-size: 10pt;
      color: #000;
    }
    /* CONTENT - starts below header */
    .content {
      padding-top: 2.5cm;
    }
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
    p {
      text-align: justify;
      margin: 0 0 0.15cm 0;
      line-height: 1.6;
    }
    .party-title {
      font-weight: bold;
      margin-bottom: 0.1cm;
      margin-top: 0.25cm;
    }
    .indent {
      padding-left: 0.5cm;
    }
    .indent-2 {
      padding-left: 1cm;
    }
    .article {
      margin-bottom: 0.25cm;
      text-align: justify;
    }
    .article-title {
      font-weight: bold;
      text-transform: uppercase;
      text-align: center;
      margin-bottom: 0.15cm;
      font-size: 11pt;
    }
    .article-list {
      padding-left: 0.5cm;
      margin: 0.1cm 0;
      line-height: 1.7;
    }
    .article-list li {
      margin-bottom: 0.1cm;
      text-align: justify;
    }
    .sub-list {
      padding-left: 0.5cm;
      margin: 0.1cm 0;
    }
    .sub-list li {
      margin-bottom: 0.05cm;
    }
    /* SIGNATURE */
    .signature-section {
      margin-top: 1cm;
      page-break-inside: avoid;
    }
    .signature-line {
      margin-top: 1.5cm;
      padding-bottom: 0.05cm;
      border-bottom: 1px solid #000;
      display: inline-block;
      min-width: 4cm;
    }
    /* LAMPIRAN */
    .lampiran {
      page-break-before: always;
    }
    .lampiran-title {
      font-size: 12pt;
      font-weight: bold;
      text-transform: uppercase;
      text-align: center;
      margin: 0.5cm 0 0.3cm 0;
    }
    .table-a-title {
      font-weight: bold;
      margin-bottom: 0.2cm;
      font-size: 10pt;
    }
    .table-b-title {
      font-weight: bold;
      margin: 0.4cm 0 0.2cm 0;
      font-size: 10pt;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin: 0.15cm 0;
      font-size: 10pt;
    }
    table th, table td {
      border: 1px solid #000;
      padding: 0.1cm 0.15cm;
      text-align: left;
      line-height: 1.5;
    }
    table th {
      background-color: #f5f5f5;
      font-weight: bold;
      text-align: center;
    }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .font-bold { font-weight: bold; }
    .note {
      font-size: 9pt;
      margin-top: 0.2cm;
      line-height: 1.6;
    }
    .footer-text {
      font-size: 8pt;
      color: #666;
      text-align: center;
      margin-top: 0.3cm;
    }
  </style>
</head>
<body>

<!-- HEADER (muncul di setiap halaman) -->
<div class="header">
  <table class="header-inner">
    <tr>
      <td class="header-logo">' . $logoHtml . '</td>
      <td class="header-info">
        <strong>' . htmlspecialchars($agencyName) . '</strong><br>
        ' . nl2br(htmlspecialchars($agencyAddress)) . '
      </td>
    </tr>
  </table>
</div>

<!-- CONTENT -->
<div class="content">
  <div class="title">Perjanjian Kerja Sama</div>
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
      <li><strong>Biaya Jasa</strong> adalah besaran biaya yang harus dibayarkan oleh Pihak Kedua kepada Pihak Pertama sesuai dengan Lampiran 1 Perjanjian ini.</li>
      <li><strong>Pihak Ketiga</strong> adalah penyedia third-party yang dibutuhkan selama pekerjaan berjalan.</li>
      <li><strong>Invoice</strong> adalah surat penagihan yang dikeluarkan oleh Pihak Pertama kepada Pihak Kedua.</li>
      <li><strong>Hari Kerja</strong> adalah hari di luar hari Sabtu, Minggu dan hari libur nasional Indonesia.</li>
      <li><strong>Hari Kalender</strong> adalah setiap hari di kalender Gregorius termasuk Sabtu, Minggu dan hari libur nasional Indonesia.</li>
    </ol>
  </div>

  <!-- PASAL 2 -->
  <div class="article">
    <div class="article-title">Pasal 2 <br> Kewajiban Pihak Pertama</div>
    <ol class="article-list">
      <li>Pihak Pertama akan memberikan Jasa untuk kepentingan Pihak Kedua sesuai dengan ruang lingkup pengerjaan yang tercantum dalam Lampiran 1 Perjanjian ini.</li>
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
      <li>Para Pihak sepakat bahwa untuk Biaya Jasa adalah mengacu pada Lampiran 1 Tabel A dari Perjanjian ini.</li>
      <li>Biaya Jasa tidak dapat diubah tanpa persetujuan tertulis terlebih dahulu dari Para Pihak.</li>
      <li>Pembayaran wajib dilakukan oleh Pihak Kedua kepada Pihak Pertama sesuai dengan jangka waktu pembayaran yang diatur di dalam Lampiran 1 Tabel A dari Perjanjian ini.</li>
      <li>Pembayaran wajib dilakukan oleh Pihak Kedua ke rekening Pihak Pertama sebagai berikut:<br><br>
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
      <li>Untuk menghindari keragu-raguan, kewajiban Pihak Kedua untuk melakukan pembayaran kepada Pihak Pertama, termasuk pembayaran denda, tidak dapat ditunda, dikurangi dengan alasan Force Majeure.</li>
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

        // Additional clauses
        if (!empty($additionalClauses)) {
            $html .= '
  <div class="article">
    <div class="article-title">Pasal 11 <br> Ketentuan Tambahan</div>
    <div style="white-space: pre-wrap; text-align: justify;">' . nl2br(htmlspecialchars($additionalClauses)) . '</div>
  </div>';
        }

        // Penalty clause
        if (!empty($penaltyClause)) {
            $html .= '
  <div class="article">
    <div class="article-title">Pasal 12 <br> Klausul Denda</div>
    <div style="white-space: pre-wrap; text-align: justify;">' . nl2br(htmlspecialchars($penaltyClause)) . '</div>
  </div>';
        }

        // SIGNATURE
        $html .= '
  <div class="signature-section">
    <p style="margin-top: 0.8cm;">Perjanjian ini dibuat dalam rangkap 2 (dua) asli yang masing-masing dibubuhi materai secukupnya dan ditandatangani sebagaimana mestinya oleh Para Pihak, dan masing-masing mempunyai kekuatan hukum yang sama.</p>

    <table style="width: 100%; border: none; margin-top: 0.8cm;">
      <tr>
        <td style="width: 50%; border: none; vertical-align: top; padding-right: 20px;">
          <p style="margin-bottom: 0.3cm;"><strong>Pihak Pertama,</strong></p>
          <p style="margin-bottom: 0.05cm;">' . htmlspecialchars($agencyName) . '</p>
          <p style="margin-bottom: 0.05cm;">' . htmlspecialchars($agencySignName) . '</p>
          <div style="margin-top: 1.2cm; padding-bottom: 0.05cm; border-bottom: 1px solid #000; display: inline-block; min-width: 4cm;"></div>
        </td>
        <td style="width: 50%; border: none; vertical-align: top; padding-left: 20px;">
          <p style="margin-bottom: 0.3cm;"><strong>Pihak Kedua,</strong></p>
          <p style="margin-bottom: 0.05cm;">' . htmlspecialchars($clientName) . '</p>
          <p style="margin-bottom: 0.05cm;">' . htmlspecialchars($clientSignName) . '</p>
          <div style="margin-top: 1.2cm; padding-bottom: 0.05cm; border-bottom: 1px solid #000; display: inline-block; min-width: 4cm;"></div>
        </td>
      </tr>
    </table>
  </div>
</div>

<!-- LAMPIRAN (page baru) -->
<div class="lampiran">
  <div class="lampiran-title">' . htmlspecialchars($lampiranTitle) . '</div>

  <div class="table-a-title">' . htmlspecialchars($tableATitle) . '</div>
  <div class="note">' . htmlspecialchars($lampiranDescription) . '</div>

  <table>
    <thead>
      <tr>
        <th style="width: 25px; text-align: center;">No</th>
        <th>Item</th>
        <th style="width: 150px;">Estimasi</th>
        <th style="width: 100px;">Tanggal</th>
        <th style="width: 120px; text-align: right;">Jumlah</th>
      </tr>
    </thead>
    <tbody>
      ' . $this->buildFeeTableFromItems($contract) . '
    </tbody>
  </table>';

        // Table a (Third Party) - only show if has_third_party is true
        if ($contract->has_third_party) {
            $html .= '
  <div class="table-b-title">' . htmlspecialchars($tableBTitle) . '</div>
  <div class="note">' . htmlspecialchars($tableBDescription) . '</div>

  <table>
    <thead>
      <tr>
        <th style="width: 25px; text-align: center;">No</th>
        <th>Item</th>
        <th style="width: 120px; text-align: right;">Amount (IDR)</th>
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

    private function buildFeeTableFromItems(Contract $contract): string
    {
        $items = $contract->feeItems ?? collect();
        $html = '';

        if ($items->isEmpty()) {
            // Default: Generate from payment type
            $paymentType = $contract->payment_type ?? 'full';
            $totalValue = (float)($contract->total_value ?? 0);
            $downPaymentPercent = (float)($contract->down_payment_percent ?? 0);
            $downPaymentValue = ($totalValue * $downPaymentPercent) / 100;
            $remainingValue = $totalValue - $downPaymentValue;
            $formattedDp = 'Rp ' . number_format($downPaymentValue, 0, ',', '.');
            $formattedRemaining = 'Rp ' . number_format($remainingValue, 0, ',', '.');
            $formattedTotal = 'Rp ' . number_format($totalValue, 0, ',', '.');
            $installmentMonths = (int)($contract->installment_months ?? 1);
            $effectiveDate = $contract->effective_date ? Carbon::parse($contract->effective_date) : now();

            if ($paymentType === 'dp_monthly') {
                $html .= '<tr>
                    <td class="text-center">1</td>
                    <td>Fase 1 - Down Payment</td>
                    <td>' . $formattedDp . '</td>
                    <td>' . $effectiveDate->format('d/m/Y') . '</td>
                    <td class="text-right">' . $formattedDp . '</td>
                  </tr>';
                $html .= '<tr>
                    <td class="text-center">2</td>
                    <td>Sisa Pembayaran (' . $installmentMonths . 'x)</td>
                    <td>' . $formattedRemaining . '</td>
                    <td>Setiap tanggal 15</td>
                    <td class="text-right">' . $formattedRemaining . '</td>
                  </tr>';
            } elseif ($paymentType === 'monthly') {
                $monthlyValue = $remainingValue / max(1, $installmentMonths);
                $formattedMonthly = 'Rp ' . number_format($monthlyValue, 0, ',', '.');
                $html .= '<tr>
                    <td class="text-center">1</td>
                    <td>Pembayaran per Bulan (' . $installmentMonths . 'x)</td>
                    <td>' . $formattedMonthly . '</td>
                    <td>Setiap tanggal 15</td>
                    <td class="text-right">' . $formattedTotal . '</td>
                  </tr>';
            } else {
                $html .= '<tr>
                    <td class="text-center">1</td>
                    <td>Pembayaran Lunas</td>
                    <td>' . $formattedTotal . '</td>
                    <td>' . $effectiveDate->format('d/m/Y') . '</td>
                    <td class="text-right">' . $formattedTotal . '</td>
                  </tr>';
            }
        } else {
            foreach ($items as $index => $item) {
                $amount = $item->amount ? 'Rp ' . number_format((float)$item->amount, 0, ',', '.') : '-';
                $html .= '<tr>
                    <td class="text-center">' . ($index + 1) . '</td>
                    <td>' . htmlspecialchars($item->item ?? '-') . '</td>
                    <td>' . $amount . '</td>
                    <td>' . htmlspecialchars($item->due_date ?? '-') . '</td>
                    <td class="text-right">' . $amount . '</td>
                  </tr>';
            }
        }

        // Total row
        $totalValue = (float)($contract->total_value ?? 0);
        $formattedTotal = 'Rp ' . number_format($totalValue, 0, ',', '.');
        $html .= '<tr>
            <td colspan="4" class="text-right font-bold">TOTAL</td>
            <td class="text-right font-bold">' . $formattedTotal . '</td>
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
                $amount = $item->amount ? 'Rp ' . number_format((float)$item->amount, 0, ',', '.') : '-';
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

    private function buildSowTable(Contract $contract): string
    {
        // Get hierarchical SOW categories with children
        $categories = $contract->sowCategories ?? collect();

        // Also check for legacy flat sowItems
        $flatItems = $contract->sowItems ?? collect();

        if ($categories->isEmpty() && $flatItems->isEmpty()) {
            return '';
        }

        $html = '
  <div class="article">
    <div class="article-title">Lampiran 2 <br> Ruang Lingkup Pekerjaan</div>
    <table style="width: 100%; border-collapse: collapse; margin: 0.15cm 0; font-size: 10pt;">
      <thead>
        <tr>
          <th style="width: 25px; border: 1px solid #000; padding: 6px; background-color: #f5f5f5; text-align: center;">No</th>
          <th style="border: 1px solid #000; padding: 6px; background-color: #f5f5f5; text-align: center;">Layanan</th>
          <th style="border: 1px solid #000; padding: 6px; background-color: #f5f5f5; text-align: center;">Rincian</th>
          <th style="width: 50px; border: 1px solid #000; padding: 6px; background-color: #f5f5f5; text-align: center;">Qty</th>
          <th style="width: 80px; border: 1px solid #000; padding: 6px; background-color: #f5f5f5; text-align: center;">Unit</th>
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
          <td colspan="4" style="border: 1px solid #000; padding: 6px; font-weight: bold; vertical-align: top;">' . $categoryName . '</td>
        </tr>';

                // Sub-items (children)
                $childNum = 1;
                foreach ($children as $child) {
                    $childItem = htmlspecialchars($child->item ?? '-');
                    $childDesc = nl2br(htmlspecialchars($child->description ?? '-'));
                    $childQty = $child->quantity ?? '-';
                    $childUnit = htmlspecialchars($child->unit ?? '-');

                    $html .= '
        <tr>
          <td style="border: 1px solid #000; padding: 6px; text-align: center; vertical-align: top;">' . $rowNum . '.' . $childNum . '</td>
          <td style="border: 1px solid #000; padding: 6px; vertical-align: top; padding-left: 20px;">' . $childItem . '</td>
          <td style="border: 1px solid #000; padding: 6px; vertical-align: top; line-height: 1.4;">' . $childDesc . '</td>
          <td style="border: 1px solid #000; padding: 6px; text-align: center;">' . $childQty . '</td>
          <td style="border: 1px solid #000; padding: 6px; text-align: center;">' . $childUnit . '</td>
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
                $itemQty = $item->quantity ?? '-';
                $itemUnit = htmlspecialchars($item->unit ?? '-');

                $html .= '
        <tr>
          <td style="border: 1px solid #000; padding: 6px; text-align: center; vertical-align: top;">' . ($index + 1) . '</td>
          <td style="border: 1px solid #000; padding: 6px; font-weight: bold; vertical-align: top;">' . $itemName . '</td>
          <td style="border: 1px solid #000; padding: 6px; vertical-align: top; line-height: 1.4;">' . $itemDesc . '</td>
          <td style="border: 1px solid #000; padding: 6px; text-align: center;">' . $itemQty . '</td>
          <td style="border: 1px solid #000; padding: 6px; text-align: center;">' . $itemUnit . '</td>
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
