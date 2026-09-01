<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording what the customer thought.
 *
 * Three rules, and all three exist to protect the figure rather than the flow:
 *
 *   1. Only a finished order can be rated. A rating given while the clothes are
 *      still at the laundry is a guess, and it would sit in the average as if it
 *      were a verdict.
 *   2. Only once. The button can be tapped twice on a slow connection, and an
 *      average that moves with the number of taps is not an average.
 *   3. The laundry is copied from the order, never taken from the request. It is
 *      the only field with any consequence for somebody else, so a client must
 *      not be able to name it.
 */
class RatingService
{
    /**
     * Statuses a rating is allowed on.
     *
     * `Delivered` counts as well as `Completed`: the customer has the clothes in
     * hand and an unpaid cash order can sit at delivered for days, so refusing
     * would mean the rating screen appears long after the experience.
     */
    private const RATEABLE = [
        OrderStatus::Delivered,
        OrderStatus::Completed,
    ];

    /**
     * @param  array{overall: int, service_quality?: int|null, delivery?: int|null, timing?: int|null, tags?: array<int, string>|null, comment?: string|null}  $data
     */
    public function rate(Order $order, User $customer, array $data): OrderRating
    {
        if ((int) $order->user_id !== (int) $customer->id) {
            throw new RuntimeException('not_your_order');
        }

        if (! in_array($order->status, self::RATEABLE, true)) {
            throw new RuntimeException('order_not_finished');
        }

        return DB::transaction(function () use ($order, $customer, $data) {
            // Inside the transaction and locked: two taps a few milliseconds
            // apart would otherwise both pass an unlocked existence check, and
            // the unique index would surface as a 500 rather than a clear refusal.
            $existing = OrderRating::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new RuntimeException('already_rated');
            }

            return OrderRating::create([
                'order_id' => $order->id,
                'user_id' => $customer->id,
                // From the order. Never from the payload — this is the only field
                // whose value affects somebody else's numbers.
                'laundry_id' => $order->laundry_id,
                'overall' => (int) $data['overall'],
                'service_quality' => $this->score($data['service_quality'] ?? null),
                'delivery' => $this->score($data['delivery'] ?? null),
                'timing' => $this->score($data['timing'] ?? null),
                // Empty array and null both mean "picked nothing"; storing null
                // keeps that one shape instead of two.
                'tags' => filled($data['tags'] ?? null) ? array_values(array_unique($data['tags'])) : null,
                'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
            ]);
        });
    }

    /**
     * The rating on an order, if there is one.
     *
     * Unscoped by laundry deliberately: this answers the customer's own «هل
     * قيّمت هذا الطلب؟», and the caller has already established the order is theirs.
     */
    public function forOrder(Order $order): ?OrderRating
    {
        return OrderRating::withoutGlobalScopes()->where('order_id', $order->id)->first();
    }

    /**
     * Whether this customer may still rate this order.
     *
     * The app needs it to decide whether to draw the «تقييم» button at all, and
     * a button that opens a screen which then refuses is worse than no button.
     */
    public function canRate(Order $order, User $customer): bool
    {
        return (int) $order->user_id === (int) $customer->id
            && in_array($order->status, self::RATEABLE, true)
            && $this->forOrder($order) === null;
    }

    /**
     * A skipped aspect stays null rather than becoming 0.
     *
     * Zero is not a score the design can produce — the stars run 1 to 5 — and a 0
     * in the column would drag every average down while looking like data.
     */
    private function score(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $score = (int) $value;

        return $score >= 1 && $score <= 5 ? $score : null;
    }
}
