<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Repositories\OrderRepository;

/**
 * Whether this order may be erased, and if not, why not.
 *
 * The panel had no delete for orders at all, and the reason is still good: an
 * order is a customer's agreement, and an operator who can erase one can erase
 * the evidence of what was agreed. What that rule was never really about is the
 * row nobody has touched yet — a booking placed by mistake, a duplicate, a test
 * order left over from a demo — which sat in the list for ever because the only
 * way out of it was a state the panel could not reach either.
 *
 * So the delete exists, and this class is the whole of its licence. Two
 * questions, and a no from either is a no:
 *
 * 1. **Are the clothes with us?** `isInCustody()` is false for exactly three
 *    statuses — `awaiting_pickup`, `driver_on_way`, `cancelled`. Past that
 *    point somebody is holding a stranger's laundry, and deleting the order
 *    deletes the only record of whose it is and where it goes back to.
 * 2. **Has money moved?** Eleven tables cascade off an order row, five of them
 *    financial, and `wallet_transactions` does not even cascade — it names its
 *    source polymorphically and would be left pointing at nothing. See
 *    `OrderRepository::moneyTrace()`.
 *
 * One class rather than a condition in the service because the *view* has to
 * ask the same question: a delete button that is drawn and then refuses is a
 * worse screen than one that explains itself in place. Both call this, so they
 * cannot drift.
 */
class OrderDeletionGuard
{
    public function __construct(private readonly OrderRepository $orders) {}

    /**
     * The reason this order may not be deleted, or null when it may.
     *
     * Returned already translated: every caller — the list, the detail screen,
     * the flash message after a refused POST — shows it to a person, and a bare
     * key would have each of them inventing its own wording.
     */
    public function blocker(Order $order): ?string
    {
        if ($order->status->isInCustody()) {
            return __('This order has already been collected. Its record is the only proof of whose clothes these are, so it cannot be deleted — it can only be cancelled or completed.');
        }

        return match ($this->orders->moneyTrace($order)) {
            'payment' => __('A payment has been recorded against this order, so it cannot be deleted.'),
            'refund' => __('A refund has been issued on this order, so it cannot be deleted.'),
            'settlement' => __('This order has been settled with the laundry, so it cannot be deleted.'),
            'earning' => __('A driver has been paid for this order, so it cannot be deleted.'),
            'wallet' => __('This order has moved money in a wallet, so it cannot be deleted.'),
            default => null,
        };
    }

    public function allows(Order $order): bool
    {
        return $this->blocker($order) === null;
    }

    /**
     * The half of the rule a list can afford to ask.
     *
     * `blocker()` costs up to five existence queries, which is fine for one
     * order and is seventy-five of them on a page of fifteen. The status half
     * is free — it is already loaded — and it is the half that disqualifies
     * almost everything, so the list draws the button on the rows this passes
     * and `deleteRecord()` applies the money half when one is actually pressed.
     *
     * The cost of the gap is a rare refusal with a clear reason on it, which is
     * the behaviour anyway for a screen that has been open a while.
     */
    public function statusAllows(Order $order): bool
    {
        return ! $order->status->isInCustody();
    }
}
