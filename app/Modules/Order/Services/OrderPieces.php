<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * The pieces of an order as the customer is shown them (2026-11-08).
 *
 * The client's sheet, item 2: «أعداد القطع لسه بتظهر للعميل غير مطابقة بعد ما
 * سلّم المندوب عدد أكبر من اللي في الأوردر». The order screen served the
 * customer's basket **and** the laundry's count in one `items` list, so a
 * customer who handed over two more shirts saw both sets side by side and a
 * count that matched neither. The owner's answer: **the laundry's count is the
 * order** once it reaches the customer; a piece they added reads «مضافة», not
 * as a difference.
 *
 * One place answers it for the order screen and the price review alike:
 *
 * - `current()` — the lines that apply now: the laundry's count once the
 *   customer is shown it (`Order::customerSeesFinal()`), the basket before.
 * - `change` on each line, against the basket: `added` (not in it), `more`,
 *   `fewer`, `same` — null before the count.
 * - on the basket's own lines, `missing` for a piece the count did not find.
 *
 * Compared per item, quantities summed, so a basket that named one item on two
 * lines is not read as a change.
 */
class OrderPieces
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function current(Order $order): array
    {
        $counted = $order->customerSeesFinal();
        $estimate = $this->quantities($order, 'estimated');

        return $this->lines($order, $counted ? 'final' : 'estimated')
            ->map(fn (OrderItem $line) => $this->present($line) + [
                'change' => $counted ? $this->changeOf($estimate[$line->item_id] ?? 0, $this->qtyIn($order, 'final', $line->item_id)) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The customer's own basket, each line with what the count found.
     *
     * @return array<int, array<string, mixed>>
     */
    public function estimated(Order $order): array
    {
        $counted = $order->customerSeesFinal();
        $final = $this->quantities($order, 'final');

        return $this->lines($order, 'estimated')
            ->map(function (OrderItem $line) use ($counted, $final, $order) {
                $found = $final[$line->item_id] ?? 0;

                return $this->present($line) + [
                    'counted' => $counted ? $found : null,
                    'change' => ! $counted ? null : ($found === 0
                        ? 'missing'
                        : $this->changeOf($this->qtyIn($order, 'estimated', $line->item_id), $found)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Pieces the count found beyond the basket, and pieces of it not found —
     * both 0 before the count.
     *
     * @return array{pieces_added: int, pieces_missing: int}
     */
    public function totals(Order $order): array
    {
        if (! $order->customerSeesFinal()) {
            return ['pieces_added' => 0, 'pieces_missing' => 0];
        }

        $estimate = $this->quantities($order, 'estimated');
        $final = $this->quantities($order, 'final');
        $added = 0;
        $missing = 0;

        foreach (array_keys($estimate + $final) as $itemId) {
            $diff = ($final[$itemId] ?? 0) - ($estimate[$itemId] ?? 0);
            $diff > 0 ? $added += $diff : $missing -= $diff;
        }

        return ['pieces_added' => $added, 'pieces_missing' => $missing];
    }

    private function changeOf(int $estimated, int $counted): string
    {
        return match (true) {
            $estimated === 0 => 'added',
            $counted > $estimated => 'more',
            $counted < $estimated => 'fewer',
            default => 'same',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrderItem $line): array
    {
        return [
            'item_id' => $line->item_id,
            'name' => $line->item ? getLocalizedValue($line->item, 'name') : null,
            'phase' => $line->phase,
            'qty' => $line->qty,
            'unit_price' => (float) $line->unit_price,
            'line_total' => (float) $line->line_total,
        ];
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private function lines(Order $order, string $phase): Collection
    {
        return $order->items->where('phase', $phase);
    }

    /**
     * @return array<int, int> item id => quantity
     */
    private function quantities(Order $order, string $phase): array
    {
        $out = [];

        foreach ($this->lines($order, $phase) as $line) {
            $out[$line->item_id] = ($out[$line->item_id] ?? 0) + (int) $line->qty;
        }

        return $out;
    }

    private function qtyIn(Order $order, string $phase, ?int $itemId): int
    {
        return $this->quantities($order, $phase)[$itemId] ?? 0;
    }
}
