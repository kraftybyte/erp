<?php

namespace App\Filament\Resources\ContractResource\Pages\Concerns;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Services\ContractDefaultBody;
use App\Services\ContractFormats;
use App\Services\ContractTemplateService;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Set;
use Illuminate\Support\Str;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Pratinjau kontrak di samping form Buat/Edit: HTML yang sama dengan PDF,
 * dibangun dari isi form yang belum disimpan. Form-nya live (debounce), jadi
 * pratinjau ikut berubah setiap kali isian berubah. Tidak ada yang disimpan.
 */
trait HasContractPreview
{
    /**
     * Isi kontrak (judul, pembukaan, pasal, penutup) dan kop/kaki suratnya.
     * Default-nya teks template bawaan; pasal bisa ditambah, dihapus, diurutkan.
     */
    protected static function contractBodySections(): array
    {
        // Kontrak baru: isi dari format bawaan di Settings → Contract Template.
        $default = fn (string $key) => fn () => ContractFormats::forNewContract()[$key];
        $toolbar = ['bold', 'italic', 'underline', 'strike', 'orderedList', 'bulletList', 'h3', 'blockquote', 'undo', 'redo'];

        $placeholders = collect(ContractDefaultBody::PLACEHOLDERS)
            ->map(fn (string $label, string $key) => '<div><code>{' . e($key) . '}</code> ' . e($label) . '</div>')
            ->implode('');

        return [
            Section::make('Isi Kontrak')
                ->icon('heroicon-o-pencil-square')
                ->description('Semua teks kontrak bisa diubah. Penanda seperti {nama_klien} diisi otomatis dari data di form.')
                ->collapsible()
                ->schema([
                    TextInput::make('document_title')
                        ->hintAction(static::applyFormatAction())
                        ->label('Judul dokumen')
                        ->default($default('document_title'))
                        ->maxLength(255),
                    TextInput::make('layout.number_line')
                        ->label('Baris nomor di bawah judul')
                        ->placeholder(ContractTemplateService::LAYOUT_DEFAULTS['number_line'])
                        ->helperText('Mis. "Nomor: {nomor_kontrak}".')
                        ->maxLength(255),
                    RichEditor::make('opening_text')
                        ->label('Pembukaan dan para pihak')
                        ->toolbarButtons($toolbar)
                        ->default($default('opening_text')),
                    Repeater::make('articles')
                        ->label('Pasal')
                        ->schema([
                            TextInput::make('title')
                                ->label('Judul pasal')
                                ->maxLength(255),
                            RichEditor::make('content')
                                ->label('Isi pasal')
                                ->toolbarButtons($toolbar),
                        ])
                        ->default($default('articles'))
                        // Nomor pasal mengikuti urutan, sama dengan di dokumen.
                        ->itemLabel(function (array $state, string $uuid, Repeater $component): string {
                            $number = array_search($uuid, array_keys($component->getState() ?? []), true) + 1;

                            return 'Pasal ' . $number . (filled($state['title'] ?? null) ? ' · ' . $state['title'] : '');
                        })
                        ->collapsible()
                        ->collapsed()
                        ->addActionLabel('Tambah pasal'),
                    RichEditor::make('closing_text')
                        ->label('Penutup (di atas tanda tangan)')
                        ->toolbarButtons($toolbar)
                        ->default($default('closing_text')),
                    Fieldset::make('Kolom tanda tangan')
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextInput::make('layout.signature_first_label')
                                ->label('Label Pihak Pertama')
                                ->placeholder(ContractTemplateService::LAYOUT_DEFAULTS['signature_first_label'])
                                ->maxLength(60),
                            TextInput::make('layout.signature_second_label')
                                ->label('Label Pihak Kedua')
                                ->placeholder(ContractTemplateService::LAYOUT_DEFAULTS['signature_second_label'])
                                ->maxLength(60),
                            Toggle::make('layout.signature_show_title')
                                ->label('Nama tebal dan jabatan di bawah tanda tangan')
                                ->inline(false),
                        ]),
                    Placeholder::make('placeholder_list')
                        ->label('Penanda isian otomatis')
                        ->content(new HtmlString('<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); gap: .25rem 1rem; font-size: .8125rem;">' . $placeholders . '</div>')),
                ]),

