<?php

namespace App\Modules\Order\Services;

use App\Modules\Notification\Services\OrderNotifier;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The laundry counts the pieces; the customer agrees to the number.
 *
 * This is where an order stops being an estimate. Three things about it are worth
 * stating plainly, because each is the sort of rule that is expensive to get
 * wrong and invisible when it is:
 *
 *  1. **The estimated lines are never touched.** A review writes a second set of
 *     rows with `phase = final`. That is what makes the design's «مقارنة القطع»
 *     — 8 قطع at ordering against 9 قطع after counting — reconstructable at any
 *     later date, including in an argument.
 *
 *  2. **Prices are re-read once, here, and copied.** Same rule as OrderPricing:
 *     the figures land on the order and nothing downstream consults the matrix
 *     again. A price edited next week must not silently restate an invoice.
 *
 *  3. **Only the pieces are re-priced.** The delivery fee and the discount carry
 *     over from the order untouched. The laundry counted clothes, not kilometres,
 *     and letting a review move the fee would let it move a number the customer
 *     already agreed to for reasons that have nothing to do with the review.
 */
class OrderReviewService
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly TaskGenerator $tasks,
    ) {}

    /**
     * Record what the laundry actually received.
     *
     * @param  array<int, array{item_id: int, qty: int, unit_price?: float|string|null}>  $lines
     *
     * @throws RuntimeException
     */
    public function review(Order $order, array $lines, ?string $note = null, ?User $actor = null): Order
    {
        if (! $order->status->isReviewable()) {
            throw new RuntimeException('not_reviewable');
        }

        $service = $order->service;

        if (! $service) {
            throw new RuntimeException('service_not_found');
        }

        [$priced, $count, $subtotal, $unpriced] = $this->price($service, $lines);

        if ($unpriced !== []) {
            throw new RuntimeException('unpriced_items:'.implode(',', $unpriced));
        }

        if ($count < 1) {
            // A review that finds nothing is not a review — the pieces are
            // physically present, somebody just did not enter them.
            throw new RuntimeException('empty_review');
        }

        return DB::transaction(function () use ($order, $priced, $count, $subtotal, $note, $actor) {
            // A re-review replaces the previous final set rather than adding to
            // it. The estimated rows, and therefore the original agreement,
            // survive both.
            OrderItem::where('order_id', $order->id)->where('phase', 'final')->delete();

            foreach ($priced as $line) {
                OrderItem::create($line + ['order_id' => $order->id, 'phase' => 'final']);
            }

            $order->update([
                'final_items_count' => $count,
                'final_subtotal' => $subtotal,
                // Delivery and discount are carried over, not recomputed.
                'final_total' => round(
                    $subtotal + (float) $order->delivery_fee - (float) $order->discount_total,
                    2
                ),
                'review_note' => $note,
                'reviewed_at' => now(),
                'review_round' => $order->review_round + 1,
            ]);

            $this->machine->transition(
                $order,
                OrderStatus::Reviewed,
                'laundry',
                $actor,
                $note ?: __('Pieces reviewed and priced.')
            );

            return $order->refresh();
        });
    }

    /**
     * «تأكيد السعر» — the customer accepts, and the work is released.
     *
     * Note what this does NOT do: take money. A card customer pays at this moment
     * and a COD customer pays at delivery, and neither changes where the order
     * sits. Confirmation is the gate; payment is a fact recorded beside it.
     */
    public function confirm(Order $order, User $customer): Order
    {
        if (! $order->status->isAwaitingCustomer()) {
            throw new RuntimeException('not_awaiting_confirmation');
        }

        if ($order->final_total === null) {
            // Defensive: `reviewed` without a final price would mean the laundry
            // moved the order by hand. Confirming a price that does not exist
            // would bind the customer to nothing.
            throw new RuntimeException('no_final_price');
        }

        return DB::transaction(function () use ($order, $customer) {
            $order->update(['confirmed_at' => now()]);

            $order = $this->machine->transition(
                $order,
                OrderStatus::Confirmed,
                'customer',
                $customer,
                __('Customer confirmed the final price.')
            );

            // Backstop only. The chain is created when the order is placed — a
            // pickup has to exist before anybody can collect — so this catches an
            // order placed before P8 existed. Idempotent either way.
            $this->tasks->generate($order);

            return $order;
        });
    }

    /**
     * «طلب مراجعة إضافية» — count them again.
     *
     * The order goes back to the laundry with the customer's reason attached, and
     * `review_round` remembers how many times this has happened.
     */
    public function dispute(Order $order, User $customer, ?string $reason = null): Order
    {
        if (! $order->status->isAwaitingCustomer()) {
            throw new RuntimeException('not_awaiting_confirmation');
        }

        return $this->machine->transition(
            $order,
            OrderStatus::ReviewDisputed,
            'customer',
            $customer,
            $reason ?: __('Customer asked for a second review.')
        );
    }

    /**
     * «لدي استفسار عن السعر» — a question, not a movement.
     *
     * The order deliberately stays exactly where it is: the customer has asked
     * something, not refused anything, and moving the order would misrepresent
     * that to everyone who looks at it afterwards.
     */
    public function raiseQuery(Order $order, User $customer, string $message): OrderPriceQuery
    {
        return OrderPriceQuery::create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'message' => $message,
        ]);
    }

    public function answerQuery(OrderPriceQuery $query, string $answer, ?User $actor = null): OrderPriceQuery
    {
        if ($query->isAnswered()) {
            throw new RuntimeException('already_answered');
        }

        $query->update([
            'answer' => $answer,
            'answered_at' => now(),
            'answered_by' => $actor?->id,
        ]);

        $query->refresh();

        try {
            app(OrderNotifier::class)
                ->priceQuestionAnswered($query);
        } catch (\Throwable $e) {
            Log::warning('[notifications] price answer', [
                'query' => $query->id, 'error' => $e->getMessage(),
            ]);
        }

        return $query;
    }

    /**
     * The comparison the design draws: what was agreed against what was found.
     *
     * @return array<string, mixed>
     */
    public function comparison(Order $order): array
    {
        return [
            'estimated' => [
                'items_count' => $order->estimated_items_count,
                'subtotal' => (float) $order->estimated_subtotal,
                'total' => (float) $order->estimated_total,
                'lines' => $this->presentLines($order, 'estimated'),
            ],
            'final' => $order->final_total === null ? null : [
                'items_count' => $order->final_items_count,
                'subtotal' => (float) $order->final_subtotal,
                'total' => (float) $order->final_total,
                'lines' => $this->presentLines($order, 'final'),
            ],
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount_total,
            'note' => $order->review_note,
            'reviewed_at' => $order->reviewed_at ? humanDate($order->reviewed_at) : null,
            'review_round' => $order->review_round,
            // The figure the customer is being asked to agree to.
            'difference' => $order->final_total === null
                ? null
                : round((float) $order->final_total - (float) $order->estimated_total, 2),
        ];
    }

    /**
     * Price a basket — against the matrix, or against what the laundry typed.
     *
     * @param  array<int, array{item_id: int, qty: int, unit_price?: float|string|null}>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: float, 3: array<int, int>}
     */
    private function price(Service $service, array $lines): array
    {
        // «تنظيف جاف» carries no catalogue prices by design — the cost of
        // cleaning a suit depends on the fabric and the stain, which is the whole
        // reason it is quoted after inspection. For those the laundry types the
        // unit price beside the count; for every other service the matrix is the
        // only source, and a price arriving in the request is ignored rather than
        // trusted. That distinction is the security-relevant half of this method:
        // a posted price must never be able to re-cost a catalogued service.
        $quoted = ! $service->isPerItem();

        $prices = $quoted
            ? collect()
            : ItemPrice::where('service_id', $service->id)
                ->whereIn('item_id', array_column($lines, 'item_id'))
                ->pluck('price', 'item_id');

        $priced = [];
        $unpriced = [];
        $count = 0;
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $itemId = (int) $line['item_id'];
            $qty = (int) $line['qty'];

            if ($qty < 1) {
                // A piece counted down to zero is a piece that is not there.
                continue;
            }

            if ($quoted) {
                $submitted = $line['unit_price'] ?? null;

                // A counted piece with no price is the one case that must not
                // pass quietly: it would be cleaned and never charged for.
                if ($submitted === null || $submitted === '' || (float) $submitted < 0) {
                    $unpriced[] = $itemId;

                    continue;
                }

                $unit = round((float) $submitted, 2);
            } elseif (! isset($prices[$itemId])) {
                $unpriced[] = $itemId;

                continue;
            } else {
                $unit = (float) $prices[$itemId];
            }

            $total = round($unit * $qty, 2);

            $priced[] = [
                'item_id' => $itemId,
                'qty' => $qty,
                'unit_price' => $unit,
                'line_total' => $total,
            ];

            $count += $qty;
            $subtotal += $total;
        }

        return [$priced, $count, round($subtotal, 2), $unpriced];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentLines(Order $order, string $phase): array
    {
        $out = [];

        foreach ($order->items->where('phase', $phase) as $line) {
            $out[] = [
                'item_id' => $line->item_id,
                'name' => $line->item ? getLocalizedValue($line->item, 'name') : null,
                'qty' => $line->qty,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ];
        }

        return $out;
    }
}
