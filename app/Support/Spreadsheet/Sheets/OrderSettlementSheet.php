<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Models\OrderSettlementLine;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «تسويات الطلبات» — how each order was divided, export only.
 *
 * Read through the tenant-scoped `OrderSettlement`, so a laundry owner exports
 * its own settlements and never another laundry's — the same rows the screen
 * shows it. Every stored figure is exported as it was stored: a settled row is
 * frozen, and its measurements are the record.
 */
class OrderSettlementSheet extends Sheet
{
    public function key(): string
    {
        return 'order_settlement';
    }

    public function title(): string
    {
        return 'order-settlements';
    }

    public function query(): Builder
    {
        return OrderSettlement::query()->with(['order:id,code,status', 'laundry:id,name', 'lines']);
    }

    public function searchColumns(): array
    {
        // SettlementController::search().
        return ['order.code', 'laundry.name'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        $status = $filters['status'] ?? null;

        if (in_array($status, [OrderSettlement::PENDING, OrderSettlement::SETTLED, OrderSettlement::CANCELLED], true)) {
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
            Column::readOnly('order_code', fn (OrderSettlement $row) => $row->order?->code),
            Column::readOnly('order_status', fn (OrderSettlement $row) => $row->order?->status),
            Column::make('laundry_id'),
            Column::readOnly('laundry', fn (OrderSettlement $row) => $row->laundry ? getLocalizedValueDashboard($row->laundry, 'name') : null),
            Column::make('basis'),
            Column::make('commission_rate'),
            Column::make('laundry_share_rate'),
            Column::make('commission_amount'),
            Column::make('laundry_amount'),
            Column::make('discount_amount'),
            Column::make('laundry_discount_amount'),
            Column::make('platform_fee_amount'),
            Column::make('tax_amount'),
            // The charges behind the figures, as the list names them under the
            // commission — each line's name and its amount.
            Column::readOnly('lines', fn (OrderSettlement $row) => $row->lines
                ->map(fn (OrderSettlementLine $line) => getLocalizedValueDashboard($line, 'name').': '.$line->amount)
                ->implode(' | ') ?: null),
            Column::make('status'),
            Column::make('settled_at'),
        ];
    }
}
