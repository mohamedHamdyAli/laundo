<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Pricing\Services\PlatformFee;
use App\Modules\Service\Models\Service;

/**
 * Turns a basket into money.
 *
 * The single rule worth stating: **prices are read once, here, and then copied
 * onto the order.** Nothing downstream re-reads the price matrix. A super admin
 * raising a shirt from 17 to 19 tomorrow must not change what a customer agreed
 * to today, and an invoice that edits itself is a dispute waiting to happen.
 */
class OrderPricing
{
    public function __construct(
        private readonly DeliveryFeeCalculator $deliveryFee,
        private readonly PlatformFee $platformFee,
    ) {}

    /**
     * The delivery leg alone.
     *
     * Needed when a laundry is assigned after the fact: the pieces were priced
     * when the order was placed and must not be re-read, but the fee is measured
     * from the laundry and so could not be worked out until now.
     *
     * @return array{fee: float|null, distance_km: float|null, reason: string|null, distance_source: string|null, distance_minutes: float|null}
     */
    public function deliveryFeeFor(?Laundry $laundry, Address $pickup, ?Address $delivery = null): array
    {
        return $this->deliveryFee->calculate($laundry, $pickup, $delivery);
    }

    /**
     * Price a basket for one service.
     *
     * @param  array<int, array{item_id: int, qty: int}>  $items
     * @return array{
     *     lines: array<int, array{item_id: int, qty: int, unit_price: float, base_unit_price: float, line_total: float}>,
     *     items_count: int,
     *     subtotal: float,
     *     delivery_fee: float|null,
     *     delivery_distance_km: float|null,
     *     delivery_fee_reason: string|null,
     *     delivery_distance_source: string|null,
     *     discount: float,
     *     cash_surcharge: float,
     *     platform_fee: float,
     *     platform_fee_rate: float,
     *     tax_rate: float,
     *     tax: float,
     *     pre_tax_total: float,
     *     total: float,
     *     unpriced: array<int, int>
     * }
     */
    public function quote(
        Service $service,
        array $items,
        Address $pickup,
        ?Address $delivery = null,
        ?Laundry $laundry = null,
        float $discount = 0.0,
        ?string $paymentMethod = null,
    ): array {
        $lines = [];
        $unpriced = [];
        $subtotal = 0.0;
        // What the laundry prices the same basket at, carried alongside. The
        // platform's fee is the gap between the two, taken by subtraction rather
        // than as a second percentage — the per-piece rounding has already
        // happened, so a re-derived figure would disagree with the lines the
        // customer is looking at.
        $baseSubtotal = 0.0;
        $count = 0;

        // A quoted service has no per-piece prices at all — it is costed after the
        // pieces are inspected, in P7 — so its basket produces no lines.
        if ($service->isPerItem()) {
            $prices = ItemPrice::where('service_id', $service->id)
                ->whereIn('item_id', array_column($items, 'item_id'))
                ->pluck('price', 'item_id');

            foreach ($items as $line) {
                $itemId = (int) $line['item_id'];
                $qty = (int) $line['qty'];

                if ($qty < 1) {
                    continue;
                }

                if (! isset($prices[$itemId])) {
                    // The service simply is not offered for this piece. Collected
                    // and reported rather than treated as free.
                    $unpriced[] = $itemId;

                    continue;
                }

                $base = (float) $prices[$itemId];

                // The price the customer is quoted already carries the
                // platform's fee. It is folded in here, per piece, rather than
                // added to the subtotal at the end: the customer multiplies this
                // number by a quantity, so it is this number that has to be
                // true, and a line that does not equal its own unit price times
                // its own quantity is the first thing somebody checks when they
                // think they have been overcharged.
                $unit = $this->platformFee->onUnit($base);
                $total = round($unit * $qty, 2);

                $lines[] = [
                    'item_id' => $itemId,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    // The laundry's own figure, kept beside the customer's. It
                    // cannot be recovered by dividing the fee back out — that is
                    // rounded per piece — and the review form has to be able to
                    // show the laundry what *it* charged.
                    'base_unit_price' => $base,
                    'line_total' => $total,
                ];

                $subtotal += $total;
                $baseSubtotal += round($base * $qty, 2);
                $count += $qty;
            }
        }

        $subtotal = round($subtotal, 2);

        $fee = $this->deliveryFee->calculate($laundry, $pickup, $delivery);

        // A discount cannot exceed what is being discounted.
        $discount = round(min(max($discount, 0.0), $subtotal), 2);

        $surcharge = $this->cashSurcharge($paymentMethod);

        $money = $this->compose(
            $subtotal,
            (float) ($fee['fee'] ?? 0),
            $discount,
            $surcharge,
            $this->taxRate(),
        );

        return [
            'lines' => $lines,
            'items_count' => $count,
            'subtotal' => $subtotal,
            'delivery_fee' => $fee['fee'],
            'delivery_distance_km' => $fee['distance_km'],
            'delivery_fee_reason' => $fee['reason'],
            // Which measurement produced the fee. Carried through so a screen
            // can mark a straight-line fallback rather than presenting it as a
            // road distance somebody drove.
            'delivery_distance_source' => $fee['distance_source'],
            'discount' => $discount,
            // Its own line, never folded into the delivery fee: the customer can
            // remove it by paying another way, and a charge you cannot see is a
            // charge you cannot avoid.
            'cash_surcharge' => $surcharge,
            // Inside `subtotal`, not beside it — so nothing that sums this array
            // may add it again. It is carried out so the order can store it and
            // the settlement can divide the right number; it is deliberately not
            // returned to the customer's app, because the whole point is that
            // there is one price.
            'platform_fee' => $this->platformFee->within($subtotal, round($baseSubtotal, 2)),
            'platform_fee_rate' => $this->platformFee->rate(),
            'tax_rate' => $money['tax_rate'],
            'tax' => $money['tax'],
            'pre_tax_total' => $money['pre_tax_total'],
            'total' => $money['total'],
            'unpriced' => $unpriced,
        ];
    }

