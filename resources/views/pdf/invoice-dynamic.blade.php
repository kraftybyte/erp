<?php
/** @var \App\Models\Invoice $invoice */
/** @var string|null $themeColor */
/** @var array $settings */

$invoiceTitle = match($invoice->type) {
    'sale' => 'INVOICE',
    'estimate' => 'PROFORMA INVOICE',
    default => strtoupper($invoice->type)
};
$themeColorSafe = is_string($themeColor ?? '') && strlen($themeColor ?? '') === 7 ? $themeColor : '#f04f29';
$logoPath = $settings['logo'] ?? null;
$logoData = null;
$bgLogoUrl = '';
if ($logoPath && file_exists(public_path('storage/' . $logoPath))) {
    $logoData = base64_encode(file_get_contents(public_path('storage/' . $logoPath)));
    $bgLogoUrl = 'data:image/png;base64,' . $logoData;
} else {
    $bgLogoUrl = public_path('storage/sites/01JG6H75DAYKED5J0CPS6MW0EF.png');
}

// Dynamic CSS values
$cssThemeColor = $themeColorSafe;

// ============================================================
// CALCULATION - Hitung dari items untuk konsistensi
// ============================================================
$calcTotalVat = 0;        // SUM((price * qty - discount) * vat%)
$calcTotalDiscount = 0;   // SUM(discount) - discount adalah TOTAL
$calcSubTotal = 0;        // SUM(price * qty)
$calcGrandTotal = 0;      // (subtotal - discount) + vat

$invoiceItems = $invoice->invoicesItems ?? collect();

// Calculate if this is a DP invoice
// Tipe pembayaran dinormalkan: di database sempat tersimpan nilai seperti
// "Full Payment" (berspasi) dan NULL. Tanpa normalisasi, keduanya jatuh ke
// cabang DP dan tercetak sebagai "Down Payment 0%" di dokumen ke klien.
$typePayment = strtolower(str_replace(' ', '_', trim((string) ($invoice->type_payment ?? ''))));
$isDP = str_starts_with($typePayment, 'dp_');
$isFinalPayment = $typePayment === 'final_payment';
$dpPercentage = $isDP ? (int) str_replace('dp_', '', $typePayment) : 0;

/*
 | INVOICE PELUNASAN
 |
 | Nilai DP yang sudah dibayar diambil dari invoice DP yang ditautkan lewat
 | parent_invoice_id. Sebelumnya angka ini di-parse dari teks bebas di kolom
 | notes, sehingga catatan yang tidak cocok dengan invoicenya membuat dokumen
 | ke klien mencetak nilai yang sama sekali berbeda dari tagihan sebenarnya.
 |
 | Pembacaan notes dipertahankan hanya sebagai cadangan untuk invoice lama yang
 | terlanjur dibuat sebelum penautan ada.
 */
$dpPaidAmount = 0;
$dpPercentagePaid = 0;
$fullTotalAmount = $calcGrandTotal;

if ($isFinalPayment) {
    $invoiceDP = $invoice->parent_invoice_id ? $invoice->parentInvoice : null;

    if ($invoiceDP) {
        $dpPercentagePaid = (int) str_replace('dp_', '', (string) $invoiceDP->type_payment);
        $fullTotalAmount = (float) $invoiceDP->grand_total;
        $dpPaidAmount = $dpPercentagePaid > 0
            ? $fullTotalAmount * ($dpPercentagePaid / 100)
            : (float) $invoiceDP->paid;
    } elseif (preg_match('/DP[:\s]*(\d+)%\s*[-–:]*\s*[Rr]p\s*([\d.,]+)/i', trim((string) $invoice->notes), $matches)) {
        // Cadangan untuk invoice lama: "DP: 50% - Rp 20.000.000"
        $dpPercentagePaid = (int) $matches[1];
        $dpPaidAmount = (float) str_replace('.', '', $matches[2]);

        if ($dpPercentagePaid > 0 && $dpPaidAmount > 0) {
            $fullTotalAmount = $dpPaidAmount / ($dpPercentagePaid / 100);
        }
    }
}

