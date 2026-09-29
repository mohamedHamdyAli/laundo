<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Item\Models\Item;
use App\Modules\Item\Requests\ItemRequest;
use App\Modules\Item\Services\itemCrudService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The image is left out: a file cannot travel in a cell, and the form does not
 * require one.
 */
class ItemSheet extends Sheet
{
    public function key(): string
    {
        return 'item';
    }

    public function title(): string
    {
        return 'items';
    }

    public function query(): Builder
    {
        return Item::query()->with('category:id,name');
    }

    public function searchColumns(): array
    {
        return ['name', 'category.name', 'sort_order'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name')->translatable(),
            Column::make('item_category_id'),
            Column::readOnly('item_category', fn (Item $item) => $item->category ? getLocalizedValueDashboard($item->category, 'name') : null),
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
        return ItemRequest::class;
    }

    public function create(array $validated): void
    {
        app(itemCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        app(itemCrudService::class)->updateRecord($validated + ['id' => $row->getKey()]);
    }
}
