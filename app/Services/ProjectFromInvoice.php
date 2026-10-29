<?php

namespace App\Services;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Project langsung dari invoice: client, PIC, alamat, dan rincian pekerjaan
 * diambil dari invoice; pengguna hanya memilih kategori project.
 */
class ProjectFromInvoice
{
    /**
     * Buat project untuk invoice ini, atau kembalikan project yang sudah dibuat
     * darinya (klik ganda / dua orang bersamaan tidak membuat project dobel).
     */
    public static function create(Invoice $invoice, string $category): Project
    {
        return DB::transaction(function () use ($invoice, $category) {
            $invoice = Invoice::with('invoicesItems')->lockForUpdate()->findOrFail($invoice->getKey());

            if ($existing = $invoice->projects()->first()) {
                return $existing;
            }

            $deal = self::deal($invoice) ?? self::createDeal($invoice);

            // Invoice ikut tertaut ke client-nya (dulu tautan ini sering hilang).
            if ($invoice->deal_id !== $deal->id) {
                $invoice->forceFill(['deal_id' => $deal->id])->saveQuietly();
            }

            return Project::create(self::attributes($invoice, $deal) + [
                'nama_project' => $category,
                'invoice_id'   => $invoice->id,
            ]);
        });
    }

    /**
     * Client invoice: tautan deal_id, atau client dengan nama perusahaan yang sama
     * (dan PIC yang sama bila ada beberapa). Null bila belum ada.
     */
    public static function deal(Invoice $invoice): ?Deal
    {
        if ($invoice->deal_id && $deal = Deal::find($invoice->deal_id)) {
            return $deal;
        }

        if (blank($invoice->nama_perusahaan)) {
            return null;
        }

        $candidates = Deal::where('nama_perusahaan', trim($invoice->nama_perusahaan))->get();

        return $candidates->first(fn (Deal $deal) => strcasecmp(trim((string) $deal->nama_pic), trim((string) $invoice->nama_pic)) === 0)
            ?? ($candidates->count() === 1 ? $candidates->first() : null);
    }

    /**
     * Isi project. PIC & alamat order ini bisa berbeda dari data client, jadi
     * invoice didahulukan.
     */
    public static function attributes(Invoice $invoice, Deal $deal): array
    {
        [$start, $end] = self::timeline($invoice);

        return [
            'deal_id'          => $deal->id,
            'nama_perusahaan'  => self::filled($invoice->nama_perusahaan) ?? $deal->nama_perusahaan,
            'nama_pic'         => self::filled($invoice->nama_pic) ?? $deal->nama_pic,
            'alamat'           => self::filled($invoice->alamat) ?? $deal->alamat,
            'notes'            => $deal->notes,
            'detail_project'   => self::detail($invoice),
            'timeline_mulai'   => $start->toDateString(),
            'timeline_selesai' => $end->toDateString(),
        ];
    }

    /**
     * Tanggal invoice s/d jatuh tempo, atau 30 hari bila jatuh tempo kosong.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function timeline(Invoice $invoice): array
    {
        $start = $invoice->date ? Carbon::parse($invoice->date) : today();
        $due   = $invoice->due_date ? Carbon::parse($invoice->due_date) : null;

        return [$start, $due && $due->gt($start) ? $due : $start->copy()->addDays(30)];
    }

    /**
     * Rincian pekerjaan dalam Markdown, satu butir bernomor per item invoice:
     *
     *   1. **FLORING 600X600** (Custom Signage)
     *      - Multiplek 18mm
     *      - Rangka 18mm
     *   2. **REVAMP** · 4x — Meja Cashier, Meja Display
     */
    public static function detail(Invoice $invoice): string
    {
        $lines = $invoice->invoicesItems->values()
            ->flatMap(fn ($item, int $index) => self::itemLines($item, $index + 1));

        return trim('Dari invoice ' . $invoice->uuid . "\n\n" . $lines->implode("\n"));
    }

