<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Http\Controllers\Admin\ActivityLogController;
use App\Models\ActivityLog;
use App\Services\ActivityPresenter;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * The activity log, as a sheet — worded the way the screen words it, so the
 * file somebody hands the owner reads like the page they saw. Export only: a
 * log that could be imported would be a log anybody could write.
 */
class ActivityLogSheet extends Sheet
{
    private ?ActivityPresenter $presenter = null;

    /** @var array<string, mixed>|null the row being written, presented once */
    private ?array $current = null;

    public function key(): string
    {
        return 'activity_log';
    }

    public function title(): string
    {
        return 'activity-log';
    }

    public function query(): Builder
    {
        return ActivityLog::query();
    }

    public function filter(Builder $query, array $filters): Builder
    {
        // The screen's own narrowing, minus its ordering: the exporter walks
        // the rows by id.
        return ActivityLogController::query($filters)->reorder();
    }

    public function columns(): array
    {
        return [
            Column::readOnly('date', fn (ActivityLog $log) => humanDate($log->created_at, 'Y-m-d h:i A')),
            Column::readOnly('what_happened', fn (ActivityLog $log) => $this->presented($log)['title']),
            Column::readOnly('done_by', fn (ActivityLog $log) => $this->presented($log)['actor']),
            Column::readOnly('role', fn (ActivityLog $log) => $this->presented($log)['role']),
            Column::readOnly('where_from', fn (ActivityLog $log) => $this->presented($log)['where']),
            Column::make('order_id'),
            Column::readOnly('details', fn (ActivityLog $log) => collect($this->presented($log)['fields'])
                ->map(fn (array $f) => $log->event === 'updated'
                    ? __(':field: was :old, became :new', ['field' => $f['label'], 'old' => $f['old'] ?: '—', 'new' => $f['new']])
                    : $f['label'].': '.($log->event === 'deleted' ? $f['old'] : $f['new']))
                ->implode("\n") ?: null),
            Column::make('ip'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presented(ActivityLog $log): array
    {
        if (($this->current['log'] ?? null) !== $log) {
            $this->presenter ??= app(ActivityPresenter::class);
            $this->current = $this->presenter->present([$log])[0];
        }

        return $this->current;
    }
}
