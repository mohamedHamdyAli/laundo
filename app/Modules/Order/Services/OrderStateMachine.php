<?php

namespace App\Modules\Order\Services;

use App\Modules\Notification\Services\OrderNotifier;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderStatusLog;
use App\Modules\Payment\Services\EarningService;
use App\Modules\Payment\Services\SettlementService;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The only way an order's status changes.
 *
 * Nothing else in the codebase should write `$order->status` — do it here and the
 * transition is validated against OrderStatus::allowedNext() and recorded in
 * order_status_logs in the same transaction. Skip it and you get an order that
 * jumped from awaiting_pickup to delivered with no trace of how.
 */
class OrderStateMachine
{
    /**
     * Move an order forward.
     *
     * @param  'customer'|'driver'|'laundry'|'admin'|'system'  $actorType
     *
     * @throws RuntimeException when the move is not allowed from the current state
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        string $actorType = 'system',
        ?User $actor = null,
        ?string $note = null,
    ): Order {
        $from = $order->status;

        if ($from === $to) {
            // Idempotent rather than fatal: a driver tapping "picked up" twice, or
            // a webhook arriving twice, should not be an error.
            return $order;
        }

        if (! $from->canTransitionTo($to)) {
            throw new RuntimeException(
                "Cannot move order {$order->code} from {$from->value} to {$to->value}."
            );
        }

        return DB::transaction(function () use ($order, $from, $to, $actorType, $actor, $note) {
            $order->status = $to;
            $order->save();

            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_id' => $actor?->id,
                'note' => $note,
            ]);

            $this->settleMoney($order, $to);
            $this->announce($order, $to);

            return $order->refresh();
        });
    }

    /**
     * Close a delivered order once its money has arrived.
     *
     * **Delivered is the clothes; Completed is the payment.** `RatingService`
     * already states the distinction — «an unpaid cash order can sit at
     * delivered for days» — and nothing acted on it: both payment paths set
     * `payment_status = 'paid'` and stopped, so `Completed` was unreachable and
     * `settleMoney()` never ran. Nineteen orders on this install, one
     * settlement, and not one driver earning released.
     *
     * Two events have to land and either may be last — the driver hands over a
     * card-paid order, or collects the cash at the door of one already
     * delivered — so this is called from both and does nothing until both are
     * true. Idempotent by the status check: a replayed webhook or a second leg
     * completion finds the order already `Completed` and returns.
     *
     * It deliberately does not force the transition. If an order reaches
     * `delivered` without being paid it stays there, visible as an unpaid
     * delivered order, which is a thing somebody should chase rather than a
     * thing to tidy away.
     */
    public function completeIfPaid(Order $order, string $actorType = 'system', ?User $actor = null): Order
    {
        if ($order->status !== OrderStatus::Delivered || $order->payment_status !== 'paid') {
            return $order;
        }

        return $this->transition($order, OrderStatus::Completed, $actorType, $actor, __('Paid in full'));
    }

    /**
     * Record the order's starting state.
     *
     * A separate method because there is no `from`, and forcing the caller through
     * transition() would mean inventing a fake previous status.
     */
    public function open(Order $order, string $actorType = 'customer', ?User $actor = null): void
    {
        OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => $order->status->value,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
        ]);
    }

    /**
     * Tell whoever is waiting on this stage that it happened.
     *
     * Here rather than at the eight call sites that move an order, because this
     * is the only place that sees every move. A notification wired per-caller is
     * a notification somebody forgets on the ninth caller.
     *
     * Deliberately never throws: a notification that fails must not roll back the
     * status change it was describing. The dispatcher swallows its own errors,
     * and this is the belt to that pair of braces.
     */
    private function announce(Order $order, OrderStatus $to): void
    {
        try {
            $notifier = app(OrderNotifier::class);

            match ($to) {
                OrderStatus::DriverOnWay => $notifier->driverOnWay($order),
                // The one the order actually stops for.
                OrderStatus::Reviewed => $notifier->finalPriceReady($order),
                OrderStatus::Confirmed => $notifier->priceConfirmed($order),
                OrderStatus::ReadyForDelivery => $notifier->readyForDelivery($order),
                OrderStatus::Delivered => $notifier->delivered($order),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('[notifications] announce failed', [
                'order' => $order->id,
                'to' => $to->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * What the status change owes people.
     *
     * Three parties are paid out of one order — the driver a share of the
     * delivery, the laundry its share of the work, the platform its commission —
     * and all three move on the same two moments: everything becomes real when
     * the order completes, and evaporates if it never does.
     *
     * Here rather than in a listener because it is a consequence of the status
     * change itself, and the two belong in the same transaction: an order that
     * completed without paying what it owed would be a silent debt.
     *
     * Written as statements rather than a `match`, unlike the dispatch above it:
     * each branch has two side effects and no value, which is the one shape
     * `match` cannot say plainly.
     */
    private function settleMoney(Order $order, OrderStatus $to): void
    {
        $earnings = app(EarningService::class);
        $settlements = app(SettlementService::class);

        // The price is agreed, so what each side is owed can be worked out and
        // shown before anybody waits for the clothes to arrive. Nothing moves.
        if ($to === OrderStatus::Confirmed) {
            $settlements->recordFor($order);

            return;
        }

        if ($to === OrderStatus::Completed) {
            $earnings->releaseFor($order);
            $settlements->settleFor($order);

            return;
        }

        if ($to === OrderStatus::Cancelled || $to === OrderStatus::Returned) {
            $earnings->cancelFor($order);
            $settlements->cancelFor($order);
        }
    }

    /**
     * Record something that happened to an order without moving it.
     *
     * An assignment, a reassignment, an operator's remark. These belong in the
     * same audit trail as the transitions — «who gave this order to that laundry»
     * is exactly the sort of question the log exists to answer — but they are not
     * status changes, so they must not go through transition().
     */
    public function note(Order $order, string $note, string $actorType = 'admin', ?User $actor = null): void
    {
        OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => $order->status->value,
            'to_status' => $order->status->value,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            'note' => $note,
        ]);
    }

    /**
     * Whether the move is legal, without attempting it — for gating a button or an
     * endpoint before the write.
     */
    public function can(Order $order, OrderStatus $to): bool
    {
        return $order->status->canTransitionTo($to);
    }
}