    /**
     * @return array<int, string>
     */
    private static function itemLines($item, int $number): array
    {
        $name  = self::clean((string) $item->item);
        $parts = self::paragraphs($item->description);
        $title = null;
        $specs = [];

        // Deskripsi berpola "BACKDROP 600x40 (Melaminto 9mm + Rangka 18mm)" atau
        // "Mesin Capit | Instalasi & Mobilisasi": judul + rincian yang dipisah "+".
        $first = $parts[0] ?? '';
        if (preg_match('/^([^()|]+?)\s*\((.+)\)$/u', $first, $match) || preg_match('/^([^()|]+?)\s*\|\s*(.+?)\)?$/u', $first, $match)) {
            array_shift($parts);
            $title = self::clean($match[1]);
            $specs = preg_split('/\s*\+\s*/u', $match[2]);
        }

        // Nama item yang hanya mengulang deskripsi (atau sependek "M") tidak ditampilkan.
        $meaningful = mb_strlen($name) >= 3;
        $repeated   = self::wordsIn($name, implode(' ', [(string) $title, ...$specs, ...$parts]));

        if ($title === null) {
            $title = $meaningful ? $name : (array_shift($parts) ?? 'Item');
            $label = null;
        } else {
            $label = $meaningful && ! $repeated ? $name : null;
        }

        $specs = collect([...$specs, ...$parts])
            ->map(fn (string $spec) => self::spec($spec))
            ->filter(fn (?string $spec) => $spec !== null && mb_strtolower($spec) !== mb_strtolower($title))
            ->values()
            ->all();

        $qty  = (float) $item->qty;
        $head = "{$number}. **" . self::escape($title) . '**'
            . ($label ? ' (' . self::escape($label) . ')' : '')
            . ($qty > 0 && $qty != 1 ? ' · ' . ($qty + 0) . 'x' : '');

        if (count($specs) === 1) {
            return [$head . ' — ' . self::escape($specs[0])];
        }

        $indent = str_repeat(' ', strlen("{$number}. "));

        return [$head, ...array_map(fn (string $spec) => "{$indent}- " . self::escape($spec), $specs)];
    }

    /**
     * Baris deskripsi. Baris yang terpotong di tengah kalimat (kurung belum
     * ditutup, atau diakhiri "+" / ",") disambung; butir "•" tetap terpisah.
     *
     * @return array<int, string>
     */
    private static function paragraphs(?string $html): array
    {
        $result = [];

        foreach (explode("\n", self::plainText($html)) as $line) {
            $bullet = str_starts_with($line, '•');
            $line   = trim(ltrim($line, '•'));
            $last   = count($result) - 1;

            if ($last >= 0 && ! $bullet && (substr_count($result[$last], '(') > substr_count($result[$last], ')') || preg_match('/[+,]$/u', $result[$last]))) {
                $result[$last] .= ' ' . $line;
            } else {
                $result[] = $line;
            }
        }

        return array_values(array_filter(array_map(fn (string $line) => self::clean($line), $result)));
    }

    private static function spec(string $text): ?string
    {
        $text = rtrim(ltrim(self::clean($text), '-–• '), ' .');
        $text = preg_replace('/,(?=\p{L})/u', ', ', $text); // "Duco,Cat" → "Duco, Cat"; "22,2" tetap

        return $text === '' ? null : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Semua kata di $needle juga ada di $haystack.
     */
    private static function wordsIn(string $needle, string $haystack): bool
    {
        $words = fn (string $text) => preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_diff($words($needle), $words($haystack)) === [];
    }

    private static function escape(string $text): string
    {
        return str_replace('*', '\\*', $text);
    }

    /**
     * Client baru dari data invoice, untuk pelanggan yang belum tercatat di CRM.
     */
    private static function createDeal(Invoice $invoice): Deal
    {
        return Deal::create([
            'nama_perusahaan' => self::filled($invoice->nama_perusahaan) ?? self::filled($invoice->nama_pic) ?? 'Tanpa nama',
            'nama_pic'        => self::filled($invoice->nama_pic) ?? '',
            'telephone'       => self::filled($invoice->telephone),
            'alamat'          => self::filled($invoice->alamat),
            'status'          => DealStatus::DalamTahapProject->value,
            'notes'           => "Dibuat otomatis dari invoice {$invoice->uuid}.",
        ]);
    }

    /**
     * Deskripsi item (HTML dari RichEditor atau import Excel) menjadi teks biasa.
     */
    private static function plainText(?string $html): string
    {
        $text = preg_replace(['#<\s*br\s*/?>#i', '#</(p|div|li|h[1-6])>#i', '#<li[^>]*>#i'], ["\n", "\n", '• '], (string) $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return collect(explode("\n", $text))->map(fn (string $line) => trim($line))->filter()->implode("\n");
    }

    private static function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : $value;
    }
}