            Section::make('Kop & Kaki Surat')
                ->icon('heroicon-o-photo')
                ->description('Kosongkan untuk memakai kop dan kaki surat dari Pengaturan → Contract Template.')
                ->collapsed()
                ->columns(2)
                ->schema([
                    FileUpload::make('header_image')
                        ->label('Kop surat (header)')
                        ->image()
                        ->directory('contracts/letterhead'),
                    FileUpload::make('footer_image')
                        ->label('Kaki surat (footer)')
                        ->image()
                        ->directory('contracts/letterhead'),
                ]),
        ];
    }

    /**
     * Wakil perusahaan kita di pembukaan dan kolom tanda tangan. Kontrak baru
     * langsung diisi bawaan dari Pengaturan, jadi mengganti bawaan nanti tidak
     * mengubah kontrak yang sudah dibuat; dikosongkan berarti memakai bawaan.
     */
    protected static function agencySignatorySection(bool $prefill): Section
    {
        $default = ContractTemplateService::defaultAgencySignatory();

        return Section::make('Penanda Tangan Pihak Pertama')
            ->icon('heroicon-o-pencil-square')
            ->description('Wakil perusahaan kita di kontrak. Kosongkan untuk memakai bawaan dari Pengaturan → Contract Template.')
            ->collapsed()
            ->columns(2)
            ->schema([
                TextInput::make('agency_signatory_name')
                    ->label('Nama Penanda Tangan')
                    ->default($prefill ? $default['name'] : null)
                    ->placeholder($default['name'] ?? 'mis. Budi Santoso')
                    ->maxLength(255),
                TextInput::make('agency_signatory_title')
                    ->label('Jabatan Penanda Tangan')
                    ->default($prefill ? $default['title'] : null)
                    ->placeholder($default['title'])
                    ->maxLength(255),
            ]);
    }

    /**
     * Ganti judul, pembukaan, pasal, penutup, tampilan, dan teks lampiran
     * dengan sebuah format (ContractFormats). Data client, nilai, dan baris
     * ruang lingkup/biaya tidak berubah.
     */
    protected static function applyFormatAction(): Action
    {
        return Action::make('applyContractFormat')
            ->label('Pakai format')
            ->icon('heroicon-m-document-text')
            ->modalHeading('Pakai format kontrak')
            ->modalDescription('Judul, pembukaan, pasal, penutup, kolom tanda tangan, dan teks lampiran diganti dengan format yang dipilih. Data client, nilai, ruang lingkup, dan baris biaya tidak berubah.')
            ->modalSubmitActionLabel('Pakai')
            ->form([
                Select::make('format')
                    ->label('Format')
                    ->options(ContractFormats::OPTIONS)
                    ->required(),
            ])
            ->action(function (array $data, Set $set, $livewire): void {
                $record = $livewire->record ?? null;

                foreach (ContractFormats::make($data['format'], $record instanceof Contract ? $record : null) as $key => $value) {
                    // Repeater pasal memakai kunci unik per baris.
                    $set($key, $key === 'articles'
                        ? collect($value)->mapWithKeys(fn (array $article) => [(string) Str::uuid() => $article])->all()
                        : $value);
                }
            });
    }

    /** Kota tempat perjanjian dibuat ({kota}); kosong = Settings → Contract Template. */
    protected static function signingCityInput(): TextInput
    {
        $default = (string) (app(\App\Settings\GeneralSettings::class)->contract_city ?? '');

        return TextInput::make('signing_city')
            ->label('Kota penandatanganan')
            ->placeholder($default !== '' ? $default : 'mis. Tangerang')
            ->helperText('Dipakai penanda {kota}. Kosong = kota di Settings → Contract Template.')
            ->maxLength(255);
    }

    /** Dasar kewenangan Pihak Kedua ({dasar_hukum_klien}). */
    protected static function clientLegalBasisInput(): Textarea
    {
        return Textarea::make('client_legal_basis')
            ->label('Dasar kewenangan client')
            ->placeholder('mis. Akta Pendirian No. ... dan SK Kemenkumham No. ...')
            ->helperText('Dipakai penanda {dasar_hukum_klien} di pembukaan kontrak.')
            ->rows(2);
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    public function hasContractPreview(): bool
    {
        // Kontrak berupa file upload tidak punya teks template untuk ditampilkan.
        return ($this->record?->type ?? null) !== 'file';
    }

    public function contractPreviewHtml(): ?string
    {
        try {
            return app(ContractTemplateService::class)->preview($this->previewContract($this->data ?? []));
        } catch (\Throwable $e) {
            // Isian setengah jadi (mis. tanggal tidak valid) tidak boleh merusak form-nya.
            report($e);

            return null;
        }
    }

    protected function previewContract(array $data): Contract
    {
        $contract = new Contract();
        $fillable = $contract->getFillable();

        // Upload kop/kaki surat yang belum disimpan tampil lewat URL sementaranya.
        foreach (['header_image', 'footer_image'] as $key) {
            $file = is_array($data[$key] ?? null) ? Arr::first($data[$key]) : ($data[$key] ?? null);

            $data[$key] = match (true) {
                $file instanceof TemporaryUploadedFile => rescue(fn () => $file->temporaryUrl(), null, false),
                is_string($file)                       => $file,
                default                                => null,
            };
        }

        // Edit: kolom yang tidak ada di form diambil dari kontrak tersimpan.
        $attributes = array_merge(
            $this->record instanceof Contract ? Arr::only($this->record->getAttributes(), $fillable) : [],
            Arr::only(array_map($this->blankToNull(...), $data), $fillable),
        );

        foreach ($contract->getCasts() as $key => $cast) {
            if (preg_match('/^(decimal|int|float)/', $cast) && isset($attributes[$key]) && ! is_numeric($attributes[$key])) {
                $attributes[$key] = null;
            }
        }

        $contract->forceFill($attributes);

        // Sama dengan yang disimpan saveSowCategories(): kategori dan sub-item tanpa nama dilewati.
        $categories = $this->filledRows($data['sow_categories'] ?? [])->map(fn (array $category, int $i) => $this->previewItem([
            'item'               => $category['item'],
            'type'               => 'sow',
            'description'        => $category['description'] ?? null,
            'is_category_header' => true,
            'sort_order'         => $i + 1,
        ])->setRelation('children', $this->filledRows($category['children'] ?? [])->map(fn (array $child, int $j) => $this->previewItem([
            'item'        => $child['item'],
            'description' => $child['description'] ?? '',
            'type'        => 'sow',
            'sort_order'  => $j + 1,
        ]))));

        return $contract
            ->setRelation('sowCategories', $categories)
            ->setRelation('sowItems', collect())
            ->setRelation('feeItems', $this->previewItems($data['fee_items'] ?? [], 'fee'))
            ->setRelation('thirdPartyItems', $this->previewItems($data['third_party_items'] ?? [], 'third_party'));
    }

    private function previewItems(array $rows, string $type): Collection
    {
        return collect($rows)->values()->map(fn (array $row, int $i) => $this->previewItem(
            ['type' => $type, 'sort_order' => $i + 1] + array_map($this->blankToNull(...), Arr::only($row, ['item', 'estimate', 'amount', 'due_date', 'notes'])),
        ));
    }

    private function previewItem(array $attributes): ContractItem
    {
        if (isset($attributes['amount']) && ! is_numeric($attributes['amount'])) {
            $attributes['amount'] = null;
        }

        return (new ContractItem())->forceFill($attributes);
    }

    private function filledRows(array $rows): Collection
    {
        return collect($rows)->filter(fn ($row) => is_array($row) && filled($row['item'] ?? null))->values();
    }

    private function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
