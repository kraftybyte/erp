{{-- Slip gaji (DomPDF). $slip: Payslip dengan items; $company: GeneralSettings::getInvoiceSettings(); $settings: Payroll::settings(). --}}
@php
    $rp       = fn ($amount) => 'Rp ' . number_format((float) $amount, 0, ',', '.');
    $earnings = $slip->items->where('type', \App\Models\PayrollComponent::EARNING)->values();
    $cuts     = $slip->items->where('type', \App\Models\PayrollComponent::DEDUCTION)->values();
    $rows     = max($earnings->count(), $cuts->count(), 1);
    $logo     = $company['logo'] ?? null;
    $logoData = $logo && file_exists(public_path('storage/' . $logo)) ? base64_encode(file_get_contents(public_path('storage/' . $logo))) : null;
    $date     = fn ($value) => $value ? \Carbon\Carbon::parse($value)->locale('id')->translatedFormat('j F Y') : null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $slip->fileName() }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2937; }
        .accent { height: 4px; background: {{ $color }}; margin: -36px -40px 24px; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .company { font-size: 12px; font-weight: bold; color: #111827; }
        .muted { color: #6b7280; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 18px; letter-spacing: .5px; color: {{ $color }}; }
        .title p { margin: 2px 0 0; font-size: 11px; font-weight: bold; color: #111827; }
        .status { display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: bold; background: #f3f4f6; color: #374151; }
        .employee { margin-top: 20px; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
        .employee td { padding: 8px 0; width: 25%; vertical-align: top; }
        .employee .label { display: block; font-size: 8.5px; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
        .employee .value { display: block; margin-top: 2px; font-size: 10.5px; font-weight: bold; color: #111827; }
        .lines { margin-top: 18px; }
        .lines th { padding: 6px 8px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; color: #374151; background: #f3f4f6; }
        .lines td { padding: 6px 8px; border-bottom: 1px solid #f1f1f1; }
        .lines .num { text-align: right; white-space: nowrap; }
        .lines .gap { width: 16px; background: none; border: 0; }
        .lines tfoot td { font-weight: bold; border-top: 1px solid #d1d5db; border-bottom: 0; color: #111827; }
        .net { margin-top: 18px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .net td { padding: 12px 14px; }
        .net .label { font-size: 10px; font-weight: bold; color: #374151; }
        .net .amount { text-align: right; font-size: 16px; font-weight: bold; color: {{ $color }}; }
        .notes { margin-top: 14px; }
        .foot { margin-top: 36px; }
        .foot td { vertical-align: bottom; }
        .sign { text-align: center; width: 38%; }
        .sign .name { margin-top: 48px; font-weight: bold; color: #111827; border-top: 1px solid #9ca3af; padding-top: 4px; }
    </style>
</head>
<body>
    <div class="accent"></div>

    <table class="head">
        <tr>
            <td style="width: 60%;">
                @if($logoData)
                    <img src="data:image/png;base64,{{ $logoData }}" style="max-height: 46px; margin-bottom: 6px;" alt="Logo">
                @endif
                <div class="company">{{ $company['company_info']['name'] ?? '' }}</div>
                <div class="muted">{{ $company['company_info']['address'] ?? '' }}</div>
                @if(filled($company['company_info']['phone'] ?? null))
                    <div class="muted">{{ $company['company_info']['phone'] }}</div>
                @endif
            </td>
            <td class="title">
                <h1>SLIP GAJI</h1>
                <p>{{ $slip->periodLabel() }}</p>
                <span class="status">{{ \App\Models\Payslip::STATUSES[$slip->status] ?? $slip->status }}{{ $slip->status === \App\Models\Payslip::PAID && $slip->paid_at ? ' · ' . $date($slip->paid_at) : '' }}</span>
            </td>
        </tr>
    </table>

    <table class="employee">
        <tr>
            <td><span class="label">Nama</span><span class="value">{{ $slip->employee_name }}</span></td>
            <td><span class="label">NIK</span><span class="value">{{ $slip->employee_nik ?: '-' }}</span></td>
            <td><span class="label">Jabatan</span><span class="value">{{ $slip->position ?: '-' }}</span></td>
            <td><span class="label">Divisi</span><span class="value">{{ $slip->division ?: '-' }}</span></td>
        </tr>
        @if(filled($slip->npwp))
            <tr><td colspan="4" style="padding-top: 0;"><span class="label">NPWP</span><span class="value">{{ $slip->npwp }}</span></td></tr>
        @endif
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Pendapatan</th><th class="num">Jumlah</th>
                <th class="gap"></th>
                <th>Potongan</th><th class="num">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @for($i = 0; $i < $rows; $i++)
                <tr>
                    <td>{{ $earnings[$i]->name ?? '' }}</td>
                    <td class="num">{{ isset($earnings[$i]) ? $rp($earnings[$i]->amount) : '' }}</td>
                    <td class="gap"></td>
                    <td>{{ $cuts[$i]->name ?? ($i === 0 && $cuts->isEmpty() ? 'Tidak ada potongan' : '') }}</td>
                    <td class="num">{{ isset($cuts[$i]) ? $rp($cuts[$i]->amount) : '' }}</td>
                </tr>
            @endfor
        </tbody>
        <tfoot>
            <tr>
                <td>Total pendapatan</td><td class="num">{{ $rp($slip->earnings) }}</td>
                <td class="gap"></td>
                <td>Total potongan</td><td class="num">{{ $rp($slip->deductions) }}</td>
            </tr>
        </tfoot>
    </table>

    <table class="net">
        <tr>
            <td class="label">Take home pay (diterima)</td>
            <td class="amount">{{ $rp($slip->net) }}</td>
        </tr>
    </table>

    @if(filled($slip->notes))
        <div class="notes"><strong>Catatan:</strong> {{ $slip->notes }}</div>
    @endif

    <table class="foot">
        <tr>
            <td class="muted">
                @if($slip->status === \App\Models\Payslip::PAID && $slip->paymentAccount)
                    Dibayarkan {{ $date($slip->paid_at) }} dari {{ $slip->paymentAccount->name }}.<br>
                @endif
                {{ $settings['slip_note'] ?? '' }}
            </td>
            <td class="sign">
                @if(filled($settings['signature_name'] ?? null))
                    <div class="name">{{ $settings['signature_name'] }}</div>
                    <div class="muted">{{ $settings['signature_title'] ?? '' }}</div>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
