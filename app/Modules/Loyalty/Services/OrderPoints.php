<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Notification\Services\PointsNotifier;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Where an order and a customer's points meet.
 *
 * Five moments, each called from the one place that order event happens:
 *
 *   - **checkout** (`OrderService::place()`) — the points spent are taken in the
 *     order's own transaction, and the order is refused if they are no longer
 *     there (two checkouts at once);
 *   - **the review** (`OrderReviewService`) — fewer pieces counted than the
 *     points paid for gives the difference back. Never grown: the customer
 *     agreed to spend at most what they spent;
 *   - **completion** (`OrderStateMachine::settleMoney()`) — the order's points
 *     are paid, once;
 *   - **cancelled or returned** (the same) — every point spent comes back;
 *   - **a doorstep leg** (`TaskService::complete()`) — the hangers handed back.
 *
 * **The discount is the platform's, all of it** — the owner's decision. The
 * laundry's share is worked out as if there were no points
 * (`Order::cleaningRevenue()`, `SettlementService::recordFor()`).
 */
class OrderPoints
{
    public function __construct(
        private readonly PointsService $points,
        private readonly PointsPolicy $policy,
    ) {}

    /**
     * What completing an order placed now will earn, to be stamped on it.
     *
     * Nothing for a basket under `Points_Min_Order_To_Earn`: a customer shown
     * «+10» at checkout must not find at completion that the order never
     * qualified. `$washing` is null for a service priced after inspection —
     * there is nothing to measure yet, so the minimum is checked when the order
     * completes, on the pieces as counted (`earn()`), as it is for every order.
     */
    public function toEarn(?float $washing = null): int
    {
        if (! $this->policy->enabled()) {
            return 0;
        }

        $minimum = $this->policy->minOrderToEarn();

        if ($washing !== null && $minimum > 0 && $washing + 0.001 < $minimum) {
            return 0;
        }

        return $this->policy->perOrder();
    }

    /**
     * What this order will still pay when it completes — the stamped promise,
     * unless the pieces as counted have fallen under the stamped minimum. The
     * same test `earn()` applies, so no screen promises what completion will
     * refuse; the stamp itself is kept, so a re-count that finds the pieces
     * after all brings the promise back.
     */
    public function promised(Order $order): int
    {
        $points = (int) $order->points_to_earn;
        $minimum = (float) (((array) ($order->points_terms ?? []))['earn_min'] ?? 0);
        $washed = (float) ($order->hasFinalPrice() ? $order->final_subtotal : $order->estimated_subtotal);

        if ($points < 1 || ($minimum > 0 && $washed + 0.001 < $minimum)) {
            return 0;
        }

        return $points;
    }

    /**
     * The terms to stamp on an order placed now, or null with the programme
     * off — an order placed then neither spends nor earns.
     *
     * @return array{point_value: float, cap_percent: float, earn_min: float}|null
     */
    public function terms(): ?array
    {
        return $this->policy->enabled() ? $this->policy->terms() : null;
    }

    /**
     * What the customer has to spend, for the quote. Zero when the programme
     * is off — a balance kept through a pause is not spendable during it.
     */
    public function availableFor(User $customer): int
    {
        return $this->policy->enabled() ? $this->points->available($customer) : 0;
    }

    /**
     * Take the points an order was placed with. Inside `place()`'s transaction.
     *
     * @throws RuntimeException `points_changed` when they are no longer there
     */
    public function spend(Order $order, User $customer): void
    {
        $points = (int) $order->points_used;

        if ($points < 1) {
            return;
        }

        try {
            $this->points->debit(
                $customer,
                $points,
                PointReason::Redeemed,
                $order,
                __('Order :code', ['code' => $order->code]),
                null,
                $this->spendKey($order),
            );
        } catch (RuntimeException $e) {
            // The quote was right a second ago; another checkout spent them
            // since. The order is refused, never placed with a discount nobody
            // paid for.
            throw new RuntimeException('points_changed', 0, $e);
        }
    }

    /**
     * The points discount on the pieces the laundry actually counted.
     *
     * At the order's own terms (its point value and cap, stamped at
     * placement), on the washing left after the coupon. Whatever no longer fits
     * goes back to the customer now, in the review's transaction.
     *
     * @return array{points: int, discount: float}
     */
    public function capAfterReview(Order $order, float $subtotal, float $couponDiscount): array
    {
        $used = (int) $order->points_used;

        if ($used < 1) {
            return ['points' => 0, 'discount' => 0.0];
        }

        $terms = (array) ($order->points_terms ?? []);

        // Only the part of the coupon that came off the pieces: a coupon that
        // also covers the delivery fee was sized on both, and its delivery part
        // leaves the washing untouched.
        $onPieces = $order->discount_covers_delivery
            ? self::piecesShare($couponDiscount, $subtotal, (float) $order->delivery_fee)
            : $couponDiscount;

        $allowed = $this->policy->redeemable(
            $used,
            max(round($subtotal - $onPieces, 2), 0.0),
            (float) ($terms['point_value'] ?? $this->valueOf($order)),
            (float) ($terms['cap_percent'] ?? 100.0),
            // The minimum is for choosing to spend, not for what is left of a
            // spend after a recount.
            0,
        );

        if ($allowed['points'] < $used) {
            $spend = $this->spendOf($order);

            if ($spend) {
                // What stays spent, not how much to return: two reviews saved
                // at once then agree, rather than each handing back its own.
                $this->points->giveBack(
                    $spend,
                    $allowed['points'],
                    $order,
                    __('Fewer pieces counted on order :code', ['code' => $order->code]),
                );
            }
        }

        return $allowed;
    }

