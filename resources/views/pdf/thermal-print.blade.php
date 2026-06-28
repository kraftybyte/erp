<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packing Slip #{{ $project->id }}</title>
    <style>
        @page {
            margin: 0;
            padding: 0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            background: #fff;
        }

        .thermal-paper {
            width: 80mm;
            min-height: auto;
            margin: 0 auto;
            padding: 3px;
        }

        .thermal-paper.size-58 {
            width: 58mm;
        }

        .thermal-paper.size-80 {
            width: 80mm;
        }

        .thermal-paper.size-100 {
            width: 100mm;
        }

        .header {
            text-align: center;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }

        .header h1 {
            font-size: 14px;
            font-weight: bold;
            margin: 0 0 2px 0;
        }

        .header .tracking-number {
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .divider {
            border: none;
            border-top: 1px dashed #000;
            margin: 5px 0;
        }

        .section-title {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
            margin-bottom: 3px;
        }

        .info-line {
            margin-bottom: 2px;
            word-wrap: break-word;
        }

        .info-label {
            font-weight: bold;
            font-size: 9px;
        }

        .info-value {
            font-size: 11px;
        }

        .info-value.large {
            font-size: 13px;
            font-weight: bold;
        }

        .qr-section {
            text-align: center;
            padding: 5px 0;
            margin: 5px 0;
        }

        .qr-code img {
            width: 45mm;
            height: 45mm;
        }

        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .thermal-paper {
                width: 80mm !important;
                padding: 0 !important;
            }

            .no-print {
                display: none !important;
            }
        }

        .preview-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #fff;
            border: 2px solid #000;
            padding: 15px;
            z-index: 1000;
        }

        .preview-controls h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
        }

        .preview-controls label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
        }

        .preview-controls select {
            width: 100%;
            padding: 5px;
            margin-bottom: 10px;
        }

        .preview-controls button {
            width: 100%;
            padding: 8px;
            margin-bottom: 5px;
            cursor: pointer;
            background: #000;
            color: #fff;
            border: none;
        }
    </style>
</head>
<body>
    <div class="preview-controls no-print">
        <h3>Pengaturan Print</h3>
        <label>Ukuran:</label>
        <select id="paperSize" onchange="changePaperSize()">
            <option value="58">58mm</option>
            <option value="80" selected>80mm</option>
            <option value="100">100mm</option>
        </select>
        <button onclick="window.print()">PRINT</button>
    </div>

    <div class="thermal-paper" id="thermalPaper">
        <div class="header">
            <h1>{{ $packingTitle ?? 'PACKING SLIP' }}</h1>
            <div class="tracking-number">#{{ $project->id }}</div>
        </div>

        <div class="section-title">PENGIRIM</div>
        <div class="info-line">
            <span class="info-label">Nama:</span>
            <span class="info-value large">{{ $project->sender_name ?? '-' }}</span>
        </div>
        <div class="info-line">
            <span class="info-label">Alamat:</span>
            <span class="info-value">{{ $project->sender_address ?? '-' }}</span>
        </div>
        <div class="info-line">
            <span class="info-label">Telp:</span>
            <span class="info-value">{{ $project->sender_phone ?? '-' }}</span>
        </div>

        <hr class="divider">

        <div class="section-title">PENERIMA</div>
        <div class="info-line">
            <span class="info-label">Nama:</span>
            <span class="info-value large">{{ $project->recipient_name ?? '-' }}</span>
        </div>
        <div class="info-line">
            <span class="info-label">Alamat:</span>
            <span class="info-value">{{ $project->recipient_address ?? '-' }}</span>
        </div>
        <div class="info-line">
            <span class="info-label">Telp:</span>
            <span class="info-value">{{ $project->recipient_phone ?? '-' }}</span>
        </div>

        <hr class="divider">

        <div class="qr-section">
            <div class="qr-code">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&format=png&color=000000&bgcolor=ffffff&data={{ urlencode($qrUrl) }}" alt="QR" />
            </div>
        </div>
    </div>

    <script>
        function changePaperSize() {
            const size = document.getElementById('paperSize').value;
            const paper = document.getElementById('thermalPaper');
            paper.style.width = size + 'mm';
        }
        document.addEventListener('DOMContentLoaded', function() {
            changePaperSize();
        });
    </script>
</body>
</html>
