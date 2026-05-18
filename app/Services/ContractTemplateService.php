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
        $deal = $contract->deal;
        $products = $deal?->product ?? collect();

        // Agency info
        $agencyName = $settings->brand_name ?? 'Getkrafty Indonesia';
        $agencyLogo = $settings->brand_logo ? Storage::url($settings->brand_logo) : null;

        // Get primary color from theme settings
        $primaryColor = $settings->site_theme['primary'] ?? '#2563eb';
        $primaryDark = $this->darkenColor($primaryColor, 15);
        $primaryLight = $this->lightenColor($primaryColor, 85);

        // Dates
        $effectiveDate = $contract->effective_date ? Carbon::parse($contract->effective_date) : now();
        $expirationDate = $contract->expiration_date ? Carbon::parse($contract->expiration_date) : now()->addMonths((int)($contract->duration_months ?? 1));
        $durationMonths = (int)($contract->duration_months ?? 1);

        // Client info
        $clientName = $contract->client_name_full ?? $deal?->nama_perusahaan ?? '..................';
        $clientAddress = $contract->client_address ?? $deal?->alamat ?? '..................';
        $clientPhone = $contract->client_phone ?? $deal?->telephone ?? '..................';
        $clientEmail = $contract->client_email ?? $deal?->email ?? '..................';

        // Products - build SOW table from products
        $sowHtml = $this->buildSowTable($products, $contract->scope_of_work);

        // Contract details
        $platform = $contract->platform ?? 'Instagram, Facebook';
        $contentCount = (int)($contract->content_count ?? 20);
        $maxRevisions = (int)($contract->max_revisions ?? 3);
        $downPaymentPercent = (float)($contract->down_payment_percent ?? 50);
        $remainingPercent = 100 - $downPaymentPercent;

        // Currency
        $totalValue = (float)($contract->total_value ?? 0);
        $formattedTotal = 'Rp ' . number_format($totalValue, 0, ',', '.');
        $downPaymentValue = ($totalValue * $downPaymentPercent) / 100;
        $formattedDp = 'Rp ' . number_format($downPaymentValue, 0, ',', '.');
        $remainingValue = $totalValue - $downPaymentValue;
        $formattedRemaining = 'Rp ' . number_format($remainingValue, 0, ',', '.');

        // Signature info
        $clientSignName = $contract->client_signatory_name ?? '..................';
        $clientSignTitle = $contract->client_signatory_title ?? 'Klien';
        $agencySignName = $contract->agency_signatory_name ?? $agencyName;
        $agencySignTitle = $contract->agency_signatory_title ?? 'Agency';

        // Logo HTML
        $logoHtml = $agencyLogo
            ? '<img src="' . $agencyLogo . '" alt="' . $agencyName . '" style="height: 50px;">'
            : '<div style="font-size: 16px; font-weight: bold;">' . $agencyName . '</div>';

        // Signature info
        $clientSignName = $contract->client_signatory_name ?? '..................';
        $clientSignTitle = $contract->client_signatory_title ?? 'Klien';
        $agencySignName = $contract->agency_signatory_name ?? $agencyName;
        $agencySignTitle = $contract->agency_signatory_title ?? 'Agency';

        // Additional clauses
        $additionalClauses = '';
        if ($contract->content) {
            $additionalClauses = <<<CLAUSE

    <div class="section">
      <div class="pasal-title">CATATAN TAMBAHAN</div>
      <div style="font-family: Georgia, serif; font-size: 10.5pt; line-height: 1.8; white-space: pre-line;">
        {$contract->content}
      </div>
    </div>
CLAUSE;
        }

        $html = <<<MAIN
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Perjanjian Kerja Sama - {$clientName}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: Georgia, 'Times New Roman', serif;
      font-size: 10.5pt;
      color: #333;
      line-height: 1.5;
      padding: 35px 50px;
    }
    .container {
      max-width: 680px;
      margin: 0 auto;
      --primary: {$primaryColor};
      --primary-dark: {$primaryDark};
      --primary-light: {$primaryLight};
    }
    .header {
      text-align: center;
      margin-bottom: 35px;
      padding-bottom: 20px;
      border-bottom: 0.5px solid #000;
    }
    h1 {
      font-family: Arial, sans-serif;
      font-size: 16pt;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 1px;
      text-align: center;
    }
    .subtitle {
      font-family: Arial, sans-serif;
      font-size: 11pt;
      color: #555;
      margin-top: 5px;
      text-align: center;
    }
    .section {
      margin-bottom: 25px;
    }
    .section-header {
      font-family: Arial, sans-serif;
      font-size: 11pt;
      font-weight: bold;
      color: #111;
      margin-bottom: 12px;
      padding-bottom: 5px;
      border-bottom: 1px solid #ccc;
    }
    .pasal-title {
      font-family: Arial, sans-serif;
      font-size: 11pt;
      font-weight: bold;
      color: #111;
      margin-bottom: 12px;
      text-align: center;
    }
    p {
      margin-bottom: 8px;
      text-align: justify;
    }
    .info-row {
      margin-bottom: 6px;
    }
    .info-label {
      font-weight: bold;
      color: #111;
      display: inline-block;
      min-width: 130px;
    }
    .sow-table {
      width: 100%;
      border-collapse: collapse;
      margin: 12px 0;
      font-size: 10pt;
    }
    .sow-table th {
      background-color: {$primaryColor} !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
      padding: 8px 12px;
      text-align: left;
      font-weight: bold;
      color: #fff !important;
      border: 1px solid #ddd;
    }
    .sow-table td {
      padding: 10px 12px;
      border: 1px solid #ddd;
      vertical-align: top;
      line-height: 1.7;
    }
    .sow-table td:first-child {
      font-weight: bold;
      color: #111;
      width: 35%;
    }
    .sow-content {
      font-family: Georgia, 'Times New Roman', serif;
      font-size: 10.5pt;
      line-height: 1.8;
      margin: 12px 0;
      white-space: pre-line;
    }
    .value-box {
      margin: 15px 0;
      padding: 14px;
      background-color: {$primaryColor} !important;
      color: #fff !important;
      font-family: Arial, sans-serif;
      font-size: 14pt;
      font-weight: bold;
      text-align: center;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .note {
      font-style: italic;
      color: #666;
      margin: 10px 0;
    }
    .signature {
      margin-top: 60px;
      display: table;
      width: 100%;
    }
    .sig-cell {
      display: table-cell;
      width: 50%;
      text-align: center;
    }
    .sig-title {
      font-family: Arial, sans-serif;
      font-size: 11pt;
      font-weight: bold;
    }
    .sig-sub {
      font-size: 9pt;
      color: #666;
      margin-bottom: 5px;
    }
    .sig-line {
      margin-top: 5px;
      padding-bottom: 5px;
      border-bottom: 1px solid #333;
      display: inline-block;
    }
    .sig-name {
      margin-top: 30px;
      margin-bottom: 5px;
      font-size: 9pt;
    }
    .sig-sub {
      font-size: 9pt;
      color: #666;
      margin-bottom: 5px;
      margin-top: 5px;
    }
    .footer {
      margin-top: 40px;
      padding-top: 15px;
      border-top: 1px solid #ddd;
      text-align: center;
      font-size: 8pt;
      color: #999;
      font-family: Arial, sans-serif;
    }
    @media print {
      body { padding: 25px 40px; }
    }
  </style>
</head>
<body>
  <div class="container">

    <div class="header">
      {$logoHtml}
      <h1>Perjanjian Kerja Sama</h1>
      <p class="subtitle">Social Media Management</p>
    </div>

    <div class="section">
      <p>Pada hari ini, <strong>{$effectiveDate->translatedFormat('d F Y')}</strong>, telah disepakati Perjanjian Kerja Sama Social Media Management antara:</p>

      <div class="section-header" style="margin-top: 20px;">PIHAK PERTAMA (KLIEN)</div>
      <div class="info-row"><span class="info-label">Nama / Perusahaan</span>: {$clientName}</div>
      <div class="info-row"><span class="info-label">Alamat</span>: {$clientAddress}</div>
      <div class="info-row"><span class="info-label">Nomor Telepon</span>: {$clientPhone}</div>
      <div class="info-row"><span class="info-label">Email</span>: {$clientEmail}</div>

      <div class="section-header" style="margin-top: 15px;">PIHAK KEDUA (AGENCY)</div>
      <div class="info-row"><span class="info-label">Nama Agency</span>: {$agencyName}</div>
      <div class="info-row"><span class="info-label">Alamat</span>: Jakarta, Indonesia</div>
      <div class="info-row"><span class="info-label">Email</span>: hello@getkrafty.id</div>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 1 — RUANG LINGKUP PEKERJAAN</div>
      <p>Pengerjaan dilakukan pada jam kerja (Senin – Jumat, 09.00 – 17.00 WIB).</p>
      <p>Ruang lingkup pekerjaan yang diberikan adalah sebagai berikut:</p>
      {$sowHtml}
      <div class="info-row" style="margin-top: 15px;"><span class="info-label">Platform</span>: {$platform}</div>
      <div class="info-row"><span class="info-label">Jumlah Konten</span>: {$contentCount} konten per bulan</div>
      <div class="info-row"><span class="info-label">Jumlah Revisi</span>: Maksimal {$maxRevisions} kali per konten</div>
      <div class="info-row"><span class="info-label">Durasi Kontrak</span>: {$durationMonths} bulan</div>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 2 — JANGKA WAKTU</div>
      <p>Perjanjian berlaku selama <strong>{$durationMonths} bulan</strong> sejak tanggal <strong>{$effectiveDate->translatedFormat('d F Y')}</strong> sampai dengan <strong>{$expirationDate->translatedFormat('d F Y')}</strong>.</p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 3 — PEMBAYARAN</div>
      <p>Total nilai kerja sama adalah:</p>
      <div class="value-box">{$formattedTotal}</div>
      <p>Pembayaran dilakukan dengan ketentuan sebagai berikut:</p>
      <p>- Down Payment (DP) {$downPaymentPercent}% : <strong>{$formattedDp}</strong> — dibayarkan sebelum pekerjaan dimulai.</p>
      <p>- Pelunasan {$remainingPercent}% : <strong>{$formattedRemaining}</strong> — dibayarkan sesuai tanggal invoice.</p>
      <p class="note">Keterlambatan pembayaran lebih dari 7 hari memberikan hak kepada Agency untuk menunda pekerjaan, menghentikan layanan sementara, maupun menahan hasil pekerjaan.</p>
      <p><strong>PEMBAYARAN BERSIFAT NON-REFUNDABLE</strong></p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 4 — REVISI DAN APPROVAL</div>
      <p>Klien mendapatkan maksimal <strong>{$maxRevisions} kali revisi</strong> untuk setiap konten.</p>
      <p>Apabila dalam waktu 7 hari kalender Klien tidak memberikan respon terhadap draft atau materi yang dikirim, maka: Materi dianggap telah disetujui, Agency berhak melanjutkan publish/eksekusi, Klien dianggap menyetujui hasil pekerjaan, dan hak komplain terhadap materi yang dipublish dianggap gugur.</p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 5 — KEWAJIBAN KLIEN</div>
      <p>Klien berkewajiban untuk: memberikan informasi bisnis yang akurat; memberikan asset pendukung seperti logo, foto produk, dan guideline brand; memberikan approval terhadap materi dalam waktu 7 hari kalender; tidak menghambat proses kerja Agency; serta memberikan akses akun media sosial yang diperlukan.</p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 6 — HAK AGENCY</div>
      <p>Agency memiliki hak menentukan strategi konten, arah visual, copywriting, dan penjadwalan posting sesuai kebutuhan campaign. Agency juga berhak menggunakan hasil pekerjaan sebagai <strong>PORTOFOLIO</strong>, kecuali terdapat permintaan tertulis dari Klien.</p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 7 — KERAHASIAAN</div>
      <p>Kedua belah pihak wajib menjaga kerahasiaan seluruh data bisnis, strategi, akses akun, dan informasi internal lainnya.</p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 8 — PEMUTUSAN KERJA SAMA</div>
      <p>Agency berhak menghentikan kerja sama apabila: terjadi keterlambatan pembayaran lebih dari 14 hari; Klien melanggar isi perjanjian; atau Klien melakukan tindakan yang merugikan Agency.</p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 9 — KEPEMILIKAN HASIL KERJA</div>
      <p>Seluruh hasil final menjadi milik Klien setelah pembayaran lunas. Draft dan editable source file tetap menjadi milik Agency, kecuali disepakati berbeda dalam addendum terpisah.</p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 10 — PENYELESAIAN SENGKETA</div>
      <p>Segala perselisihan akan diselesaikan terlebih dahulu secara musyawarah untuk mencapai mufakat. Apabila tidak tercapai kesepakatan, maka akan diselesaikan sesuai hukum yang berlaku di Indonesia.</p>
    </div>

    <div class="section">
      <div class="pasal-title">PASAL 11 — ADDENDUM</div>
      <p>Perubahan terhadap perjanjian ini hanya berlaku jika disepakati kedua belah pihak dan dituangkan dalam bentuk addendum tertulis yang ditandatangani.</p>
    </div>

    {$additionalClauses}

    <div class="signature">
      <div class="sig-cell">
        <div class="sig-title">PIHAK PERTAMA</div>
        <div class="sig-name">{$clientSignName}</div>
        <div class="sig-line" style="width: 200px; display: inline-block;"></div>
        <div class="sig-sub">({$clientSignTitle})</div>
      </div>
      <div class="sig-cell">
        <div class="sig-title">PIHAK KEDUA</div>
        <div class="sig-name">{$agencySignName}</div>
        <div class="sig-line" style="width: 200px; display: inline-block;"></div>
        <div class="sig-sub">({$agencySignTitle})</div>
      </div>
    </div>

    <div class="footer">
      Dokumen ini dibuat secara sadar dan tanpa paksaan dari pihak manapun.
    </div>

  </div>
</body>
</html>
MAIN;

        return $html;
    }

    private function buildSowTable($products, ?string $scopeOfWork = null): string
    {
        if ($products->isEmpty()) {
            if ($scopeOfWork) {
                return '<div class="sow-content">' . nl2br($scopeOfWork) . '</div>';
            }
            return '<p style="font-family: Georgia, serif; font-size: 10.5pt; line-height: 1.5;"><em>Social Media Management</em></p>';
        }

        $rows = [];
        foreach ($products as $product) {
            $namaLayanan = $product->nama_layanan ?? '-';
            $detailLayanan = nl2br($product->detail_layanan ?? '-');

            $rows[] = '<tr><td style="font-weight: bold; width: 35%; font-family: Georgia, serif; font-size: 10.5pt; line-height: 1.5; vertical-align: top;">' . $namaLayanan . '</td><td style="font-family: Georgia, serif; font-size: 10.5pt; line-height: 1.5; vertical-align: top;">' . $detailLayanan . '</td></tr>';
        }

        $table = '<table class="sow-table"><thead><tr><th style="width: 35%;">Layanan</th><th>Detail</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table>';

        // Add additional scope of work if exists
        if ($scopeOfWork) {
            $table .= '<div class="sow-content" style="margin-top: 12px;"><strong>Ruang Lingkup Tambahan:</strong><br>' . nl2br($scopeOfWork) . '</div>';
        }

        return $table;
    }

    private function darkenColor(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, $r - ($r * $percent / 100));
        $g = max(0, $g - ($g * $percent / 100));
        $b = max(0, $b - ($b * $percent / 100));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function lightenColor(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = min(255, $r + ((255 - $r) * $percent / 100));
        $g = min(255, $g + ((255 - $g) * $percent / 100));
        $b = min(255, $b + ((255 - $b) * $percent / 100));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
