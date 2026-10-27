<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation {{ $quotation->nama_perusahaan }} - {{ $quotation->uuid }}</title>
    <style type="text/css">
        body {
            font-family: Arial, sans-serif;
        }
        .invoice-preview-main {
            position: relative;
        }

        .invoice-preview-main::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 500px; /* Ukuran watermark */
            height: 500px;
            background: url("{{ public_path('storage/sites/01JG6H75DAYKED5J0CPS6MW0EF.png') }}") no-repeat center;
            background-size: contain;
            opacity: 0.08; /* Transparansi watermark */
            z-index: -1; /* Tetap di belakang */
        }

        :root {
            --white: #ffffff;
            --black: #000000;
        }

        body {
            font-family: 'Noto Sans', sans-serif;
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

        table.add-border tr {
            border-top: 1px solid #f04f29;
        }

        tfoot tr:first-of-type {
            border-bottom: 1px solid #f04f29;
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

        html[dir="rtl"] table tr td,
        html[dir="rtl"] table tr th {
            text-align: right;
        }

        html[dir="rtl"] .text-right {
            text-align: left;
        }

        html[dir="rtl"] .view-qrcode {
            margin-left: 0;
            margin-right: auto;
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

        /* Flexbox for From and To sections */
        .from-to-container {
            display: flex;
            justify-content: space-between; /* To ensure both are spaced out to the left and right */
            margin-bottom: 15px;
        }

        .from-to-container .column {
            width: 50%; /* Take 48% width for each column */
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
            line-height: 2; /* Meningkatkan jarak antar teks */

        }

        .page-break {
        page-break-after: always;
        page-break-after: always;
        }


    </style>
</head>
@php
    $imagePath = public_path('storage/sites/1.jpg');
    $imageData = base64_encode(file_get_contents($imagePath));
@endphp

<img src="data:image/jpeg;base64,{{ $imageData }}" style="width: 100%;">

<div class="page-break"></div>
@php
    $imagePath = public_path('storage/sites/2.jpg');
    $imageData2 = base64_encode(file_get_contents($imagePath));
@endphp

<img src="data:image/jpeg;base64,{{ $imageData2 }}" style="width: 100%;">
<body>
    <div class="invoice-preview-main" id="boxes">
        <div class="invoice-header" style="border-top: 5px solid; color: #f04f29;">
            <table>
                <tbody>
                    <tr>
                        <td>
                            <h3 style="text-transform: uppercase; font-size: 40px; font-weight: bold; color: #f04f29;">Quotation</h3>
                        </td>
                        <td class="text-right">
                            
                            <img class="invoice-logo" src="{{ public_path('storage/sites/01JG6H75DAYKED5J0CPS6MW0EF.png') }}" alt="Company Logo">
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
                            Digitalk Creative
                            <br>
                            hubungi@digitalkcreative.id
                            <br>
                            085186028747
                        </p>
                    </td>
                    <td style="width: 50%; vertical-align: top;">
                        <strong style="margin-bottom: 10px; display:block; font-size: 14px; text-align: right;">To:</strong>
                        <p style="font-size: 12px; text-align: right;">
                        {{ $quotation->nama_perusahaan }}
                        <br>
                            {{ $quotation->nama_pic }}
                            <br>
                            {{ $quotation->email }}
                            <br>
                            {{ $quotation->telephone }}
                        </p>
                    </td>
                </tr>
                <td style="width: 50%; vertical-align: top; text-align: left;">
                        <p style="font-size: 12px; text-align: left;">
                        <b>Number:</b> {{ $quotation->uuid }}
                        <br>
                        <b>Due Date:</b> {{ $quotation->due_date }}
                        </p>
                    </td>
            </table>
        </div>



            <table class="add-border invoice-summary" style="margin-top: 20px;">
                <thead style="background-color: #f04f29; color:rgb(0, 0, 0);">
                    <tr>
                        <th style="color: white; text-align: center; font-size: 11px;">{{__('Item Type')}}</th>
                        <th style="color: white; text-align: center; font-size: 11px;">{{ __('Item') }}</th>
                        <th style="color: white; text-align: center; font-size: 11px;">{{ __('Unit') }}</th>
                        <th style="color: white; text-align: center; font-size: 11px;">{{ __('Rate') }}</th>
                        <th style="color: white; text-align: center; font-size: 11px;">{{ __('Discount') }}</th>
                        <th style="color: white; text-align: center; font-size: 11px;">{{ __('Price') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($quotation->items as $item)
                    <tr>
                        <td style="text-align: center; font-size: 11px;">Service</td>
                        <td style="text-align: left; font-size: 11px;">
                        {{ $item->item }}<br><br>
                        <span style="font-size: 9px;">{!! str($item->Description ?? '')->sanitizeHtml() !!}</span>
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
                <td style="text-align: left; width: 50%; vertical-align: top;">
                    <span style="font-size: 9px; line-height: 1.3;">
                        <b>Quotation Terms & Conditions:</b><br>
                        1. Price above is NET.<br>
                        2. This quotation is valid for 14 (fourteen) days from the quotation date and after this period, the price may change without notice.<br>
                        3. Term of Payment: Full payment before the project starts, or two terms (50-50) for a minimum amount of 30 million.<br>
                    </span>
                </td>
                <td style="text-align: right; width: 50%; vertical-align: top;">
                    <strong>Sub Total &emsp;:</strong> Rp{{ number_format($quotation->grand_total - $quotation->total_vat - $quotation->total_discount, 0, ',', '.') }}
                    <br>
                    <strong>Total VAT &emsp;:</strong> Rp{{ number_format($quotation->total_vat, 0, ',', '.') }}
                    <br>
                    <strong>Total &emsp;:</strong> Rp{{ number_format($quotation->grand_total, 0, ',', '.') }}
                </td>
            </tr>
        </table>
    </div>
</div>


<div class="invoice-footer" style="font-size: 11px; margin-top: 20px;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="text-align: left; width: 50%;">
            <p>Sincerely Yours,</p>
                    <img class="invoice-sign" src="{{ public_path('storage/sites/tandatangan.png') }}" alt="Signature" style="display: block; margin: 10px auto;">                    <p>Indra Purwanto</p>
                    <p>hubungi@digitalkcreative.id</p>
            </td>
            <td style="width: 50%; text-align: right;">
                <div style="display: inline-block; text-align: center;">
                    <p>Checked & Approve By:</p>
                    <br>
                    <br>
                    <br>
                    <br>
                    <br>
                    <br>
                    <br>
                    <br>
                    <p></p>
                    <p>PIC</p>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="page-break"></div>
@php
    $imagePath = public_path('storage/sites/3.jpg');
    $imageData3 = base64_encode(file_get_contents($imagePath));
@endphp

<img src="data:image/jpeg;base64,{{ $imageData3 }}" style="width: 100%;">
        </div>
    </div>
</body>
</html>