<?php

namespace App\Modules\Order\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\Service\Models\Service;
use App\Support\LaundryContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «طلبات اليوم» — one day's work: which orders, how many pieces in each, and how
 * many of each piece across all of them.
 *
 * The totals are the point of the screen. A laundry planning its machines does
 * not want three orders, it wants «20 بنطلون، 10 قمصان» — and split by service,
 * because a pair of trousers to wash and iron and a pair to iron only are two
 * different jobs on the floor.
 *
 * **Which count.** Once the laundry has counted the pieces the order carries a
 * final set, and that is the truth; until then the customer's own estimate is
 * all there is, and the screen says so beside it rather than presenting a guess
 * as a count.
 *
 * @phpstan-type Row array{order: Order, counted: bool, pieces: Collection<int, array{item_id: int, item: string, qty: int}>, total: int}
 */
class OrderTodayService
{
    public const SCOPES = ['in_laundry', 'delivery_today', 'pickup_today'];

    public function __construct(private readonly OrderRepository $orders) {}

    /**
     * The universal view-data assembler, with the filters it was built from.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function shredData(array $input = []): array
    {
        $filters = $this->filters($input);
        $orders = $this->orders->todayBoard($filters);

        $rows = $orders->map(fn (Order $order) => $this->row($order));

        return [
            'rows' => $rows,
            'summary' => $this->summary($rows),
            'filters' => $filters,
            'scopes' => self::SCOPES,
            'statuses' => OrderStatus::cases(),
            'services' => Service::where('status', 'active')->orderBy('sort_order')->get(['id', 'name']),
            // Only for somebody who sees more than one laundry. A laundry's own
            // people would be offered a list of one — or, worse, the names of
            // every other laundry on the platform.
            'laundries' => LaundryContext::currentId() === null
                ? Laundry::withoutGlobalScopes()->orderBy('id')->get(['id', 'name'])
                : collect(),
        ];
    }

    /**
     * The request, made safe to query with.
     *
     * An unknown scope is the default rather than an error, and a laundry filter
     * from somebody inside a tenant is dropped: the tenant scope would confine
     * them anyway, but a filter they cannot meaningfully use has no business
     * reaching the query.
     *
     * @param  array<string, mixed>  $input
     * @return array{scope: string, date: string, status: ?string, service_id: ?int, laundry_id: ?int, query: ?string}
     */
    public function filters(array $input): array
    {
        $scope = in_array($input['scope'] ?? null, self::SCOPES, true) ? $input['scope'] : 'in_laundry';

        // «Today» in the business's own calendar. The chosen pickup and delivery
        // days are plain dates the customer picked, so they are compared as
        // dates — only the meaning of «today» needs the display timezone.
        $today = Carbon::now(displayTimezone())->toDateString();
        $date = $today;

        if (! empty($input['date'])) {
            try {
                $date = Carbon::parse((string) $input['date'])->toDateString();
            } catch (\Throwable) {
                $date = $today;
            }
        }

        $status = OrderStatus::tryFrom((string) ($input['status'] ?? ''))?->value;
        $query = trim((string) ($input['query'] ?? ''));

        return [
            'scope' => $scope,
            'date' => $date,
            'status' => $status,
            'service_id' => ! empty($input['service_id']) ? (int) $input['service_id'] : null,
            'laundry_id' => LaundryContext::currentId() === null && ! empty($input['laundry_id'])
                ? (int) $input['laundry_id']
                : null,
            'query' => $query !== '' ? $query : null,
        ];
    }

    /**
     * One order, with its pieces counted the way the screen shows them.
     *
     * @return Row
     */
    private function row(Order $order): array
    {
        $counted = $order->hasFinalPrice();
        $phase = $counted ? 'final' : 'estimated';

        $pieces = $order->items
            ->where('phase', $phase)
            ->groupBy('item_id')
            ->map(fn (Collection $lines, $itemId) => [
                'item_id' => (int) $itemId,
                'item' => $lines->first()->item ? getLocalizedValueDashboard($lines->first()->item, 'name') : '—',
                'qty' => (int) $lines->sum('qty'),
            ])
            ->sortByDesc('qty')
            ->values();

        return [
            'order' => $order,
            'counted' => $counted,
            'pieces' => $pieces,
            'total' => (int) $pieces->sum('qty'),
        ];
    }

    /**
     * Every piece across the rows, by service and then by item, plus the same
     * by item alone.
     *
     * @param  Collection<int, Row>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $byService = [];
        $byItem = [];

        foreach ($rows as $row) {
            $order = $row['order'];
            $serviceKey = $order->service_id ?? 0;
            $serviceName = $order->service ? getLocalizedValueDashboard($order->service, 'name') : '—';

            $byService[$serviceKey] ??= ['service' => $serviceName, 'items' => [], 'total' => 0];

            foreach ($row['pieces'] as $piece) {
                $byService[$serviceKey]['items'][$piece['item_id']] ??= ['item' => $piece['item'], 'qty' => 0];
                $byService[$serviceKey]['items'][$piece['item_id']]['qty'] += $piece['qty'];
                $byService[$serviceKey]['total'] += $piece['qty'];

                $byItem[$piece['item_id']] ??= ['item' => $piece['item'], 'qty' => 0];
                $byItem[$piece['item_id']]['qty'] += $piece['qty'];
            }
        }

        $sort = fn (array $items) => collect($items)->sortByDesc('qty')->values()->all();

        // The same numbers as one table — a row per item, a column per service
        // and a total — because that is how the question is asked on the floor:
        // «كام بنطلون، كام قميص». Items the most of first.
        $columns = collect($byService)->map(fn (array $service) => $service['service'])->all();
        $table = collect($byItem)
            ->map(fn (array $item, $itemId) => [
                'item' => $item['item'],
                'total' => $item['qty'],
                'by_service' => collect($byService)
                    ->map(fn (array $service) => $service['items'][$itemId]['qty'] ?? 0)
                    ->all(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'orders' => $rows->count(),
            'pieces' => (int) $rows->sum('total'),
            // Orders whose pieces are still the customer's estimate. Said on the
            // card, so a total built partly on guesses does not read as a count.
            'estimated' => $rows->where('counted', false)->count(),
            'by_service' => collect($byService)
                ->map(fn (array $service) => ['items' => $sort($service['items'])] + $service)
                ->sortByDesc('total')
                ->values()
                ->all(),
            'by_item' => $sort($byItem),
            'service_columns' => $columns,
            // In the same key order as the columns, so the foot of the table
            // lines up under them — `by_service` above is sorted by size.
            'service_totals' => collect($byService)->map(fn (array $service) => $service['total'])->all(),
            'items_table' => $table,
        ];
    }
}
