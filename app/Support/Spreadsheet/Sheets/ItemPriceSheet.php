<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Pricing\Requests\ItemPriceRowRequest;
use App\Modules\Pricing\Services\pricingService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The price grid, one cell per row.
 *
 * The rows are the cells the grid draws: an active item in an active category,
 * under an active per-item service. A row with no id is a cell being filled in
 * — keyed on (item, service), so naming a pair that already has a price changes
 * that price rather than refusing it, which is what typing into the grid does.
 *
 * A blank price never clears a cell here. In the grid a blank box deletes the
 * price; in a sheet a blank cell means «not touched», and a spreadsheet that
 * deleted prices would be the one import that removes rows.
 */
class ItemPriceSheet extends Sheet
{
    public function key(): string
    {
        return 'item_price';
    }

    public function title(): string
    {
        return 'prices';
    }

    public function query(): Builder
    {
        return ItemPrice::query()
            ->with(['item:id,name', 'service:id,name'])
            ->whereHas('service', fn (Builder $q) => $q->where('status', 'active')->where('pricing_mode', 'per_item'))
            ->whereHas('item', fn (Builder $q) => $q->where('status', 'active')
                ->whereHas('category', fn (Builder $c) => $c->where('status', 'active')));
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('item_id'),
            Column::readOnly('item', fn (ItemPrice $price) => $price->item ? getLocalizedValueDashboard($price->item, 'name') : null),
            Column::make('service_id'),
            Column::readOnly('service', fn (ItemPrice $price) => $price->service ? getLocalizedValueDashboard($price->service, 'name') : null),
            Column::make('price'),
        ];
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return ItemPriceRowRequest::class;
    }

    /**
     * Through the grid's own save, so the cell is written — and rounded — the
     * way a typed box is.
     */
    public function create(array $validated): void
    {
        app(pricingService::class)->saveGrid([
            (int) $validated['item_id'] => [(int) $validated['service_id'] => $validated['price']],
        ]);
    }

    /**
     * The price, and the item or service when the row names a different one.
     * Moving a price onto a pair that already has one is refused by the
     * database's unique (service, item) index and reported as a failed row.
     */
    public function update(Model $row, array $validated): void
    {
        $data = [];

        foreach (['item_id', 'service_id'] as $key) {
            if (isset($validated[$key])) {
                $data[$key] = (int) $validated[$key];
            }
        }

        if (isset($validated['price'])) {
            $data['price'] = round((float) $validated['price'], 2);
        }

        if ($data !== []) {
            $row->update($data);
        }
    }

    /**
     * The grid adds a price by filling a blank box, which is `item_price.update`
     * — there is no create route — so adding by import answers to the same.
     */
    public function createPermission(): string
    {
        return 'item_price.update';
    }
}
