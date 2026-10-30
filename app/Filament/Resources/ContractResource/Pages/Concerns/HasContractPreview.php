<?php

namespace App\Filament\Resources\ContractResource\Pages\Concerns;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Services\ContractTemplateService;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Pratinjau kontrak di samping form Buat/Edit: HTML yang sama dengan PDF,
 * dibangun dari isi form yang belum disimpan. Form-nya live (debounce), jadi
 * pratinjau ikut berubah setiap kali isian berubah. Tidak ada yang disimpan.
 */
trait HasContractPreview
{
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

        // Edit: kolom yang tidak ada di form (mis. penanda tangan agensi) dari kontrak tersimpan.
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
            ['type' => $type, 'sort_order' => $i + 1] + array_map($this->blankToNull(...), Arr::only($row, ['item', 'amount', 'due_date', 'notes'])),
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
