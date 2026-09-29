<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Offer\Models\Offer;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Offers — export only.
 */
class OfferSheet extends Sheet
{
    public function key(): string
    {
        return 'offer';
    }

    public function title(): string
    {
        return 'offers';
    }

    public function query(): Builder
    {
        // The coupon eagerly: the badge and the code both come off it.
        return Offer::query()->with('coupon');
    }

    public function searchColumns(): array
    {
        return ['title', 'description', 'coupon.code'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('title')->translatable(),
            Column::make('description')->translatable(),
            Column::make('coupon_id'),
            Column::readOnly('coupon_code', fn (Offer $offer) => $offer->coupon?->code),
            // The badge as the app draws it — empty when the coupon would be refused.
            Column::readOnly('badge', fn (Offer $offer) => $offer->badge()),
            Column::make('target_type'),
            Column::make('target_value'),
            Column::make('starts_at'),
            Column::make('ends_at'),
            Column::make('sort_order'),
            Column::make('status'),
        ];
    }
}
