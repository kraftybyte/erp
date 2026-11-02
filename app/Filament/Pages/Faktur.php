<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AdvanceResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\InvoiceResource;
use App\Http\Controllers\FakturController;
use App\Models\Expense;
use App\Models\Invoice;
use App\Services\FakturCollector;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Pengepulan faktur sebulan: Keluaran (nomor faktur pajak invoice ber-PPN,
 * bisa diisi langsung di sini) dan Masukan (nota Expense dan struk Realisasi).
 */
class Faktur extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Faktur';

    protected static ?string $title = 'Faktur';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.faktur';

    /** "2026-10" */
    #[Url]
    public string $bulan = '';

    #[Url]
    public string $tab = 'keluaran';

    // Hanya baris yang fakturnya belum ada.
    #[Url(as: 'kurang')]
    public bool $belumAda = false;

    public static function canAccess(): bool
    {
        return FakturCollector::visibleTo(auth()->user());
    }

    public function mount(): void
    {
        $this->updatedBulan();

        if (! in_array($this->tab, ['keluaran', 'masukan'], true)) {
            $this->tab = 'keluaran';
        }
    }

    public function updatedBulan(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->bulan)) {
            $this->bulan = now()->format('Y-m');
        }
    }

    public function geserBulan(int $langkah): void
    {
        $this->bulan = Carbon::createFromFormat('!Y-m', $this->bulan)->addMonthsNoOverflow($langkah)->format('Y-m');
    }

    public function simpanFaktur(int $invoiceId, string $nomor): void
    {
        abort_unless(static::canAccess(), 403);

        $nomor = trim($nomor);

        if (mb_strlen($nomor) > 100) {
            Notification::make()->danger()->title('Nomor faktur maksimal 100 karakter.')->send();

            return;
        }

        $invoice = Invoice::where('type', 'sale')->findOrFail($invoiceId);

        if ((string) $invoice->faktur === $nomor) {
            return;
        }

        $invoice->update(['faktur' => $nomor === '' ? null : $nomor]);

        $invoice->invoiceLogs()->create([
            'log'  => 'Nomor faktur pajak ' . ($nomor === '' ? 'dikosongkan' : "diisi {$nomor}") . ' By: ' . auth()->user()->name,
            'type' => 'updated',
        ]);

        Notification::make()
            ->success()
            ->title($nomor === '' ? "Nomor faktur {$invoice->uuid} dikosongkan" : "Nomor faktur {$invoice->uuid} tersimpan")
            ->send();
    }

    public function invoiceUrl(Invoice $invoice): string
    {
        return InvoiceResource::getUrl('view', ['record' => $invoice]);
    }

    /**
     * Expense: halaman expense-nya. Realisasi: halaman realisasi advance-nya.
     */
    public function recordUrl(array $row): string
    {
        return $row['record'] instanceof Expense
            ? ExpenseResource::getUrl('view', ['record' => $row['record']])
            : AdvanceResource::getUrl('realisasi', ['record' => $row['record']]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Unduh Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(fn () => route('faktur.excel', $this->bulan)),
            Action::make('zip')
                ->label('Unduh ZIP faktur masukan')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn () => route('faktur.zip', $this->bulan)),
        ];
    }

    protected function getViewData(): array
    {
        $period    = FakturController::period($this->bulan);
        $collector = app(FakturCollector::class);
        $keluaran  = $collector->keluaran($period);
        $masukan   = $collector->masukan($period);

        $tanpaFile = fn (array $row) => ! collect($row['files'])->contains('exists', true);

        return [
            'periode'  => $period->label(),
            'keluaran' => $this->belumAda ? $keluaran->whereNull('faktur')->values() : $keluaran,
            'masukan'  => $this->belumAda ? $masukan->filter($tanpaFile)->values() : $masukan,
            'ringkas'  => [
                'keluaran'         => $keluaran->count(),
                'dpp'              => $keluaran->sum('dpp'),
                'ppn'              => $keluaran->sum('ppn'),
                'keluaranBelumAda' => $keluaran->whereNull('faktur')->count(),
                'masukan'          => $masukan->count(),
                'nominal'          => $masukan->sum('amount'),
                'masukanBelumAda'  => $masukan->filter($tanpaFile)->count(),
            ],
        ];
    }
}
