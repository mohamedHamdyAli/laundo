<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Payment\Models\Refund;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «طلبات الاسترداد» — the refund queue, export only.
 *
 * Not tenant-scoped, like the screen. With no status at all it holds what the
 * screen opens on — the requests still under review.
 */
class RefundSheet extends Sheet
{
    public function key(): string
    {
        return 'refund';
    }

    public function title(): string
    {
        return 'refunds';
    }

    public function query(): Builder
    {
        return Refund::query()->with(['customer:id,name,phone', 'order:id,code,payment_method', 'reviewer:id,name']);
    }

    public function searchColumns(): array
    {
        // RefundController::search().
        return ['reason', 'note', 'order.code', 'customer.name', 'customer.phone', 'reviewer.name'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        // RefundController::index(): no status at all opens on `pending`; an
        // empty one or `all` is every row.
        $status = array_key_exists('status', $filters) ? $filters['status'] : Refund::PENDING;

        if (is_string($status) && $status !== '' && $status !== 'all') {
            $query->where($query->qualifyColumn('status'), $status);
        }

        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('created_at'),
            Column::make('order_id'),
            Column::readOnly('order_code', fn (Refund $refund) => $refund->order?->code),
            Column::readOnly('order_payment_method', fn (Refund $refund) => $refund->order?->payment_method),
            Column::make('user_id'),
            Column::readOnly('customer', fn (Refund $refund) => $refund->customer?->name),
            Column::readOnly('customer_phone', fn (Refund $refund) => $refund->customer?->phone),
            Column::make('payment_id'),
            Column::make('amount'),
            Column::make('reason'),
            Column::make('note'),
            Column::make('status'),
            Column::make('destination'),
            Column::make('reviewed_by'),
            Column::readOnly('reviewer', fn (Refund $refund) => $refund->reviewer?->name),
            Column::make('reviewed_at'),
            Column::make('review_note'),
            Column::make('settled_at'),
        ];
    }
}
