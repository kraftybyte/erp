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
     * Rincian pekerjaan dalam Markdown, satu butir per item invoice.
     */
    public static function detail(Invoice $invoice): string
    {
        $items = $invoice->invoicesItems->map(function ($item) {
            $qty  = (float) $item->qty;
            $line = '- **' . trim((string) $item->item) . '**' . ($qty > 0 && $qty != 1 ? ' (' . ($qty + 0) . 'x)' : '');

            $description = self::plainText($item->description);

            return $description === ''
                ? $line
                : $line . "\n" . collect(explode("\n", $description))->map(fn (string $text) => "  {$text}")->implode("\n");
        });

        return trim('Dari invoice ' . $invoice->uuid . "\n\n" . $items->implode("\n"));
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
