<?php

namespace App\Services;

use App\Models\Invoice;
use App\Settings\GeneralSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Dompdf\Canvas;
use Dompdf\FontMetrics;

/**
 * PDF invoice/proforma dari template pdf.invoice-dynamic.
 */
class InvoicePdf
{
    private const DEFAULT_COLOR = '#f04f29';

    public static function make(Invoice $invoice): DomPdf
    {
        $invoice->loadMissing('invoicesItems');

        $settings = app(GeneralSettings::class);

        $pdf = Pdf::loadView('pdf.invoice-dynamic', [
            'invoice'    => $invoice,
            'settings'   => $settings->getInvoiceSettings(),
            'themeColor' => $settings->inv_theme_color,
        ]);

        $pdf->render();

        // Garis aksen (hanya halaman pertama) dan nomor halaman digambar langsung
        // ke kertas: keduanya berada di area margin, yang tidak digambar dompdf
        // dari HTML.
        $color = self::rgb($settings->inv_theme_color);

        $pdf->getDomPDF()->getCanvas()->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $fonts) use ($color) {
            if ($page === 1) {
                $canvas->filled_rectangle(0, 0, $canvas->get_width(), 4, $color);
            }

            if ($pages > 1) {
                $font  = $fonts->getFont('DejaVu Sans');
                $label = "{$page} / {$pages}";
                $width = $fonts->getTextWidth($label, $font, 8);

                $canvas->text($canvas->get_width() - $width - 24, $canvas->get_height() - 20, $label, $font, 8, [0.55, 0.55, 0.55]);
            }
        });

        return $pdf;
    }

    /**
     * Warna tema (#rrggbb) dalam format dompdf: tiga angka 0–1.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rgb(?string $hex): array
    {
        $hex = is_string($hex) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : self::DEFAULT_COLOR;

        return array_map(fn (string $part) => hexdec($part) / 255, str_split(substr($hex, 1), 2));
    }
}
