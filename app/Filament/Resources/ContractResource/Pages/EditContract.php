<?php

namespace App\Filament\Resources\ContractResource\Pages;

use App\Filament\Resources\ContractResource;
use App\Models\ContractItem;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    /** @var array|null */
    protected ?array $sowCategoriesData = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function fillFormWithDataAndRelationships(): void
    {
        // Load relationships before filling form
        $this->record->load([
            'feeItems',
            'thirdPartyItems',
            'sowCategories' => function ($query) {
                $query->with('children')->orderBy('sort_order');
            },
            'deal',
        ]);

        parent::fillFormWithDataAndRelationships();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Extract SOW categories before record save
        if (isset($data['sow_categories'])) {
            $this->sowCategoriesData = $data['sow_categories'];
            unset($data['sow_categories']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Save SOW categories after contract is updated
        if (!empty($this->sowCategoriesData)) {
            // Delete existing SOW items for this contract
            ContractItem::where('contract_id', $this->record->id)
                ->where('type', 'sow')
                ->delete();

            // Re-save all
            $this->saveSowCategories($this->record, $this->sowCategoriesData);
            unset($this->sowCategoriesData);
        }
    }

    private function saveSowCategories(Model $contract, array $categories): void
    {
        $sortOrder = 0;
        foreach ($categories as $categoryData) {
            $sortOrder++;

            // Skip if no item name
            if (empty($categoryData['item'])) {
                continue;
            }

            // Create category header
            $category = ContractItem::create([
                'contract_id' => $contract->id,
                'item' => $categoryData['item'] ?? 'Layanan',
                'type' => 'sow',
                'sort_order' => $sortOrder,
                'is_category_header' => true,
            ]);

            // Create children
            if (!empty($categoryData['children']) && is_array($categoryData['children'])) {
                $childSortOrder = 0;
                foreach ($categoryData['children'] as $childData) {
                    $childSortOrder++;

                    // Skip if no item name
                    if (empty($childData['item'])) {
                        continue;
                    }

                    ContractItem::create([
                        'contract_id' => $contract->id,
                        'parent_id' => $category->id,
                        'item' => $childData['item'] ?? '',
                        'description' => $childData['description'] ?? '',
                        'type' => 'sow',
                        'sort_order' => $childSortOrder,
                        'is_category_header' => false,
                    ]);
                }
            }
        }
    }
}
