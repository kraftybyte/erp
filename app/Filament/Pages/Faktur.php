<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AdvanceResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\InvoiceResource;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\FakturController;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\RealisasiFile;
use App\Models\RealisasiItem;
use App\Services\FakturCollector;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Pengepulan faktur sebulan: Keluaran (nomor faktur pajak invoice ber-PPN,
 * bisa diisi langsung di sini) dan Masukan (nota Expense dan struk Realisasi,
 * yang belum ada bisa di-upload langsung di sini).
 */
class Faktur extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Faktur';

    protected static ?string $title = 'Faktur';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.faktur';

    private const FILE_TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

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

    /**
     * Upload faktur pembelian untuk baris Masukan yang belum ada fakturnya.
     * Expense: mengisi kolom nota yang sama dengan tombol Upload Nota (syarat
     * tombol Bayar). Realisasi: file tambahan item, seperti di halaman Realisasi.
     */
    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Upload')
            ->icon('heroicon-m-arrow-up-tray')
            ->link()
            ->size('sm')
            ->modalHeading('Upload faktur pembelian')
            ->modalDescription(fn (array $arguments) => $arguments['label'] ?? null)
            ->modalSubmitActionLabel('Simpan')
            ->modalWidth('lg')
            ->form([
                FileUpload::make('file')
                    ->label('File faktur / nota')
                    ->helperText('JPG, PNG, WebP, atau PDF, maksimal 5 MB.')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->maxSize(5120)
                    // Disimpan sendiri di action: foldernya bergantung pada baris yang dipilih.
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data, array $arguments) {
                abort_unless(static::canAccess(), 403);

                $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                [$type, $id] = explode(':', (string) ($arguments['target'] ?? ''), 2) + [null, null];

                $saved = match ($type) {
                    'expense'   => $this->uploadExpenseFile((int) $id, $file),
                    'realisasi' => $this->uploadRealisasiFile((int) $id, $file),
                    default     => abort(404),
                };

                if ($saved) {
                    Notification::make()->success()->title('Faktur tersimpan')->body($arguments['label'] ?? null)->send();
                }
            });
    }

    private function uploadExpenseFile(int $id, UploadedFile $file): bool
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status === ExpenseStatus::Ditolak->value) {
            Notification::make()->danger()->title('Expense ini ditolak, fakturnya tidak dikumpulkan.')->send();

            return false;
        }

        // Nota yang masih ada tidak ditimpa; yang tercatat tapi filenya hilang boleh diganti.
        if (filled($expense->attachment) && Storage::disk('public')->exists($expense->attachment)) {
            Notification::make()->warning()->title('Expense ini sudah punya nota.')->body('Ganti notanya dari menu Expense bila memang salah.')->send();

            return false;
        }

        if (! $stored = $this->storeFile($file, 'expense/invoice/' . now()->format('Y/m/d'))) {
            return false;
        }

        $expense->update(['attachment' => $stored['path']]);

        return true;
    }

    private function uploadRealisasiFile(int $id, UploadedFile $file): bool
    {
        $item = RealisasiItem::with('realizasi')->findOrFail($id);

        abort_unless($item->realizasi, 404);

        if (! $stored = $this->storeFile($file, 'realisasi/' . $item->realizasi->advance_id)) {
            return false;
        }

        RealisasiFile::create([
            'realisasi_item_id' => $item->id,
            'original_name'     => Str::limit($file->getClientOriginalName(), 200, ''),
            'filepath'          => $stored['path'],
            'filesize'          => $file->getSize(),
            'mime_type'         => $stored['mime'],
        ]);

        return true;
    }

    /**
     * Simpan ke disk public. File ini bisa dibuka lewat link /storage, jadi
     * jenisnya diperiksa dari isinya dan ekstensinya ditentukan dari situ,
     * bukan dari nama file (skrip bernama nota.jpg tidak boleh tersimpan).
     *
     * @return array{path: string, mime: string}|null
     */
    private function storeFile(UploadedFile $file, string $folder): ?array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) $file->get());

        if (! isset(self::FILE_TYPES[$mime])) {
            Notification::make()->danger()->title('File ini bukan gambar atau PDF.')->body('Upload foto (JPG, PNG, WebP) atau PDF fakturnya.')->send();

            return null;
        }

        return [
            'path' => $file->storeAs($folder, Str::uuid() . '.' . self::FILE_TYPES[$mime], 'public'),
            'mime' => $mime,
        ];
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
