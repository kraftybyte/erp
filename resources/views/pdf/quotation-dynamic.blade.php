<?php
    /** @var string|null $themeColor */
    /** @var array $settings */
    /** @var \App\Models\Quotation $quotation */

    // Get theme color safely - use default if not set
    $themeColorSafe = is_string($themeColor ?? null) && strlen($themeColor ?? '') === 7 ? $themeColor : '#f04f29';

    // Settings variables
    $showCoverPages = ($settings['show_cover_pages'] ?? true) === true;
    $coverPage1 = $settings['cover_page_1'] ?? null;
    $coverPage2 = $settings['cover_page_2'] ?? null;
    $coverPage3 = $settings['cover_page_3'] ?? null;
    $companyInfo = $settings['company_info'] ?? [];
    $signatureName = $settings['signature_name'] ?? 'Your Name';
    $signatureTitle = $settings['signature_title'] ?? 'Director';
    $signatureImage = $settings['signature_image'] ?? null;
    $terms = $settings['terms'] ?? [];
    $logoPath = $settings['logo'] ?? null;

    // ============================================================
    // CALCULATION
    // ============================================================
    //
    // Rumus per baris: (price * qty) - discount + PPN
    //
    // discount adalah nilai TOTAL per baris, bukan per unit — sama seperti form
    // quotation, form invoice, dan PDF invoice. Sebelumnya berkas ini sendirian
    // memakai rumus berbeda: diskon dikalikan qty, lalu Grand Total disusun dari
    // (price * qty) + PPN tanpa memotong diskon sama sekali. Akibatnya PDF
    // mencantumkan "Total Diskon" tetapi angkanya tidak pernah dikurangkan,
    // sehingga quotation dan invoice untuk pekerjaan yang sama mencetak nilai
    // yang berbeda.
    //
    // Pembacaan original_price juga dihapus: kolom itu tidak pernah ada di tabel
    // quotation_items, jadi nilainya selalu jatuh ke price.
    //
    $calcTotalVat = 0;        // SUM((price * qty - discount) * vat%)
    $calcTotalDiscount = 0;   // SUM(discount)
    $calcSubTotal = 0;        // SUM(price * qty)
    $calcGrandTotal = 0;      // SUM((price * qty - discount) + vat)

    foreach ($quotation->items as $item) {
        $price = (float) ($item->price ?? 0);
        $qty = (float) ($item->qty ?? 1);
        $discount = (float) ($item->discount ?? 0);  // Discount TOTAL per baris
        $vatPercentage = (float) ($item->vat_percentage ?? 0);

        $itemSubTotal = $price * $qty;
        $itemAfterDiscount = max($itemSubTotal - $discount, 0);
        $itemVat = $itemAfterDiscount * ($vatPercentage / 100);
        $itemTotal = $itemAfterDiscount + $itemVat;

        $calcTotalDiscount += $discount;
        $calcSubTotal += $itemSubTotal;
        $calcTotalVat += $itemVat;
        $calcGrandTotal += $itemTotal;
    }

    // Helper function to get image data from path
    /**
     * Get base64 encoded image data from storage path
     * @param string|null $path
     * @return string|null
     */
    // Dibungkus function_exists: tanpa itu, merender template ini dua kali dalam
    // satu proses (mis. beberapa PDF sekaligus) gagal "Cannot redeclare function".
    if (! function_exists('getImageData')) {
    function getImageData(?string $path): ?string {
        if (empty($path) || !is_string($path)) return null;
        $fullPath = public_path('storage/' . $path);
        if (file_exists($fullPath)) {
            return base64_encode(file_get_contents($fullPath));
        }
        return null;
    }
    }

    // Get image extensions
    /**
     * Get MIME type from file path
     * @param string|null $path
     * @return string
     */
    if (! function_exists('getImageMimeType')) {
    function getImageMimeType(?string $path): string {
        if (empty($path) || !is_string($path)) return 'image/jpeg';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Quotation {{ $quotation->nama_perusahaan }} - {{ $quotation->uuid }}</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 0;">

{{-- Cover Page 1 --}}
@if($showCoverPages && $coverPage1)
    @php $cover1Data = getImageData($coverPage1); @endphp
    @if($cover1Data)
        <img src="data:{{ getImageMimeType($coverPage1) }};base64,{{ $cover1Data }}" style="width: 100%;">
        <div style="page-break-after: always;"></div>
    @endif
@endif

{{-- Cover Page 2 --}}
@if($showCoverPages && $coverPage2)
    @php $cover2Data = getImageData($coverPage2); @endphp
    @if($cover2Data)
        <img src="data:{{ getImageMimeType($coverPage2) }};base64,{{ $cover2Data }}" style="width: 100%;">
        <div style="page-break-after: always;"></div>
    @endif
@endif

{{-- Main Content --}}
<div style="max-width: 700px; margin: 0 auto; background: #fff; padding: 0 20px;">

    {{-- Header --}}
    <div style="border-top: 5px solid {{ $themeColorSafe }}; padding: 15px 30px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: middle;">
                    <h1 style="text-transform: uppercase; font-size: 32px; font-weight: bold; color: {{ $themeColorSafe }}; margin: 0;">Quotation</h1>
                </td>
                <td style="text-align: right; vertical-align: middle;">
                    @if($logoPath)
                        @php $logoData = getImageData($logoPath); @endphp
                        @if($logoData)
                            <img src="data:{{ getImageMimeType($logoPath) }};base64,{{ $logoData }}" style="max-width: 150px;">
                        @endif
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Company Info --}}
    <div style="padding: 20px 30px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <strong style="display: block; margin-bottom: 10px;">From:</strong>
                    <p style="font-size: 12px; margin: 0;">
                        {{ $companyInfo['name'] ?? 'Company Name' }}<br>
                        {{ $companyInfo['email'] ?? 'email@company.com' }}<br>
                        {{ $companyInfo['phone'] ?? '08xxxxxxxxxx' }}
                    </p>
                </td>
                <td style="width: 50%; vertical-align: top; text-align: right;">
                    <strong style="display: block; margin-bottom: 10px;">To:</strong>
                    {{-- Baris yang kosong (atau "-") tidak dicetak. --}}
                    <p style="font-size: 12px; margin: 0; text-align: right;">
                        {!! collect([$quotation->nama_perusahaan, $quotation->nama_pic, $quotation->email, $quotation->telephone])
                            ->map(fn ($line) => trim((string) $line))
                            ->reject(fn ($line) => $line === '' || $line === '-')
                            ->map(fn ($line) => e($line))
                            ->implode('<br>') !!}
                    </p>
                </td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top: 15px;">
                    <p style="font-size: 12px; margin: 0;">
                        <strong>Number:</strong> {{ $quotation->uuid }}<br>
                        <strong>Due Date:</strong> {{ \Carbon\Carbon::parse($quotation->due_date)->format('d/m/Y') }}
                    </p>
                </td>
            </tr>
        </table>
    </div>

    {{-- Items Table --}}
    <div style="padding: 0 25px;">
        <table style="width: 100%; border-collapse: collapse; border-top: 1px solid {{ $themeColorSafe }};">
            <thead style="background-color: {{ $themeColorSafe }};">
                <tr>
                   {{-- <th style="color: white; text-align: center; font-size: 11px; padding: 10px;">Type</th> --}}
                    <th style="color: white; text-align: center; font-size: 11px; padding: 10px;">Item</th>
                    <th style="color: white; text-align: center; font-size: 11px; padding: 10px;">Qty</th>
                    <th style="color: white; text-align: center; font-size: 11px; padding: 10px;">Price</th>
                    <th style="color: white; text-align: center; font-size: 11px; padding: 10px;">Discount</th>
                    <th style="color: white; text-align: center; font-size: 11px; padding: 10px;">VAT</th>
                    <th style="color: white; text-align: center; font-size: 11px; padding: 10px;">Total</th>
                </tr>
            </thead>