    /**
     * Add a set of lines up. **The only place an order total is arrived at.**
     *
     * Both totals an order carries go through here — the estimate at placement
     * and the final figure once the laundry has counted the pieces — because
     * they were assembled in two places and had already drifted: the estimate
     * added `cash_surcharge` and the final one silently dropped it, so a cash
     * customer's handling fee disappeared the moment their order was reviewed.
     * Two expressions of one rule is one expression too many.
     *
     * The order of operations, and each step is a decision:
     *
     *   subtotal - discount        a coupon discounts the washing
     *   + delivery fee             measured from the laundry, never discounted
     *   + cash surcharge           added after the discount, deliberately: a
     *                              coupon large enough would otherwise pay the
     *                              customer to use notes
     *   = pre-tax total
     *   + tax                      «ضريبة الدولة بتضاف على الإجمالي» — on the
     *                              whole supply, which is what makes the invoice
     *                              readable top to bottom: every line, then the
     *                              tax, then the total
     *
     * `$rate` is passed in rather than read here. A placed order carries the
     * rate that was in force when it was placed, and re-reading the setting at
     * review time would retax an agreed order at next quarter's rate.
     *
     * @return array{pre_tax_total: float, tax_rate: float, tax: float, total: float}
     */
    public function compose(
        float $subtotal,
        float $deliveryFee,
        float $discount,
        float $surcharge,
        float $rate,
    ): array {
        $preTax = round($subtotal + $deliveryFee - $discount + $surcharge, 2);

        // Floored before the tax is taken, not after. A negative base would hand
        // the customer tax back, and a total that cannot go below zero must not
        // reach zero by way of a credit from the treasury.
        $preTax = max($preTax, 0.0);

        $tax = round($preTax * $rate / 100, 2);

        return [
            'pre_tax_total' => $preTax,
            'tax_rate' => $rate,
            'tax' => $tax,
            'total' => round($preTax + $tax, 2),
        ];
    }

    /**
     * The configured tax, as a percentage.
     *
     * `Tax` has been on the settings form, validated and stored since P9 and read
     * by nothing at all — the same fault `Cash_Surcharge` had. An invoice with no
     * tax line is an invoice that cannot be filed.
     *
     * Unset means none. Egypt's VAT is not something to assume on an install
     * whose operator has not said so.
     */
    public function taxRate(): float
    {
        $configured = getSettingValue('Tax');

        if ($configured === null || $configured === '') {
            return 0.0;
        }

        // Clamped rather than trusted: the settings column is a string and a
        // fat-fingered 1000 would triple every invoice in the country.
        return round(max(min((float) $configured, 100.0), 0.0), 2);
    }

    /**
     * «قد يتم تطبيق رسوم إضافية» — the cash handling fee.
     *
     * `Cash_Surcharge` has been on the settings form, validated and stored since
     * P9, and **nothing read it**. A configured surcharge changed no price at all.
     *
     * A fixed amount rather than a percentage, which is what the validation says:
     * `max:1000` next to `Driver_Earning_Rate`'s `max:100`. Handling notes costs
     * the same whether the order is thirty pounds or three hundred.
     *
     * Applied only when the customer is paying cash. An unknown or absent method
     * adds nothing — a quote taken before the customer has chosen must not show a
     * fee they may never incur.
     */
    private function cashSurcharge(?string $paymentMethod): float
    {
        if ($paymentMethod !== PaymentMethod::Cash->value) {
            return 0.0;
        }

        $configured = getSettingValue('Cash_Surcharge');

        // Unset means off, which is the design's own default: «قد يتم تطبيق» is
        // permissive, not a promise.
        return $configured === null ? 0.0 : round(max((float) $configured, 0.0), 2);
    }
}
