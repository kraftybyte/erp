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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Eager load all relationships for edit
        $this->record->load([
            'feeItems',
            'thirdPartyItems',
            'sowCategories' => function ($query) {
                $query->with('children')->orderBy('sort_order');
            },
            'deal',
        ]);

        return $data;
    }

    protected function mutateRelationshipDataBeforeSaveUsing(array $data): array
    {
        // Handle hierarchical SOW items (from repeater)
        if (isset($data['_sow_categories'])) {
            $categories = $data['_sow_categories'];
            unset($data['_sow_categories']);

            // Store for afterSave hook
            $this->sowCategoriesData = $categories;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Save hierarchical SOW items after contract is updated
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

            // Create category header
            $category = ContractItem::create([
                'contract_id' => $contract->id,
                'item' => $categoryData['item'] ?? 'Layanan',
                'type' => 'sow',
                'sort_order' => $sortOrder,
                'is_category_header' => true,
            ]);

            // Create children
            if (!empty($categoryData['children'])) {
                $childSortOrder = 0;
                foreach ($categoryData['children'] as $childData) {
                    $childSortOrder++;
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
