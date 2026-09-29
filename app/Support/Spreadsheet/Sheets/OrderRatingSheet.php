<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Order\Models\OrderRating;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «التقييمات» — what customers thought, export only.
 *
 * The screen is `admin.rating.*` and the permission `order_rating`, which is the
 * key here. Read through the tenant-scoped `OrderRating`, so a laundry owner
 * exports its own verdicts and nobody else's.
 */
class OrderRatingSheet extends Sheet
{
    public function key(): string
    {
        return 'order_rating';
    }

    public function title(): string
    {
        return 'order-ratings';
    }

    public function query(): Builder
    {
        return OrderRating::query()->with(['customer:id,name,phone', 'order:id,code', 'laundry:id,name']);
    }

    public function searchColumns(): array
    {
        // RatingController::search().
        return ['comment', 'order.code', 'customer.name', 'customer.phone', 'laundry.name'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        // RatingController::listing()'s bands.
        match ($filters['band'] ?? null) {
            'poor' => $query->scopes('poor'),
            'good' => $query->where($query->qualifyColumn('overall'), '>=', 4),
            'commented' => $query->whereNotNull($query->qualifyColumn('comment')),
            default => null,
        };

        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('created_at'),
            Column::make('order_id'),
            Column::readOnly('order_code', fn (OrderRating $rating) => $rating->order?->code),
            Column::make('user_id'),
            Column::readOnly('customer', fn (OrderRating $rating) => $rating->customer?->name),
            Column::readOnly('customer_phone', fn (OrderRating $rating) => $rating->customer?->phone),
            Column::make('laundry_id'),
            Column::readOnly('laundry', fn (OrderRating $rating) => $rating->laundry ? getLocalizedValueDashboard($rating->laundry, 'name') : null),
            Column::make('overall'),
            Column::make('service_quality'),
            Column::make('delivery'),
            Column::make('timing'),
            Column::make('tags')->value(fn (OrderRating $rating) => $rating->tags ? implode(', ', $rating->tags) : null),
            Column::make('comment'),
        ];
    }
}
