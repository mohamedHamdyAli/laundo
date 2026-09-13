<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;

/**
 * «تتبّع الطلب» — the customer's timeline.
 *
 * Split out of `OrderStatus::trackingSteps()`, which it used to share. That list
 * is **also the landing page's journey section** — six steps of marketing
 * narrative — and the two audiences want different granularity. A visitor being
 * sold the service does not need «the driver is on the way»; a customer standing
 * at home waiting for their clothes needs nothing else.
 *
 * Sharing one list meant the tracking screen could only ever be as detailed as
 * the marketing page, and the cost was real: between «جاهز للتسليم» and
 * «تم التسليم» the screen did not change for the entire journey home. The
 * customer whose clothes were in a van saw exactly what they had seen an hour
 * earlier.
 *
 * **Two of the eight steps are not order statuses.** Six come off
 * `order_status_logs`, which is the record of what actually happened — reading
 * the current status instead would show a step as unreached the moment an order
 * moved past it. The other two are derived from the journeys:
 *
 *   - «السائق في الطريق إليك» is the `driver_on_way` status, which the order has
 *     always recorded and the timeline never showed.
 *   - «هدومك في الطريق إليك» has no status at all. Collecting from the laundry
 *     does not move the order — deliberately, because the order's state is about
 *     the clothes and not about which van they are in — so it is read from the
 *     leg itself.
 *
 * Adding a status for that second one was the alternative, and it was rejected:
 * a new `OrderStatus` widens the transition table, every screen that switches on
 * status, and the dashboard's filters, to describe something no rule anywhere
 * depends on. A timeline entry is a presentation concern and belongs here.
 */
class OrderTimeline
{
    /**
     * The key for the step that has no status behind it.
     *
     * Deliberately not a value in `OrderStatus`: a client mapping this back to
     * the enum should fail loudly rather than half-match. It is a timeline key,
     * and the timeline is the only thing that speaks it.
     */
    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    /**
     * The eight steps, in order, each with whether it has happened and when.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for(Order $order): array
    {
        $order->loadMissing(['statusLogs', 'tasks']);

        $steps = [];

        foreach ($this->plan() as $key) {
            $steps[] = $key === self::OUT_FOR_DELIVERY
                ? $this->outForDelivery($order)
                : $this->fromStatus($order, $key);
        }

        return $steps;
    }

    /**
     * The order of the steps.
     *
     * `driver_on_way` leads, and `out_for_delivery` sits between «ready» and
     * «delivered» — the hour that used to be blank.
     *
     * @return array<int, OrderStatus|string>
     */
    private function plan(): array
    {
        return [
            OrderStatus::DriverOnWay,
            OrderStatus::PickedUp,
            OrderStatus::Reviewed,
            OrderStatus::Confirmed,
            OrderStatus::Cleaning,
            OrderStatus::ReadyForDelivery,
            self::OUT_FOR_DELIVERY,
            OrderStatus::Delivered,
        ];
    }

    /**
     * A step backed by a real status.
     *
     * Read from the log rather than from `$order->status`, so a step stays
     * reached once it has been reached. `ReviewDisputed` is deliberately absent
     * from the plan: it is a detour, not a milestone, and drawing it would make
     * the line grow longer the worse things went.
     *
     * @return array<string, mixed>
     */
    private function fromStatus(Order $order, OrderStatus $status): array
    {
        $log = $order->statusLogs->firstWhere('to_status', $status->value);

        return [
            'status' => $status->value,
            'label' => __($status->label()),
            'reached' => $log !== null,
            'at' => $log ? humanDate($log->created_at) : null,
        ];
    }

    /**
     * «هدومك في الطريق إليك» — derived from the third leg, not from a status.
     *
     * Reached when the driver has actually collected from the laundry. Assigning
     * the leg is not enough: both return journeys are assigned together the
     * moment the laundry marks an order ready, so «assigned» would light this up
     * while the clothes were still on a shelf.
     *
     * @return array<string, mixed>
     */
    private function outForDelivery(Order $order): array
    {
        $collected = $order->tasks
            ->firstWhere(fn ($task) => $task->type === TaskType::CollectFromLaundry
                && $task->status === TaskStatus::Completed);

        return [
            'status' => self::OUT_FOR_DELIVERY,
            'label' => __('On the way to you'),
            'reached' => $collected !== null,
            'at' => $collected?->completed_at ? humanDate($collected->completed_at) : null,
        ];
    }
}