    /**
     * Every point the order spent comes back — it was cancelled or returned.
     */
    public function giveAllBack(Order $order): int
    {
        if ((int) $order->points_used < 1) {
            return 0;
        }

        $spend = $this->spendOf($order);

        if (! $spend) {
            return 0;
        }

        return $this->points->giveBack(
            $spend,
            0,
            $order,
            __('Order :code did not go ahead', ['code' => $order->code]),
        );
    }

    /**
     * The order's own points, now that it has completed. Once.
     *
     * The washing must reach the minimum stamped when the order was placed —
     * measured on the pieces as counted, before any discount, because that is
     * what was washed.
     */
    public function earn(Order $order): ?PointTransaction
    {
        $points = (int) $order->points_to_earn;
        $customer = $order->customer;

        if ($points < 1 || ! $customer) {
            return null;
        }

        $minimum = (float) (((array) ($order->points_terms ?? []))['earn_min'] ?? 0);
        $washed = (float) ($order->hasFinalPrice() ? $order->final_subtotal : $order->estimated_subtotal);

        if ($minimum > 0 && $washed + 0.001 < $minimum) {
            return null;
        }

        $credit = $this->points->credit(
            $customer,
            $points,
            PointReason::OrderEarned,
            $order,
            __('Order :code', ['code' => $order->code]),
            null,
            'order_earned:'.$order->id,
        );

        if ($credit) {
            $this->announce(fn (PointsNotifier $notifier) => $notifier->earned($credit, $order->code));
        }

        return $credit;
    }

    /**
     * «رجّع شماعات» — the hangers a driver took back at the door.
     *
     * Credited the moment the leg completes (the owner's call), at the rate in
     * force now. The count is stamped on the leg whether or not the programme
     * is on; only the points wait on the switch.
     */
    public function earnForHangers(OrderTask $task): ?PointTransaction
    {
        $hangers = (int) ($task->hangers_returned ?? 0);

        if ($hangers < 1 || ! $task->type->takesHangers() || ! $this->policy->enabled()) {
            return null;
        }

        $points = $hangers * $this->policy->perHanger();
        $order = $task->order;
        $customer = $order?->customer;

        if ($points < 1 || ! $customer) {
            return null;
        }

        $credit = $this->points->credit(
            $customer,
            $points,
            PointReason::HangersReturned,
            $task,
            trans_choice(':count hanger returned on order :code|:count hangers returned on order :code', $hangers, [
                'count' => $hangers,
                'code' => $order->code,
            ]),
            null,
            'hangers:'.$task->id,
        );

        if ($credit) {
            $this->announce(fn (PointsNotifier $notifier) => $notifier->earned($credit, $order->code, $hangers));
        }

        return $credit;
    }

    /**
     * The part of a coupon that came off the pieces, when it was sized on the
     * pieces and the delivery fee together — split in proportion, the way
     * `Order::discountOnPieces()` splits it for the settlement.
     */
    public static function piecesShare(float $discount, float $pieces, float $deliveryFee): float
    {
        $measured = $pieces + max($deliveryFee, 0.0);

        return $measured > 0 ? round($discount * ($pieces / $measured), 2) : 0.0;
    }

    /**
     * The most hangers one visit may record — `Max_Hangers_Per_Visit`.
     */
    public function hangersAllowed(): int
    {
        return $this->policy->maxHangersPerVisit();
    }

    /**
     * What the order has earned so far, for its screens — its own points, and
     * with `$hangers` the hangers handed back on its legs as well.
     */
    public function earnedOn(Order $order, bool $hangers = true): int
    {
        $keys = ['order_earned:'.$order->id];

        if ($hangers) {
            $legs = OrderTask::where('order_id', $order->id)
                ->whereIn('type', [TaskType::PickupFromCustomer->value, TaskType::DeliverToCustomer->value])
                ->pluck('id');

            $keys = array_merge($keys, $legs->map(fn ($id) => 'hangers:'.$id)->all());
        }

        return (int) PointTransaction::query()
            ->where('direction', PointTransaction::CREDIT)
            ->whereIn('dedupe_key', $keys)
            ->sum('points');
    }

    /**
     * The spend behind an order, if it spent anything.
     */
    public function spendOf(Order $order): ?PointTransaction
    {
        return PointTransaction::where('dedupe_key', $this->spendKey($order))->first();
    }

    /**
     * Tell the customer once the points are really theirs — after the commit,
     * so a completion rolled back never announced points it did not pay. A
     * notification that fails is logged and never undoes the credit.
     */
    private function announce(callable $send): void
    {
        DB::afterCommit(function () use ($send) {
            try {
                $send(app(PointsNotifier::class));
            } catch (Throwable $e) {
                Log::warning('[points] notification failed', ['error' => $e->getMessage()]);
            }
        });
    }

    private function spendKey(Order $order): string
    {
        return 'redeemed:'.$order->id;
    }

    /**
     * The point's value on an order placed before terms were stamped — the
     * discount over the points, which is what it was.
     */
    private function valueOf(Order $order): float
    {
        $used = (int) $order->points_used;

        return $used > 0 ? round((float) $order->points_discount / $used, 2) : $this->policy->pointValue();
    }
}
