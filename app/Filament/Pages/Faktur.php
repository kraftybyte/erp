<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AdvanceResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\InvoiceResource;
use App\Helpers\Money;
use App\Http\Controllers\FakturController;
use App\Models\Expense;
use App\Models\Invoice;
use App\Services\FakturCollector;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use App\Services\FakturMatcher;
use App\Services\ReportPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Pengepulan faktur sebulan: Keluaran (nomor faktur pajak invoice ber-PPN,
 * bisa diisi langsung di sini) dan Masukan (nota Expense dan struk Realisasi,
 * yang belum ada bisa di-upload langsung di sini). Tombol Upload faktur
 * menerima beberapa file sekaligus dan mencarikan pasangannya (FakturMatcher).
 */
class Faktur extends Page
{
    // Akses: izin Shield page_Faktur (Roles → Pages).
    use HasPageShield;

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
                    ->acceptedFileTypes(array_keys(FakturMatcher::FILE_TYPES))
                    ->maxSize(5120)
                    // Disimpan sendiri: foldernya bergantung pada baris yang dipilih.
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data, array $arguments) {
                abort_unless(static::canAccess(), 403);

                $file   = is_array($data['file']) ? reset($data['file']) : $data['file'];
                $result = app(FakturMatcher::class)->attach((string) ($arguments['target'] ?? ''), (string) $file->get(), $file->getClientOriginalName());

                $result['ok']
                    ? Notification::make()->success()->title('Faktur tersimpan')->body($arguments['label'] ?? null)->send()
                    : Notification::make()->danger()->title($result['message'])->send();
            });
    }

    /**
     * Beberapa file faktur sekaligus; tiap file dibaca lalu dicarikan
     * pasangannya, dan user memastikannya sebelum disimpan.
     */
    protected function uploadFakturAction(): Action
    {
        return Action::make('uploadFaktur')
            ->label('Upload faktur')
            ->icon('heroicon-o-document-arrow-up')
            ->color('gray')
            ->modalHeading('Upload faktur')
            ->modalDescription('Faktur pajak keluaran (PDF Coretax) dipasangkan ke invoice penjualan; faktur pembelian, nota, dan struk ke expense atau realisasi yang belum ada fakturnya.')
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->modalSubmitActionLabel('Simpan')
            ->steps([
                Step::make('File')
                    ->schema([
                        FileUpload::make('files')
                            ->label('File faktur')
                            ->helperText('Sampai ' . FakturMatcher::MAX_FILES . ' file sekaligus. JPG, PNG, WebP, atau PDF, maksimal 5 MB per file.')
                            ->multiple()
                            ->maxFiles(FakturMatcher::MAX_FILES)
                            ->acceptedFileTypes(array_keys(FakturMatcher::FILE_TYPES))
                            ->maxSize(5120)
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->afterValidation(fn (Get $get, Set $set) => $set('rows', $this->matchUploads((array) $get('files')))),

                Step::make('Cocokkan')
                    ->schema([
                        Placeholder::make('hint')
                            ->hiddenLabel()
                            ->content('Pasangan yang nominalnya cocok sudah dipilihkan. Periksa, ganti bila keliru, atau kosongkan untuk melewati file.'),
                        Repeater::make('rows')
                            ->hiddenLabel()
                            ->addable(false)
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state) => $state['name'] ?? null)
                            ->schema([
                                Hidden::make('path'),
                                Hidden::make('name'),
                                Hidden::make('nomor'),
                                Hidden::make('doc'),
                                Hidden::make('matched'),
                                Hidden::make('options'),
                                Placeholder::make('detail')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get) => $this->describe($get('doc'), (int) $get('matched'))),
                                Select::make('target')
                                    ->label('Pasangkan ke')
                                    ->options(fn (Get $get) => $get('options') ?: [])
                                    ->searchable()
                                    ->placeholder('Lewati, jangan disimpan'),
                            ]),
                    ]),
            ])
            ->action(function (array $data) {
                abort_unless(static::canAccess(), 403);

                $matcher = app(FakturMatcher::class);
                $saved   = 0;
                $skipped = 0;
                $notes   = [];

                foreach ($data['rows'] ?? [] as $row) {
                    if (! filled($row['target'] ?? null)) {
                        $matcher->forget($row['path'] ?? null);
                        $skipped++;

                        continue;
                    }

                    $result = $matcher->attachKept((string) $row['target'], (string) ($row['path'] ?? ''), (string) ($row['name'] ?? 'faktur'), $row['nomor'] ?? null);

                    $result['ok'] ? $saved++ : null;

                    if (! $result['ok'] || ($result['note'] ?? null)) {
                        $notes[] = ($row['name'] ?? 'File') . ': ' . ($result['note'] ?? $result['message']);
                    }
                }

                Notification::make()
                    ->title("{$saved} faktur tersimpan" . ($skipped ? ", {$skipped} dilewati" : ''))
                    ->body($notes ? implode("\n", $notes) : null)
                    ->{$notes ? 'warning' : 'success'}()
                    ->persistent($notes !== [])
                    ->send();
            });
    }

    /**
     * Baca dan cocokkan tiap file. Pilihan manual: yang belum ada fakturnya
     * dari sebulan sebelum sampai sebulan sesudah bulan yang dibuka.
     *
     * @return array<string, array<string, mixed>>
     */
    private function matchUploads(array $files): array
    {
        set_time_limit(300);

        $matcher = app(FakturMatcher::class);
        $month   = Carbon::createFromFormat('!Y-m', $this->bulan);
        $manual  = $matcher->openTargets(ReportPeriod::between($month->copy()->subMonthNoOverflow()->startOfMonth(), $month->copy()->addMonthNoOverflow()->endOfMonth()));
        $rows    = [];

        foreach (array_slice(array_filter($files, fn ($file) => $file instanceof TemporaryUploadedFile), 0, FakturMatcher::MAX_FILES) as $file) {
            $data = (string) $file->get();
            $name = Str::limit($file->getClientOriginalName(), 120, '');

            if (! $kept = $matcher->keep($data)) {
                Notification::make()->warning()->title("{$name} bukan gambar atau PDF, dilewati.")->send();

                continue;
            }

            $doc   = $matcher->read($data, $kept['mime']);
            $match = $doc ? $matcher->candidates($doc) : [];

            $rows[(string) Str::uuid()] = [
                'path'    => $kept['path'],
                'name'    => $name,
                'nomor'   => $doc['nomor'] ?? null,
                'doc'     => $doc,
                'matched' => count($match),
                'options' => array_filter(['Paling cocok' => $match] + array_map(fn (array $group) => array_diff_key($group, $match), $manual)),
                'target'  => array_key_first($match),
            ];
        }

        if (! $rows) {
            throw new Halt();
        }

        return $rows;
    }

    private function describe(mixed $doc, int $matched): HtmlString
    {
        if (! is_array($doc)) {
            return new HtmlString('Isi file tidak terbaca otomatis (Gemini tidak aktif atau saldonya habis). Pilih pasangannya sendiri.');
        }

        $date  = $doc['tanggal'] ? Carbon::parse($doc['tanggal'])->locale('id')->translatedFormat('j M Y') : null;
        $lines = [
            implode(' · ', array_filter([$doc['faktur_pajak'] ? 'Faktur pajak' : 'Faktur / nota pembelian', $doc['nomor'] ? 'No. ' . e($doc['nomor']) : null, $date])),
            implode(' · ', array_filter([$doc['penjual'] ? 'Penjual: ' . e($doc['penjual']) : null, $doc['pembeli'] ? 'Pembeli: ' . e($doc['pembeli']) : null])),
            implode(' · ', array_filter([
                $doc['dpp'] ? 'DPP ' . Money::rupiah($doc['dpp']) : null,
                $doc['ppn'] ? 'PPN ' . Money::rupiah($doc['ppn']) : null,
                $doc['total'] ? 'Total ' . Money::rupiah($doc['total']) : null,
            ])),
            $matched ? null : '<strong>Tidak ada yang nominalnya cocok.</strong> Pilih sendiri atau lewati.',
        ];

        return new HtmlString(implode('<br>', array_filter($lines)));
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
            $this->uploadFakturAction(),
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