<tbody>
    @foreach ($quotation->items as $item)
    @php
        $itemPrice = (float) ($item->price ?? 0);
        $itemQty = (float) ($item->qty ?? 1);
        $itemDiscount = (float) ($item->discount ?? 0);  // Discount TOTAL per baris
        $itemVatPct = (float) ($item->vat_percentage ?? 0);
        $itemSubTotal = $itemPrice * $itemQty;
        // Diskon tidak dikalikan qty: nilainya sudah total untuk baris ini.
        $itemAfterDiscount = max($itemSubTotal - $itemDiscount, 0);
        $itemVat = $itemAfterDiscount * ($itemVatPct / 100);
        $itemTotal = $itemAfterDiscount + $itemVat;
    @endphp
    <tr style="border-bottom: 1px solid #ddd;">
        {{-- <td style="text-align: center; font-size: 11px; padding: 10px;">Service</td> --}}
        <td style="text-align: left; font-size: 11px; padding: 10px;">
            {{ $item->item }}<br>
            <span style="font-size: 9px; color: #666;">{!! str($item->description ?? '')->sanitizeHtml() !!}</span>
        </td>

        <td style="text-align: center; font-size: 11px; padding: 10px;">
            {{ $itemQty }}
        </td>

        {{-- Price --}}
        <td style="text-align: center; font-size: 11px; padding: 10px;">
            Rp{{ number_format($itemPrice, 0, ',', '.') }}
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
    </div>

    {{--
        Terms & Conditions bersifat opsional, ringkasan total tidak.

        Sebelumnya keduanya terbungkus dalam satu @if(count($terms) > 0), sehingga
        quotation yang settings-nya belum punya Terms & Conditions terkirim ke klien
        TANPA Sub Total maupun Grand Total sama sekali.
    --}}
    <div style="padding: 15px 25px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    @if(count($terms) > 0)
                        <div style="font-size: 9px; line-height: 1.5;">
                            <strong>Terms &amp; Conditions:</strong><br>
                            @foreach($terms as $i => $termText)
                                {{ $i + 1 }}. {{ $termText }}<br>
                            @endforeach
                        </div>
                    @endif
                </td>
                <td style="width: 50%; text-align: right; font-size: 12px;">
                    {{--
                        Urutan mengikuti cara angkanya dihitung — Sub Total, dikurangi
                        diskon, lalu ditambah PPN — supaya penerima bisa menjumlahkan
                        sendiri dan sampai pada Grand Total yang sama.
                    --}}
                    <strong>Sub Total:</strong> Rp{{ number_format($calcSubTotal, 0, ',', '.') }}<br>
                    @if($calcTotalDiscount > 0)
                        <strong>Total Diskon:</strong> -Rp{{ number_format($calcTotalDiscount, 0, ',', '.') }}<br>
                    @endif
                    @if($calcTotalVat > 0)
                        <strong>Total VAT:</strong> Rp{{ number_format($calcTotalVat, 0, ',', '.') }}<br>
                    @endif
                    <div style="padding-top: 6px;">
                        <strong style="font-size: 14px;">Grand Total:</strong>
                        Rp{{ number_format($calcGrandTotal, 0, ',', '.') }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Signature --}}
    <div style="padding: 40px 25px 30px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; text-align: center; vertical-align: bottom;">
                    <p style="margin: 0 0 50px 0;">Sincerely Yours,</p>
                    @if($signatureImage)
                        @php $sigData = getImageData($signatureImage); @endphp
                        @if($sigData)
                            <img src="data:{{ getImageMimeType($signatureImage) }};base64,{{ $sigData }}" style="max-height: 50px;">
                        @endif
                    @endif
                    <p style="margin: 10px 0 0 0;">{{ $signatureName }}</p>
                    <p style="margin: 0; font-size: 10px; color: #666;">{{ $signatureTitle }}</p>
                </td>
                <td style="width: 50%; text-align: center; vertical-align: bottom;">
                    <p style="margin: 0 0 50px 0;">Checked & Approve By:</p>
                    <p style="margin: 50px 0 0 0; border-bottom: 1px solid #333; display: inline-block; width: 150px;">&nbsp;</p>
                    <p style="margin: 10px 0 0 0;">PIC</p>
                </td>
            </tr>
        </table>
    </div>

</div>

{{-- Cover Page 3 (Footer) --}}
@if($showCoverPages && $coverPage3)
    <div style="page-break-before: always;"></div>
    @php $cover3Data = getImageData($coverPage3); @endphp
    @if($cover3Data)
        <img src="data:{{ getImageMimeType($coverPage3) }};base64,{{ $cover3Data }}" style="width: 100%;">
    @endif
@endif

</body>
</html>