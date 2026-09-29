<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\ItemCategory\Requests\ItemCategoryRequest;
use App\Modules\ItemCategory\Services\itemCategoryCrudService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The image is left out: a file cannot travel in a cell, and the form does not
 * require one.
 */
class ItemCategorySheet extends Sheet
{
    public function key(): string
    {
        return 'item_category';
    }

    public function title(): string
    {
        return 'item-categories';
    }

    public function query(): Builder
    {
        return ItemCategory::query();
    }

    public function searchColumns(): array
    {
        return ['name', 'sort_order'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name')->translatable(),
            Column::make('sort_order'),
            Column::make('status'),
        ];
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return ItemCategoryRequest::class;
    }

    public function create(array $validated): void
    {
        app(itemCategoryCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        app(itemCategoryCrudService::class)->updateRecord($validated + ['id' => $row->getKey()]);
    }
}
