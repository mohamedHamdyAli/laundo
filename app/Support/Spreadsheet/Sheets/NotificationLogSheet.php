<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Notification\Models\NotificationLog;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * The notification log — export only; a record of what was sent is not
 * something to type in.
 *
 * `destination` is already masked when the dispatcher writes it, so no device
 * token reaches the file.
 */
class NotificationLogSheet extends Sheet
{
    public function key(): string
    {
        return 'notification_log';
    }

    public function title(): string
    {
        return 'notification-log';
    }

    public function query(): Builder
    {
        return NotificationLog::query()->with(['recipient:id,name,phone', 'author:id,name']);
    }

    public function searchColumns(): array
    {
        return [
            'title', 'body',
            'destination', 'channel', 'failure_reason',
            'recipient.name', 'recipient.phone',
            'author.name',
        ];
    }

    /**
     * The term, then the event and status dropdowns — AND'ed after the grouped
     * search, exactly as `NotificationLogController::filtered()` does.
     */
    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        $event = is_string($filters['event'] ?? null) ? $filters['event'] : '';
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : '';

        return $query
            ->when($event !== '', fn (Builder $q) => $q->where('event', $event))
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status));
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('created_at'),
            Column::make('user_id'),
            Column::readOnly('recipient', fn (NotificationLog $log) => $log->recipient?->name),
            Column::readOnly('recipient_phone', fn (NotificationLog $log) => $log->recipient?->phone),
            Column::make('event'),
            Column::make('channel'),
            Column::make('destination'),
            Column::make('status'),
            Column::make('title'),
            Column::make('body'),
            Column::make('failure_reason'),
            Column::make('sent_by'),
            Column::readOnly('author', fn (NotificationLog $log) => $log->author?->name),
        ];
    }
}
