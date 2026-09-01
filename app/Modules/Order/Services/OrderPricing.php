<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Pricing\Models\ItemPrice;
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
    public function __construct(private readonly DeliveryFeeCalculator $deliveryFee) {}

    /**
     * The delivery leg alone.
     *
     * Needed when a laundry is assigned after the fact: the pieces were priced
     * when the order was placed and must not be re-read, but the fee is measured
     * from the laundry and so could not be worked out until now.
     *
     * @return array{fee: float|null, distance_km: float|null, reason: string|null}
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
     *     lines: array<int, array{item_id: int, qty: int, unit_price: float, line_total: float}>,
     *     items_count: int,
     *     subtotal: float,
     *     delivery_fee: float|null,
     *     delivery_distance_km: float|null,
     *     delivery_fee_reason: string|null,
     *     discount: float,
     *     cash_surcharge: float,
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

                $unit = (float) $prices[$itemId];
                $total = round($unit * $qty, 2);

                $lines[] = [
                    'item_id' => $itemId,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $total,
                ];

                $subtotal += $total;
                $count += $qty;
            }
        }

        $subtotal = round($subtotal, 2);

        $fee = $this->deliveryFee->calculate($laundry, $pickup, $delivery);

        // A discount cannot exceed what is being discounted.
        $discount = round(min(max($discount, 0.0), $subtotal), 2);

        $surcharge = $this->cashSurcharge($paymentMethod);

        // Added after the discount, deliberately. A coupon discounts the washing,
        // not the cost of handling cash — discounting the surcharge would mean a
        // large enough coupon paid the customer to use notes.
        $total = round($subtotal + (float) ($fee['fee'] ?? 0) - $discount + $surcharge, 2);

        return [
            'lines' => $lines,
            'items_count' => $count,
            'subtotal' => $subtotal,
            'delivery_fee' => $fee['fee'],
            'delivery_distance_km' => $fee['distance_km'],
            'delivery_fee_reason' => $fee['reason'],
            'discount' => $discount,
            // Its own line, never folded into the delivery fee: the customer can
            // remove it by paying another way, and a charge you cannot see is a
            // charge you cannot avoid.
            'cash_surcharge' => $surcharge,
            'total' => max($total, 0.0),
            'unpriced' => $unpriced,
        ];
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