foreach ($invoiceItems as $item) {
    $price = (float) ($item->price ?? 0);
    $qty = (float) ($item->qty ?? 1);
    $discount = (float) ($item->discount ?? 0);  // Discount TOTAL
    $vatPercentage = (float) ($item->vat_percentage ?? 0);

    // Sub Total: price × qty
    $itemSubTotal = $price * $qty;
    // After discount: subtotal - discount (discount adalah total, bukan per unit)
    $itemAfterDiscount = max($itemSubTotal - $discount, 0);
    // VAT from price after discount
    $itemVat = $itemAfterDiscount * ($vatPercentage / 100);
    // Total per item: after discount + VAT
    $itemTotal = $itemAfterDiscount + $itemVat;

    $calcTotalDiscount += $discount;
    $calcSubTotal += $itemSubTotal;
    $calcTotalVat += $itemVat;
    $calcGrandTotal += $itemTotal;
}

/*
 | INVOICE DP
 |
 | Item menyimpan harga PENUH pekerjaan, jadi $calcGrandTotal adalah total penuh.
 | Yang ditagihkan lewat invoice DP adalah uang mukanya — persentase dari total
 | penuh itu — sementara sisanya ditagih menyusul lewat invoice pelunasan.
 |
 | Sebelumnya "Amount Due" diisi dengan sisa, sehingga invoice DP 20% atas
 | pekerjaan Rp 560.000 menagih Rp 448.000 alih-alih Rp 112.000. Pada DP 50%
 | kekeliruan ini tidak terlihat karena DP dan sisanya kebetulan sama besar.
 */
$originalFullTotal = $calcGrandTotal;
$dpAmount = 0;
$remainingAmount = 0;

