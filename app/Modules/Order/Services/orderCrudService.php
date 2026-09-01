<?php

namespace App\Modules\Order\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Item\Models\Item;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\User\Models\User;

/**
 * The dashboard's view of orders.
 *
 * Read-heavy on purpose. An order is created by a customer, moved by drivers and
 * priced by the laundry — the dashboard's job in P6 is to *see* orders and to
 * assign the ones nothing covered. Piece review and final pricing are P7; the
 * transport legs are P8.
 *
 * There is no addNew() and no deleteRecord(), and that is deliberate: an order
 * placed by a customer is a record of an agreement, not a row an operator
 * invents or erases. Cancellation goes through the state machine, which leaves a
 * trace.
 */
class orderCrudService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderService $orderService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shredData($id = null): array
    {
        // A `?status=` in the URL filters the first render too, not only the AJAX
        // refresh. Without this a deep link to «الطلبات المتأخرة» silently shows
        // everything, which is worse than showing nothing.
        $status = request()->get('status');

        $data = [
            'orders' => $status
                ? $this->orders->search(null, $status)
                : $this->orders->getAllPaginated(),
            'activeStatus' => $status,
            'statuses' => OrderStatus::cases(),
            'counts' => $this->orders->counts(),
        ];

        if ($id) {
            $row = $this->orders->findById($id);
            $data['row'] = $row;

            // Only offered while the order can still be moved, and only for the
            // laundries that actually cover it — an operator should not be able to
            // hand an order to a laundry that does not serve the zone.
            $data['assignable'] = $row->status->isInCustody()
                ? []
                : $this->assignableFor($row);

            // Only built when the form will actually render — it costs a query
            // over the whole price matrix for the service.
            $data['reviewItems'] = $row->status->isReviewable()
                ? $this->reviewItems($row)
                : [];

            // Who could take each unfinished leg. Computed per task because
            // eligibility is per zone and per city, and the four legs do not all
            // happen in the same place.
            $data['taskCandidates'] = $this->taskCandidates($row);
        }

        return $data;
    }

    public function search($query, $perPage = 15)
    {
        return $this->orders->search($query, request()->get('status'), $perPage);
    }

    /**
     * The laundries an operator may hand this order to.
     *
     * @return array<int, Laundry>
     */
    public function assignableFor(Order $order): array
    {
        if (! $order->pickupAddress || ! $order->service) {
            return [];
        }

        return app(LaundryAssigner::class)->candidates($order->pickupAddress, $order->service);
    }

    public function assign(int|string $id, int $laundryId, ?User $actor = null): Order
    {
        return $this->orderService->assignLaundry($this->orders->findById($id), $laundryId, $actor);
    }

    /**
     * Eligible drivers for each unfinished leg.
     *
     * @return array<int, array<int, Driver>>
     */
    private function taskCandidates(Order $order): array
    {
        $dispatcher = app(DriverDispatcher::class);
        $out = [];

        foreach ($order->tasks as $task) {
            if (! $task->status->isFinished()) {
                $out[$task->id] = $dispatcher->candidates($task);
            }
        }

        return $out;
    }

    /**
     * The rows of the review form.
     *
     * **Every** piece the service is priced for, not just the ones the customer
     * listed — «تم العثور على قطعة إضافية أثناء المراجعة» is the case this screen
     * exists for, and a form that can only subtract cannot record it.
     *
     * Pre-filled from the previous count so the job is correcting a list rather
     * than building one: the customer's own basket on a first review, and the
     * last review's numbers on a re-count.
     *
     * @return array<int, array{item: Item, price: float|null, estimated_qty: int, final_qty: int}>
     */
    private function reviewItems(Order $order): array
    {
        // «تنظيف جاف» has no matrix by design, so there is nothing to filter the
        // catalogue by: every active piece is offered and the laundry prices the
        // ones that actually arrived. For a catalogued service the matrix is both
        // the price list and the list of pieces the service handles at all.
        $quoted = $order->service && ! $order->service->isPerItem();

        $prices = $quoted
            ? collect()
            : ItemPrice::where('service_id', $order->service_id)->pluck('price', 'item_id');

        if (! $quoted && $prices->isEmpty()) {
            return [];
        }

        $estimated = $order->items->where('phase', 'estimated')->pluck('qty', 'item_id');
        $final = $order->items->where('phase', 'final')->pluck('qty', 'item_id');

        $items = Item::query()
            ->when(! $quoted, fn ($q) => $q->whereIn('id', $prices->keys()))
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        $rows = [];

        foreach ($items as $item) {
            $estimatedQty = (int) ($estimated[$item->id] ?? 0);

            $rows[] = [
                'item' => $item,
                // Null, not zero. Zero is a price somebody chose; null is the
                // empty box the laundry has to fill in.
                'price' => $quoted
                    ? ($final->has($item->id) ? (float) $order->items
                        ->where('phase', 'final')
                        ->firstWhere('item_id', $item->id)?->unit_price : null)
                    : (float) $prices[$item->id],
                'estimated_qty' => $estimatedQty,
                'final_qty' => (int) ($final[$item->id] ?? $estimatedQty),
            ];
        }

        // The pieces the customer actually asked for come first; the rest are
        // there to be found, not to be scrolled past.
        usort($rows, fn ($a, $b) => ($b['estimated_qty'] <=> $a['estimated_qty'])
            ?: ($a['item']->sort_order <=> $b['item']->sort_order));

        return $rows;
    }
}
