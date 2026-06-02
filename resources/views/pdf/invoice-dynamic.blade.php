<?php
$invoiceTitle = match($invoice->type) {
    'sale' => 'INVOICE',
    'estimate' => 'PROFORMA INVOICE',
    default => strtoupper($invoice->type)
};
$themeColorSafe = is_string($themeColor ?? '') && strlen($themeColor ?? '') === 7 ? $themeColor : '#f04f29';
$logoPath = $settings['logo'] ?? null;
$logoData = null;
if ($logoPath && file_exists(public_path('storage/' . $logoPath))) {
    $logoData = base64_encode(file_get_contents(public_path('storage/' . $logoPath)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoiceTitle }} {{ $invoice->nama_perusahaan }} - {{ $invoice->uuid }}</title>
    <style type="text/css">
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
            @if($logoData)
            background: url("data:image/png;base64,{{ $logoData }}") no-repeat center;
            @else
            background: url("{{ public_path('storage/sites/01JG6H75DAYKED5J0CPS6MW0EF.png') }}") no-repeat center;
            @endif
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
            border-top: 1px solid {{ $themeColor }};
        }

        tfoot tr:first-of-type {
            border-bottom: 1px solid {{ $themeColor }};
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
        <div class="invoice-header" style="border-top: 5px solid {{ $themeColorSafe }}; padding: 20px 30px;">
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
                        <strong style="margin-bottom: 10px; display:block; font-size: 14px;">From:</strong>
                        <p style="font-size: 12px; text-align: left;">
                            {{ $settings['company_info']['name'] ?? 'Company Name' }}
                            <br>
                            {{ $settings['company_info']['address'] ?? '' }}
                            <br>
                            {{ $settings['company_info']['phone'] ?? '' }}
                        </p>
                    </td>
                    <td style="width: 50%; vertical-align: top;">
                        <strong style="margin-bottom: 10px; display:block; font-size: 14px; text-align: right;">To:</strong>
                        <p style="font-size: 12px; text-align: right;">
                            {{ $invoice->nama_perusahaan }}
                            <br>
                            {{ $invoice->alamat }}
                            <br>
                            {{ $invoice->telephone }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 50%; vertical-align: top; text-align: left;">
                        <p style="font-size: 12px; text-align: left;">
                        <br>
                        <b>Number:</b> {{ $invoice->uuid }}
                        <br>
                        <b>Date:</b> {{ \Carbon\Carbon::parse($invoice->date)->locale('id')->translatedFormat('d-F-Y') }}
                        <br>
                        <b>Due Date:</b> {{ \Carbon\Carbon::parse($invoice->due_date)->locale('id')->translatedFormat('d-F-Y') }}
                        @if ($invoice->type === 'sale')
                        <br>
                        <b>Type Payment:</b> {{ $invoice->type_payment }}
                        <br>
                        <b>PO Number:</b> {{ $invoice->po_number }}
                        <br>
                        <b>No. Faktur:</b> {{ $invoice->faktur }}
                        @endif
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <table class="add-border invoice-summary" style="margin-top: 20px;">
            <thead style="background-color: {{ $themeColorSafe }}; color:rgb(0, 0, 0);">
                <tr>
                    <th style="color: white; text-align: center; font-size: 11px;">Item Type</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Item</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Unit</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Rate</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Discount</th>
                    <th style="color: white; text-align: center; font-size: 11px;">Price</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->invoicesItems as $item)
                <tr>
                    <td style="text-align: center; font-size: 11px;">Service</td>
                    <td style="text-align: left; font-size: 11px;">
                        {{ $item->item }}<br><br>
                        <span style="font-size: 9px;">{!! $item->description !!}</span>
                    </td>
                    <td style="text-align: center; font-size: 11px;">{{ $item->qty }} Month</td>
                    <td style="text-align: center; font-size: 11px;">Rp{{ number_format($item->price, 0, ',', '.') }}</td>
                    <td style="text-align: center; font-size: 11px;">{{ $item->discount == 0 ? '-' : 'Rp' . number_format($item->discount, 0, ',', '.') }}</td>
                    <td style="text-align: center; font-size: 11px;">Rp{{ number_format($item->price * $item->qty, 0, ',', '.') }}</td>
                </tr>
                @endforeach
                <tr>
                    <td colspan="5" style="text-align: right; font-size: 11px;"><strong></strong></td>
                    <td style="text-align: center; font-size: 11px;"></td>
                </tr>
            </tbody>
        </table>

        <div class="summary-box">
            <div class="invoice-footer" style="font-size: 12px;">
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
                            <strong>Sub Total &emsp;:</strong> Rp{{ number_format($invoice->grand_total - $invoice->total_vat - $invoice->total_discount, 0, ',', '.') }}
                            <br>
                            <strong>Total Discount &emsp;:</strong> Rp{{ number_format($invoice->total_discount, 0, ',', '.') }}
                            <br>
                            <strong>Total VAT &emsp;:</strong> Rp{{ number_format($invoice->total_vat, 0, ',', '.') }}
                            <br>
                            <strong>Total &emsp;:</strong> Rp{{ number_format($invoice->grand_total, 0, ',', '.') }}
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
        <div class="invoice-footer" style="font-size: 11px; margin-top: 20px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="text-align: left; width: 50%;">
                        <p></p>
                    </td>
                    <td style="width: 50%; text-align: right;">
                        <div style="display: inline-block; text-align: center;">
                            <p>Sincerely Yours,</p>
                            @if($sigData)
                                <img class="invoice-sign" src="data:image/png;base64,{{ $sigData }}" alt="Signature" style="display: block; margin: 10px auto; height: 60px;">
                            @elseif($sigPath)
                                <img class="invoice-sign" src="{{ public_path('storage/' . $sigPath) }}" alt="Signature" style="display: block; margin: 10px auto; height: 60px;">
                            @else
                                <img class="invoice-sign" src="{{ public_path('storage/sites/heidy-stamp.png') }}" alt="Signature" style="display: block; margin: 10px auto; height: 60px;">
                            @endif
                            <p>{{ $settings['signature_name'] ?? 'Madita Heidy' }}</p>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        @endif
    </div>
</body>
</html>
