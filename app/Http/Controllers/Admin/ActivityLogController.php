<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\ActivityPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * «سجل النشاط» — who changed what, across the platform.
 *
 * Read only: there is no route that edits or deletes a row, because a log that
 * can be changed is a log nobody can trust. Retention is `laundo:prune`'s.
 * Gated on `activity_log.view`, the platform's — a laundry sees its own orders'
 * history on the order screen, not this.
 */
class ActivityLogController extends Controller
{
    public function __construct(private readonly ActivityPresenter $presenter) {}

    public function index(Request $request)
    {
        $logs = $this->query($request->query())->paginate(30);

        $view = view('admin.activity_log.index', [
            'logs' => $logs,
            'rows' => $this->presenter->present($logs->items()),
            'filters' => $this->filters($request->query()),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $logs = $this->query($request->query())->paginate(30);

        return response()->json([
            'table' => view('admin.activity_log.partials._activity_log_table_body', [
                'rows' => $this->presenter->present($logs->items()),
            ])->render(),
            'pagination' => $logs->withQueryString()->links()->toHtml(),
        ]);
    }

    /**
     * The same narrowing the export uses — see ActivityLogSheet.
     *
     * @param  array<string, mixed>  $input
     * @return Builder<ActivityLog>
     */
    public static function query(array $input): Builder
    {
        $filters = self::filters($input);

        // A kind of record no longer recorded is not shown either — rows
        // written before it joined the list (the permissions a deploy seeds)
        // would otherwise bury the changes somebody actually made.
        $excluded = array_map(
            fn (string $class) => (new $class)->getMorphClass(),
            (array) config('activity.excluded', [])
        );

        return ActivityLog::query()
            ->where(fn (Builder $q) => $q->whereNull('subject_type')->orWhereNotIn('subject_type', $excluded))
            ->when($filters['query'], fn (Builder $q, string $term) => $q->search($term, ['actor_name', 'subject_label', 'route', 'subject_type']))
            ->when($filters['source'], fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['event'], fn (Builder $q, string $event) => $q->where('event', $event))
            ->when($filters['from'], fn (Builder $q, string $from) => $q->where('created_at', '>=', Carbon::parse($from, displayTimezone())->startOfDay()->utc()))
            ->when($filters['to'], fn (Builder $q, string $to) => $q->where('created_at', '<=', Carbon::parse($to, displayTimezone())->endOfDay()->utc()))
            ->latest('id');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{query: ?string, source: ?string, event: ?string, from: ?string, to: ?string}
     */
    public static function filters(array $input): array
    {
        $date = function ($value): ?string {
            if (empty($value)) {
                return null;
            }

            try {
                return Carbon::parse((string) $value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };

        $source = (string) ($input['source'] ?? '');
        $event = (string) ($input['event'] ?? '');
        $term = trim((string) ($input['query'] ?? ''));

        return [
            'query' => $term !== '' ? $term : null,
            'source' => in_array($source, [ActivityLog::DASHBOARD, ActivityLog::API, ActivityLog::SITE, ActivityLog::SYSTEM], true) ? $source : null,
            'event' => in_array($event, ['created', 'updated', 'deleted', 'login', 'logout'], true) ? $event : null,
            'from' => $date($input['from'] ?? null),
            'to' => $date($input['to'] ?? null),
        ];
    }
}
