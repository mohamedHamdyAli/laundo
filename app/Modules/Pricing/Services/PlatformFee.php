<?php

namespace App\Modules\Pricing\Services;

/**
 * The platform's own charge on an order, carried by the customer.
 *
 * **Three separate things take money out of an order, and only two of them used
 * to exist.** The state takes the tax, the platform takes this, and the laundry
 * pays its own commission out of what is left. Before this, `Commission_Rate`
 * was the laundry's fallback commission — one charge wearing two hats — so the
 * platform could only ever be paid by taking a slice of the laundry's work.
 *
 * This is the other side: a rate the *customer* pays, on top of the laundry's
 * price, so the laundry is owed its price in full.
 *
 * **It is folded into the per-piece price and never shown as a line.** That is
 * not decoration. An invoice carries a tax line, and a tax line obliges the
 * document to add up: if the fee were a hidden addend the customer could
 * subtract the rows they can see from the total they paid and find a gap with
 * no name on it. Folding it into the unit price means every row, the subtotal
 * and the total reconcile exactly, and there is nothing left over to explain.
 *
 * **One definition, and it has to stay one.** The catalogue the customer browses,
 * the quote, the order and the invoice all price the same shirt, and a second
 * copy of this arithmetic anywhere is how the price on the list stops matching
 * the price on the bill.
 */
class PlatformFee
{
    /**
     * The configured rate, as a percentage.
     *
     * Clamped rather than trusted, for the reason `OrderPricing::taxRate()` is:
     * the settings column is a string, and a fat-fingered 1000 would multiply
     * every price in the country by eleven.
     *
     * Unset or zero means none, and none is a real answer — an install that has
     * not set a rate charges nothing rather than guessing one.
     */
    public function rate(): float
    {
        $configured = getSettingValue('Commission_Rate');

        if ($configured === null || $configured === '') {
            return 0.0;
        }

        return round(max(min((float) $configured, 100.0), 0.0), 2);
    }

    /**
     * What the customer is shown for a piece the laundry prices at `$base`.
     *
     * Rounded here, per piece, and not at the end: the customer sees this number
     * multiplied by a quantity, so it is the rounded one that has to be true.
     * Rounding the subtotal instead would leave a line that does not equal its
     * own unit price times its own quantity — visible on the invoice, and the
     * first thing somebody checks when they think they have been overcharged.
     */
    public function onUnit(float $base): float
    {
        return $this->onUnitAt($base, $this->rate());
    }

    /**
     * The same, at a rate given rather than read.
     *
     * **This is the one an order in flight must use.** `onUnit()` reads the
     * setting as it stands now, which is right when a price is being quoted for
     * the first time and wrong everywhere else: an order is priced twice — once
     * at placement and again when the laundry counts the pieces — and between
     * those two moments somebody may have changed the rate. Re-reading it there
     * would hand the customer a final bill above the estimate they agreed to,
     * for a piece count that did not change, and the only column that could
     * explain the difference would still be showing the old rate.
     *
     * Same rule as the tax rate, and for the same reason: it is stamped onto the
     * order at placement and the review is handed the stamp, not the setting.
     */
    public function onUnitAt(float $base, float $rate): float
    {
        $rate = round(max(min($rate, 100.0), 0.0), 2);

        return round($base * (1 + $rate / 100), 2);
    }

    /**
     * The fee inside a customer-facing subtotal.
     *
     * Taken as the difference between what the customer pays for the pieces and
     * what the laundry prices them at — never as a second percentage of either.
     * The rounding already happened per line, so re-deriving the fee from a rate
     * would disagree with the lines by a few piastres, and the settlement would
     * then divide a number the invoice does not contain.
     */
    public function within(float $customerSubtotal, float $baseSubtotal): float
    {
        return round(max($customerSubtotal - $baseSubtotal, 0.0), 2);
    }
}
