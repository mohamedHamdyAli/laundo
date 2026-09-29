<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Order\Models\OrderRecurrence;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «الاشتراكات» — the repeat schedules, export only.
 *
 * The screen is `admin.recurrence.*` and the permission `order_recurrence`,
 * which is the key here. Not tenant-scoped, like the screen: a schedule belongs
 * to a customer and names no laundry. The prompt counts are the list's own —
 * how often it asked, how often anybody answered, how often it became an order.
 */
class OrderRecurrenceSheet extends Sheet
{
    public function key(): string
    {
        return 'order_recurrence';
    }

    public function title(): string
    {
        return 'order-recurrences';
    }

    public function query(): Builder
    {
        return OrderRecurrence::query()
            ->with(['customer:id,name,phone', 'service:id,name', 'timeSlot'])
            ->withCount([
                'prompts',
                'prompts as answered_prompts_count' => fn ($q) => $q->whereNotNull('answer'),
                'prompts as confirmed_prompts_count' => fn ($q) => $q->where('answer', 'confirmed'),
            ]);
    }

    public function searchColumns(): array
    {
        // RecurrenceController::search().
        return ['frequency', 'customer.name', 'customer.phone', 'service.name'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        // RecurrenceController::listing(): anything but `all` is a status.
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : 'all';

        if ($status !== 'all') {
            $query->where($query->qualifyColumn('status'), $status);
        }

        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('created_at'),
            Column::make('user_id'),
            Column::readOnly('customer', fn (OrderRecurrence $row) => $row->customer?->name),
            Column::readOnly('customer_phone', fn (OrderRecurrence $row) => $row->customer?->phone),
            Column::make('service_id'),
            Column::readOnly('service', fn (OrderRecurrence $row) => $row->service ? getLocalizedValueDashboard($row->service, 'name') : null),
            Column::make('frequency'),
            Column::make('day_of_week'),
            Column::make('time_slot_id'),
            Column::readOnly('time_slot', fn (OrderRecurrence $row) => $row->timeSlot?->label()),
            Column::make('status'),
            Column::make('next_prompt_on')->value(fn (OrderRecurrence $row) => $row->next_prompt_on?->format('Y-m-d')),
            Column::readOnly('prompts_count', fn (OrderRecurrence $row) => (int) $row->getAttribute('prompts_count')),
            Column::readOnly('answered_prompts_count', fn (OrderRecurrence $row) => (int) $row->getAttribute('answered_prompts_count')),
            Column::readOnly('confirmed_prompts_count', fn (OrderRecurrence $row) => (int) $row->getAttribute('confirmed_prompts_count')),
        ];
    }
}