if ($isDP && $dpPercentage > 0 && $calcGrandTotal > 0) {
    $dpAmount = $originalFullTotal * ($dpPercentage / 100);
    $remainingAmount = $originalFullTotal - $dpAmount;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoiceTitle }} {{ $invoice->nama_perusahaan }} - {{ $invoice->uuid }}</title>
    <style type="text/css">
        /*
         | Margin kertas. Tanpa ini halaman lanjutan (invoice dengan banyak item)
         | dimulai tepat di tepi atas kertas, menempel tanpa jarak. Garis aksen
         | di tepi atas dan nomor halaman digambar App\Services\InvoicePdf.
         */
        @page {
            margin: 36px 0;
        }

        /*
         | Judul kolom tabel item hanya di halaman pertama. Sebagai thead biasa,
         | dompdf mengulangnya di setiap halaman baru sehingga bar judul muncul dobel.
         */
        .invoice-summary thead {
            display: table-row-group;
        }

        /* Satu baris item, blok total, dan tanda tangan tidak terbelah ke dua halaman. */
        .invoice-summary tr,
        .summary-box,
        .invoice-footer {
            page-break-inside: avoid;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
        }

        p, li, ul, ol {
            margin: 0;
            padding: 0;
            list-style: none;
            line-height: 1.5;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table tr th {
            padding: 0.75rem;
            text-align: left;
        }

        table tr td {
            padding: 0.75rem;
            text-align: left;
        }

        table th small {
            display: block;
            font-size: 12px;
        }

        .invoice-preview-main {
            max-width: 700px;
            width: 100%;
            margin: 0 auto;
            background: #fff;
            box-shadow: 0 0 10px #ddd;
        }

        .invoice-preview-main::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 500px;
            height: 500px;
            background: url("{{ $bgLogoUrl }}") no-repeat center;
            background-size: contain;
            opacity: 0.08;
            z-index: -1;
        }

        .invoice-logo {
            max-width: 200px;
            width: 100%;
        }

        .invoice-header table td {
            padding: 15px 30px;
        }

        .text-right {
            text-align: right;
        }

        .no-space tr td {
            padding: 0;
            white-space: nowrap;
        }

        .vertical-align-top td {
            vertical-align: top;
        }

        .invoice-body {
            padding: 30px 25px 0;
        }

        .add-border tr {
            border-top: 1px solid <?= $cssThemeColor ?>;
        }

        tfoot tr:first-of-type {
            border-bottom: 1px solid <?= $cssThemeColor ?>;
        }

        .total-table tr:first-of-type td {
            padding-top: 0;
        }

        .total-table tr:first-of-type {
            border-top: 0;
        }

        .sub-total {
            padding-right: 0;
            padding-left: 0;
        }

        .border-0 {
            border: none !important;
        }

        .invoice-summary td,
        .invoice-summary th {
            font-size: 13px;
            font-weight: 600;
        }

        .total-table td:last-of-type {
            width: 146px;
        }

        .invoice-footer {
            padding: 15px 20px;
        }

        .itm-description td {
            padding-top: 0;
        }

        p:not(:last-of-type) {
            margin-bottom: 15px;
        }

        .invoice-summary p {
            margin-bottom: 0;
        }

        .wid-75 {
            width: 75px;
        }

        .from-to-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .from-to-container .column {
            width: 50%;
        }

        .summary-container {
            display: flex;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .summary-box {
            width: 97%;
            padding: 10px;
            font-size: 16px;
            text-align: right;
            line-height: 2;
        }
    </style>
</head>
<body>
    <div class="invoice-preview-main" id="boxes">
        <div class="invoice-header" style="padding: 0 30px 20px;">
            <table>
                <tbody>
                    <tr>
                        <td style="width: 50%; vertical-align: middle;">
                            <h3 style="text-transform: uppercase; font-size: 32px; font-weight: bold; color: {{ $themeColorSafe }}; margin: 0;">
                                {{ $invoiceTitle }}
                            </h3>
                        </td>
                        <td style="width: 50%; text-align: right; vertical-align: middle;">
                            @if($logoData)
                                <img class="invoice-logo" src="data:image/png;base64,{{ $logoData }}" alt="Company Logo" style="max-height: 60px;">
                            @elseif($logoPath)
                                <img class="invoice-logo" src="{{ public_path('storage/' . $logoPath) }}" alt="Company Logo" style="max-height: 60px;">
                            @else
                                <img class="invoice-logo" src="{{ public_path('storage/sites/01JG6H75DAYKED5J0CPS6MW0EF.png') }}" alt="Company Logo" style="max-height: 60px;">
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="invoice-body">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 50%; vertical-align: top; text-align: left;">
                        <strong style="margin-bottom: 10px; display:block; font-size: 10px;">From:</strong>
                        <p style="font-size: 11px; text-align: left;">
                            {!! collect([$settings['company_info']['name'] ?? 'Company Name', $settings['company_info']['address'] ?? '', $settings['company_info']['phone'] ?? ''])
                                ->map(fn ($line) => trim((string) $line))
                                ->reject(fn ($line) => $line === '' || $line === '-')
                                ->map(fn ($line) => nl2br(e($line), false))
                                ->implode('<br>') !!}
                        </p>
                    </td>
                    <td style="width: 50%; vertical-align: top;">
                        <strong style="margin-bottom: 10px; display:block; font-size: 11px; text-align: right;">To:</strong>
                        {{-- Baris yang kosong (atau "-") tidak dicetak. --}}
                        <p style="font-size: 10px; text-align: right;">
                            {!! collect([$invoice->nama_perusahaan, $invoice->nama_pic, $invoice->alamat, $invoice->telephone])
                                ->map(fn ($line) => trim((string) $line))
                                ->reject(fn ($line) => $line === '' || $line === '-')
                                ->map(fn ($line) => nl2br(e($line), false))
                                ->implode('<br>') !!}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 50%; vertical-align: top; text-align: left;">
                        <p style="font-size: 10px; text-align: left;">
                        <br>
                        <b>Number:</b> {{ $invoice->uuid }}
                        <br>
                        <b>Date:</b> {{ $invoice->date ? \Carbon\Carbon::parse($invoice->date)->locale('id')->translatedFormat('d-F-Y') : 'N/A' }}
                        <br>
                        <b>Due Date:</b> {{ $invoice->due_date ? \Carbon\Carbon::parse($invoice->due_date)->locale('id')->translatedFormat('d-F-Y') : 'N/A' }}
                        @if ($invoice->type === 'sale')
                        <br>
                        {{--
                            Hanya DP yang dilabeli Down Payment. Cabang terakhir dulu
                            menangkap semua nilai lain — termasuk "Full Payment" berspasi
                            dan NULL — lalu mencetaknya sebagai "Down Payment 0%".
                        --}}
                        <b>Type Payment:</b>
                        @if($isDP && $dpPercentage > 0)
                            <span style="background-color: #007bff; color: white; padding: 2px 8px; border-radius: 4px; font-size: 10px;">Down Payment {{ $dpPercentage }}%</span>
                        @elseif($isFinalPayment)
                            <span style="background-color: #6f42c1; color: white; padding: 2px 8px; border-radius: 4px; font-size: 10px;">Final Payment</span>
                            @if($dpPercentagePaid > 0 && $dpPaidAmount > 0)
                            <br>
                            <span style="color: #6f42c1; font-size: 9px;">(DP {{ $dpPercentagePaid }}% - Rp {{ number_format($dpPaidAmount, 0, ',', '.') }} sudah dibayar)</span>
                            @endif
                        @else
                            <span style="background-color: #28a745; color: white; padding: 2px 8px; border-radius: 4px; font-size: 10px;">Full Payment</span>
                        @endif
                        <br>
                        <b>PO Number:</b> {{ $invoice->po_number ?: '-' }}
                        <br>
                        <b>No. Faktur:</b> {{ $invoice->faktur ?: '-' }}
                        <br>
                        <b>No. Surat Jalan:</b> {{ $invoice->delivery_note_number ?: '-' }}
                        @endif
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <table class="add-border invoice-summary" style="margin-top: 20px;">
            <thead style="background-color: {{ $themeColorSafe }}; color:rgb(0, 0, 0);">
                <tr>
                    <th style="color: white; text-align: center; font-size: 11px;">Item</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Qty</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Price</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Discount</th>
                    <th style="color: white; text-align: center; font-size: 11px;">VAT</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Total</th>
                </tr>
            </thead>

            <tbody>
    @foreach ($invoiceItems as $item)
    @php
        $itemPrice = (float) ($item->price ?? 0);
        $itemQty = (float) ($item->qty ?? 1);
        $itemDiscount = (float) ($item->discount ?? 0);  // Discount TOTAL
        $itemVatPct = (float) ($item->vat_percentage ?? 0);
        $itemSubTotal = $itemPrice * $itemQty;
        // Discount adalah total (bukan per unit)
        $itemAfterDiscount = max($itemSubTotal - $itemDiscount, 0);
        $itemVat = $itemAfterDiscount * ($itemVatPct / 100);
        $itemTotal = $itemAfterDiscount + $itemVat;

        // Original price untuk DP
        $originalItemPrice = (float) ($item->original_price ?? $item->price ?? 0);
        $isDPPrice = $originalItemPrice > 0 && $originalItemPrice != $itemPrice;
    @endphp
    <tr style="border-bottom: 1px solid #ddd;">
        <td style="text-align: left; font-size: 11px; padding: 10px;">
            {{ $item->item }}<br>
            <span style="font-size: 9px; color: #666;">{!! $item->description ?? '' !!}</span>
        </td>

        <td style="text-align: center; font-size: 11px; padding: 10px;">
            {{ $itemQty }}
        </td>

        {{-- Price --}}
        <td style="text-align: center; font-size: 11px; padding: 10px;">
            Rp{{ number_format($itemPrice, 0, ',', '.') }}
            @if($isDPPrice)
                <br><small style="color: #999; font-size: 8px;">dari Rp{{ number_format($originalItemPrice, 0, ',', '.') }}</small>
            @endif
        </td>

        {{-- Discount --}}
        <td style="text-align: center; font-size: 11px; padding: 10px;">
            @if($itemDiscount == 0)
                -
            @else
                Rp{{ number_format($itemDiscount, 0, ',', '.') }}
            @endif
        </td>

        {{-- VAT Percentage --}}
        <td style="text-align: center; font-size: 11px; padding: 10px;">
            @if($itemVatPct == 0)
                -
            @else
                {{ $itemVatPct }}%
            @endif
        </td>

        {{-- Total including VAT --}}
        <td style="text-align: center; font-size: 11px; padding: 10px;">
            Rp{{ number_format($itemTotal, 0, ',', '.') }}
        </td>
    </tr>
    @endforeach
</tbody>
        </table>

        <div class="summary-box">
            <div class="invoice-footer" style="font-size: 10px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="text-align: left; width: 50%;">
                            <p>
                                PAYMENT DETAILS<br>
                                BANK {{ $settings['bank_info']['name'] ?? 'BCA' }} {{ $settings['bank_info']['account'] ?? '5385141120' }}<br>
                                a/n {{ $settings['bank_info']['account_name'] ?? 'PT DIGITAL CATHA' }}
                            </p>
                        </td>
                        <td style="text-align: right; width: 50%;">
                            @if($isFinalPayment && $dpPaidAmount > 0)
                                {{-- Format Final Payment Invoice - show DP info --}}
                                <strong>Total Tagihan &emsp;:</strong> Rp{{ number_format($fullTotalAmount, 0, ',', '.') }}
                                <br>
                                <strong>DP {{ $dpPercentagePaid }}% (Sudah Bayar) &emsp;:</strong> <span style="color: #28a745;">(-) Rp{{ number_format($dpPaidAmount, 0, ',', '.') }}</span>
                                <br>
                                <strong style="font-size: 14px;">Sisa Pembayaran &emsp;:</strong> <span style="font-size: 14px; color: #f04f29;">Rp{{ number_format(max(0, $fullTotalAmount - $dpPaidAmount), 0, ',', '.') }}</span>
                            @elseif($isDP && $dpPercentage > 0)
                                {{--
                                    Invoice DP menagih uang mukanya. Sisanya ditampilkan
                                    sebagai keterangan agar penerima tahu berapa yang
                                    menyusul, tapi yang jatuh tempo sekarang adalah DP.
                                --}}
                                <strong>Total Harga &emsp;:</strong> Rp{{ number_format($originalFullTotal, 0, ',', '.') }}
                                <br>
                                <strong>Sisa (ditagih nanti) &emsp;:</strong> <span style="color: #777;">Rp{{ number_format($remainingAmount, 0, ',', '.') }}</span>
                                <br>
                                <strong style="font-size: 14px;">Amount Due (DP {{ $dpPercentage }}%) &emsp;:</strong> <span style="font-size: 14px; color: #f04f29;">Rp{{ number_format($dpAmount, 0, ',', '.') }}</span>
                            @else
                                {{-- Format Full Payment Invoice --}}
                                <strong>Sub Total &emsp;:</strong> Rp{{ number_format($calcSubTotal, 0, ',', '.') }}
                                <br>
                                @if($calcTotalDiscount > 0)
                                    <strong>Total Diskon &emsp;:</strong> -Rp{{ number_format($calcTotalDiscount, 0, ',', '.') }}
                                    <br>
                                @endif
                                @if($calcTotalVat > 0)
                                    <strong>Total VAT &emsp;:</strong> Rp{{ number_format($calcTotalVat, 0, ',', '.') }}
                                    <br>
                                @endif
                                <strong style="font-size: 14px;">Grand Total &emsp;:</strong> Rp{{ number_format($calcGrandTotal, 0, ',', '.') }}
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        @if ($invoice->type === 'sale')
        <?php
            $sigPath = $settings['signature_image'] ?? null;
            $sigData = null;
            if ($sigPath && file_exists(public_path('storage/' . $sigPath))) {
                $sigData = base64_encode(file_get_contents(public_path('storage/' . $sigPath)));
            }
        ?>
        <div class="invoice-footer" style="font-size: 10px; margin-top: 20px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="text-align: left; width: 50%;">
                        <p></p>
                    </td>
                    <td style="width: 50%; text-align: right;">
                        <div style="display: inline-block; text-align: center;">
                            <p>Sincerely Yours,</p><br>
                            @if($sigData)
                                <img class="invoice-sign" src="data:image/png;base64,{{ $sigData }}" alt="Signature" style="display: block; margin: 10px auto; height: 60px;">
                            @elseif($sigPath)
                                <img class="invoice-sign" src="{{ public_path('storage/' . $sigPath) }}" alt="Signature" style="display: block; margin: 10px auto; height: 60px;">
                            @else
                                <img class="invoice-sign" src="{{ public_path('storage/sites/heidy-stamp.png') }}" alt="Signature" style="display: block; margin: 10px auto; height: 60px;">
                            @endif
                            <br><br><p>{{ $settings['signature_name'] ?? 'Madita Heidy' }}</p>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        @endif
    </div>
</body>
</html>
