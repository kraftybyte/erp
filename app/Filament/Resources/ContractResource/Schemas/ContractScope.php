<?php

namespace App\Filament\Resources\ContractResource\Schemas;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Services\ContractTemplateService;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;

/**
 * Bagian Ruang Lingkup Pekerjaan di form kontrak (Buat dan Edit): kategori
 * dengan catatan (mis. "Akun: Instagram @..."), sub-item, dan pilihan
 * menampilkannya sebagai halaman Lampiran sendiri (format PKS) dengan judul,
 * pembuka, judul kolom, dan penutup yang bisa diubah.
 */
final class ContractScope
{
    private const TOOLBAR = ['bold', 'italic', 'underline', 'orderedList', 'bulletList', 'undo', 'redo'];

    /** @param  array|null  $existing  kategori tersimpan (halaman Edit) untuk mengisi repeater */
    public static function section(?array $existing = null): Section
    {
        $categories = Repeater::make('sow_categories')
            ->label('Kategori Ruang Lingkup')
            ->defaultItems(0)
            ->collapsible()
            ->addActionLabel('Tambah kategori')
            ->schema([
                TextInput::make('item')
                    ->label('Nama Kategori')
                    ->placeholder('Contoh: Pengelolaan Media Sosial, Pembaruan Website')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label('Catatan kategori')
                    ->placeholder('Contoh: Akun: Instagram @namaakun; TikTok @namaakun')
                    ->helperText('Tampil di bawah nama kategori.')
                    ->rows(1)
                    ->columnSpanFull(),
                Repeater::make('children')
                    ->label('Sub-Item')
                    ->defaultItems(0)
                    ->collapsible()
                    ->addActionLabel('Tambah sub-item')
                    ->schema([
                        TextInput::make('item')
                            ->label('Layanan')
                            ->placeholder('Contoh: 15 Feed Image/Carousel')
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Rincian')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ])
            ->columns(1);

        if ($existing !== null) {
            $categories->afterStateHydrated(function (Repeater $component, $state) use ($existing): void {
                if (empty($state) && $existing !== []) {
                    $component->state($existing);
                }
            });
        }

        return Section::make('Ruang Lingkup Pekerjaan')
            ->icon('heroicon-o-sparkles')
            ->description('Kategori dan sub-item. Tampil sebagai Lampiran 1 tersendiri, atau sebagai tabel di pasal yang memuat {tabel_ruang_lingkup}.')
            ->schema([
                $categories,
                Toggle::make('layout.scope_appendix')
                    ->label('Tampilkan sebagai halaman Lampiran 1 (format PKS)')
                    ->live(),
                Fieldset::make('Lampiran ruang lingkup')
                    ->columns(1)
                    ->visible(fn (Get $get): bool => (bool) $get('layout.scope_appendix'))
                    ->schema([
                        TextInput::make('layout.scope_title')
                            ->label('Judul lampiran')
                            ->placeholder(ContractTemplateService::LAYOUT_DEFAULTS['scope_title'])
                            ->maxLength(255),
                        RichEditor::make('layout.scope_intro')
                            ->label('Pembuka (di bawah judul)')
                            ->toolbarButtons(self::TOOLBAR),
                        Fieldset::make('Judul kolom')
                            ->columns(['default' => 3])
                            ->schema(collect(ContractTemplateService::LAYOUT_DEFAULTS['scope_columns'])
                                ->map(fn (string $label, string $key) => TextInput::make("layout.scope_columns.{$key}")
                                    ->label("Kolom {$label}")
                                    ->placeholder($label)
                                    ->maxLength(60))
                                ->values()
                                ->all()),
                        RichEditor::make('layout.scope_outro')
                            ->label('Penutup (mis. pengecualian)')
                            ->toolbarButtons(self::TOOLBAR),
                    ]),
            ]);
    }

    /** Kategori tersimpan sebagai isian repeater (halaman Edit). */
    public static function existing(Contract $contract): array
    {
        return $contract->sowCategories->map(fn (ContractItem $category) => [
            'item'        => $category->item,
            'description' => $category->description ?? '',
            'children'    => $category->children->map(fn (ContractItem $child) => [
                'item'        => $child->item,
                'description' => $child->description ?? '',
            ])->values()->all(),
        ])->values()->all();
    }

    /** Simpan kategori dan sub-item dari form (baris sow lama sudah dihapus pemanggil bila perlu). */
    public static function save(Contract $contract, array $categories): void
    {
        $sortOrder = 0;

        foreach ($categories as $categoryData) {
            $sortOrder++;

            if (empty($categoryData['item'])) {
                continue;
            }

            $category = ContractItem::create([
                'contract_id'        => $contract->id,
                'item'               => $categoryData['item'],
                'description'        => filled($categoryData['description'] ?? null) ? $categoryData['description'] : null,
                'type'               => 'sow',
                'sort_order'         => $sortOrder,
                'is_category_header' => true,
            ]);

            $childSortOrder = 0;

            foreach (is_array($categoryData['children'] ?? null) ? $categoryData['children'] : [] as $childData) {
                $childSortOrder++;

                if (empty($childData['item'])) {
                    continue;
                }

                ContractItem::create([
                    'contract_id'        => $contract->id,
                    'parent_id'          => $category->id,
                    'item'               => $childData['item'],
                    'description'        => $childData['description'] ?? '',
                    'type'               => 'sow',
                    'sort_order'         => $childSortOrder,
                    'is_category_header' => false,
                ]);
            }
        }
    }
}
