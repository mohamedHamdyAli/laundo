<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Repositories\OrderRepository;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «الطلبات» — the orders list, export only.
 *
 * Read through the tenant-scoped `Order`, so a laundry owner exports its own
 * orders and nobody else's, exactly as the list shows them. The search and the
 * status filter mirror `OrderRepository::search()`, including the four queue
 * filters that are not order statuses.
 */
class OrderSheet extends Sheet
{
    public function key(): string
    {
        return 'order';
    }

    public function title(): string
    {
        return 'orders';
    }

    public function query(): Builder
    {
        // The list's own eager loads (OrderRepository::EAGER).
        return Order::query()->with([
            'customer:id,name,phone', 'laundry:id,name', 'service:id,name,pricing_mode', 'pickupAddress',
        ]);
    }

    public function searchColumns(): array
    {
        // What OrderRepository::search() reaches: the code, the customer's name
        // and phone, and the service and laundry named in the SERVICE column.
        return ['code', 'customer.name', 'customer.phone', 'service.name', 'laundry.name'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        $status = is_string($filters['status'] ?? null) ? $filters['status'] : '';

        if ($status === '') {
            return $query;
        }

        // The same match as OrderRepository::search(): two of the queue's
        // filters are task states and one is a null laundry, not statuses.
        return match ($status) {
            OrderRepository::NEEDS_DRIVER => $query->whereIn($query->qualifyColumn('id'), OrderTask::queued()->select('order_id')),
            OrderRepository::NEEDS_LAUNDRY => $query->scopes(['unassigned', 'active']),
            OrderRepository::NEEDS_RESCUE => $query->whereIn($query->qualifyColumn('id'), OrderTask::where('status', 'failed')
                ->where('attempts', '>=', OrderTask::MAX_ATTEMPTS)
                ->select('order_id')),
            OrderRepository::NEEDS_PRICE_ANSWER => $query->whereIn($query->qualifyColumn('id'), OrderPriceQuery::open()->select('order_id')),
            OrderRepository::PIECE_MISMATCH => $query->scopes(['withOpenPieceCheck']),
            default => $query->where($query->qualifyColumn('status'), $status),
        };
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('code'),
            Column::make('created_at'),
            Column::make('status'),
            Column::make('user_id'),
            Column::readOnly('customer', fn (Order $order) => $order->customer?->name),
            Column::readOnly('customer_phone', fn (Order $order) => $order->customer?->phone),
            Column::make('service_id'),
            Column::readOnly('service', fn (Order $order) => $order->service ? getLocalizedValueDashboard($order->service, 'name') : null),
            Column::make('laundry_id'),
            Column::readOnly('laundry', fn (Order $order) => $order->laundry ? getLocalizedValueDashboard($order->laundry, 'name') : null),
            Column::readOnly('pickup_zone_id', fn (Order $order) => $order->pickupAddress?->zone_id),
            Column::make('pickup_date')->value(fn (Order $order) => $order->pickup_date?->format('Y-m-d')),
            Column::make('delivery_date')->value(fn (Order $order) => $order->delivery_date?->format('Y-m-d')),
            Column::make('payment_method'),
            Column::make('payment_status'),
            Column::make('paid_at'),
            Column::make('coupon_code'),
            Column::make('offer_id'),
            Column::make('estimated_items_count'),
            Column::make('final_items_count'),
            Column::make('estimated_subtotal'),
            Column::make('final_subtotal'),
            Column::make('delivery_fee'),
            Column::make('discount_total'),
            Column::make('cash_surcharge'),
            Column::make('platform_fee'),
            Column::make('tax_rate'),
            Column::make('estimated_tax'),
            Column::make('final_tax'),
            Column::make('estimated_total'),
            Column::make('final_total'),
            // The figure the list's Total column shows: final once counted.
            Column::readOnly('payable_total', fn (Order $order) => $order->payableTotal()),
            Column::make('confirmed_at'),
        ];
    }
}
