<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\Payment;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «المدفوعات» — every payment, export only. A payment typed into a spreadsheet
 * is a ledger entry nobody made.
 *
 * Not tenant-scoped, like the screen: the permission is the protection. The
 * gateway payload is left out — it is the provider's raw answer, not a figure.
 */
class PaymentSheet extends Sheet
{
    public function key(): string
    {
        return 'payment';
    }

    public function title(): string
    {
        return 'payments';
    }

    public function query(): Builder
    {
        return Payment::query()->with(['order:id,code', 'customer:id,name,phone']);
    }

    public function searchColumns(): array
    {
        // PaymentLedgerController::searchPayments().
        return ['provider_reference', 'provider', 'failure_reason', 'order.code', 'customer.name', 'customer.phone'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        // «all» or anything that is not a status leaves the list whole.
        $status = $filters['status'] ?? null;

        if (in_array($status, array_column(PaymentStatus::cases(), 'value'), true)) {
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
            Column::readOnly('order_code', fn (Payment $payment) => $payment->order?->code),
            Column::make('user_id'),
            Column::readOnly('customer', fn (Payment $payment) => $payment->customer?->name),
            Column::readOnly('customer_phone', fn (Payment $payment) => $payment->customer?->phone),
            Column::make('provider'),
            Column::make('method'),
            Column::make('provider_reference'),
            Column::make('amount'),
            Column::make('currency'),
            Column::make('status'),
            Column::make('authorised_at'),
            Column::make('captured_at'),
            Column::make('failed_at'),
            Column::make('failure_reason'),
        ];
    }
}
