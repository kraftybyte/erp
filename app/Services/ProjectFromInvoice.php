<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicesItem;
use Illuminate\Support\Carbon;

/**
 * Isi form project dari invoice: client, PIC, alamat, dan rincian pekerjaan
 * dari item invoice. Kategori project tetap dipilih sendiri oleh pengguna.
 */
class ProjectFromInvoice
{
    public static function formData(Invoice $invoice): array
    {
        $deal  = self::deal($invoice);
        $start = $invoice->date ? Carbon::parse($invoice->date) : today();
        $due   = $invoice->due_date ? Carbon::parse($invoice->due_date) : null;

        return [
            'deal_id'          => $deal?->id,
            // PIC & alamat order ini bisa berbeda dari data client, jadi invoice didahulukan.
            'nama_perusahaan'  => $invoice->nama_perusahaan ?: $deal?->nama_perusahaan,
            'nama_pic'         => self::filled($invoice->nama_pic) ?? $deal?->nama_pic,
            'alamat'           => self::filled($invoice->alamat) ?? $deal?->alamat,
            'notes'            => $deal?->notes,
            'detail_project'   => self::detail($invoice),
            'timeline_mulai'   => $start->toDateString(),
            'timeline_selesai' => ($due && $due->gt($start) ? $due : $start->copy()->addDays(30))->toDateString(),
        ];
    }

    /**
     * Client invoice: tautan deal_id, atau client dengan nama perusahaan yang sama
     * (dan PIC yang sama bila ada beberapa). Null bila tidak bisa dipastikan.
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
     * Rincian pekerjaan dalam Markdown, satu butir per item invoice.
     */
    public static function detail(Invoice $invoice): string
    {
        $items = $invoice->invoicesItems->map(function (InvoicesItem $item) {
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
