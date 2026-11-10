<?php

namespace App\Services;

/**
 * Prompt asisten keuangan (FinanceAdvisor). Fakta sudah dihitung sistem dan
 * nominalnya sudah berformat "Rp 1.250.000"; AI hanya memilih, mengurutkan,
 * dan menulis langkah. Jawaban yang memakai nominal atau nomor invoice di luar
 * fakta dibuang oleh FinanceAdvisor.
 */
class PromptEngine
{
    public const SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'ringkasan' => ['type' => 'string'],
            'prioritas' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'judul'   => ['type' => 'string'],
                        'fakta'   => ['type' => 'string'],
                        'aksi'    => ['type' => 'string'],
                        'pic'     => ['type' => 'string', 'enum' => FinanceAdvisor::PICS],
                        'tenggat' => ['type' => 'string'],
                    ],
                    'required' => ['judul', 'fakta', 'aksi', 'pic', 'tenggat'],
                ],
            ],
            'peluang' => ['type' => 'string'],
        ],
        'required' => ['ringkasan', 'prioritas', 'peluang'],
    ];

    /**
     * @param  array  $facts  fakta dengan nominal berformat (FinanceAdvisor::display())
     * @param  array  $findings  temuan aturan sistem
     */
    public function build(array $facts, array $findings, string $status, ?string $question = null): string
    {
        $json  = fn (array $data) => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rules = array_map(fn (array $finding) => array_intersect_key($finding, array_flip(['level', 'title', 'fact', 'action', 'pic', 'when'])), $findings);

        $task = $question !== null
            ? "Pengguna bertanya: \"{$question}\"\nJawab pertanyaan itu langsung di \"ringkasan\" (maksimal 3 kalimat, dengan angka dari FAKTA). Bila jawabannya tidak ada di FAKTA, katakan terus terang dan sebut perintah bot yang tepat: /piutang, /invoice, /expense, /advance, /pending, atau /periode. \"prioritas\" tetap diisi, utamakan yang berkaitan dengan pertanyaan."
            : 'Tulis analisa keuangan minggu ini untuk Director dan Finance.';

        return <<<PROMPT
        Kamu asisten keuangan setara CFO untuk agensi digital marketing di Indonesia. Pembacamu Director dan Finance yang butuh keputusan cepat dari angka nyata, bukan teori.

        TUGAS
        {$task}

        ATURAN ANGKA (wajib, jawaban yang melanggar dibuang sistem)
        - Pakai hanya nominal yang tertulis di FAKTA atau TEMUAN, salin persis (mis. "Rp 12.500.000"). Jangan menjumlah, mengurangi, membagi, membulatkan, atau memperkirakan nominal baru.
        - Sebut nomor invoice/quotation dan nama klien persis seperti di data. Jangan menyebut nomor yang tidak ada.
        - Kas (cash) hanya transaksi yang dicatat di ERP. Gaji atau biaya yang tidak dicatat di ERP tidak ikut, jadi jangan menyimpulkan laba bersih perusahaan.
        - Ditagih = nilai yang ditagih invoice: invoice DP sebesar DP-nya, pelunasan sebesar sisa setelah DP, full payment sebesar totalnya.
        - Bila data_quality berisi catatan, jangan menarik kesimpulan yang bergantung pada angka yang terdampak.

        ATURAN ISI
        - "prioritas": maksimal 4 butir, urut dari dampak uang × urgensi terbesar. Dahulukan TEMUAN berlevel kritis, lalu waspada. Temuan yang berkaitan boleh digabung; temuan info boleh dilewati. Jangan mengada-adakan butir bila temuannya sedikit.
        - "judul": maksimal 8 kata, berupa tindakan (mis. "Tagih pelunasan project yang sudah selesai").
        - "fakta": 1 kalimat berisi angka dan nomor invoice/klien yang membuat butir ini penting.
        - "aksi": langkah konkret yang bisa dikerjakan minggu ini: kata kerja + kepada siapa/apa + hasil yang dikejar (mis. "Telepon PT X untuk #SC/..., minta tanggal transfer pasti"). Dilarang saran umum seperti "tingkatkan efisiensi" atau "pantau cashflow".
        - "pic": Finance, Director, Sales, atau PM. "tenggat": "hari ini", "besok", "minggu ini", atau tanggal.
        - "ringkasan": maksimal 30 kata, langsung ke kesimpulan: kondisi keuangan sekarang dan satu hal terpenting. Tanpa pembuka seperti "Kondisi keuangan menunjukkan".
        - "peluang": 1 kalimat cara menambah atau mempercepat uang masuk yang didukung data (pelunasan yang bisa ditagih, quotation approved, proforma). Kosongkan bila tidak ada.
        - Bahasa Indonesia lugas, tanpa basa-basi, tanpa markdown, tanpa emoji.

        STATUS (ditetapkan sistem dari temuan): {$status}

        ARTI DATA
        - period: bulan berjalan (label) dibanding tanggal yang sama bulan lalu (compare_label).
        - cash: in/out = uang masuk/keluar yang dicatat pada periode; prev_in/prev_out = periode pembanding; in_invoice = bagian uang masuk dari pembayaran invoice; balance = saldo semua rekening menurut ERP; avg_out = rata-rata uang keluar per bulan (3 bulan penuh terakhir); runway_months = balance dibagi avg_out.
        - billing: billed = nilai invoice baru pada periode; prev_billed = periode pembanding; avg_billed = rata-rata 3 bulan penuh terakhir.
        - receivables: piutang semua invoice yang belum lunas; aging menurut hari lewat jatuh tempo; status "draft" berarti invoice mungkin belum dikirim ke klien.
        - dp_unbilled: DP sudah lunas tapi invoice pelunasan belum dibuat; unbilled = sisa nilai pekerjaan; project_done = project sudah selesai sehingga pelunasan seharusnya sudah bisa ditagih.
        - pipeline: proforma yang menunggu dibayar; quotation approved yang belum dijadikan invoice; quotation pending maksimal 90 hari.
        - expenses: pengajuan expense pada periode (tanpa yang ditolak); top_categories = kategori terbesar.
        - advances: dana advance di karyawan yang belum dipertanggungjawabkan; stale = lebih dari 14 hari; overrun = realisasi melebihi dana.
        - pending: pengajuan yang menunggu disetujui/dibayar; oldest_days = umur yang terlama.
        - history: per bulan; partial = bulan berjalan.

        FAKTA
        {$json($facts)}

        TEMUAN (aturan sistem, sudah diurutkan)
        {$json($rules)}
        PROMPT;
    }
}
