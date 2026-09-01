<?php

namespace App\Modules\Payment\Services;

use App\Modules\Order\Models\Order;

/**
 * «تحميل الفاتورة».
 *
 * The rule, and the only interesting thing about this class: **an invoice is
 * assembled from the order's stored figures and never recomputed.** Same reason
 * prices are copied onto the order in the first place — a document that
 * recalculates itself is one that can disagree with the one the customer already
 * has, and the customer's copy is the one that will be produced in an argument.
 *
 * Rendered as a printable HTML page rather than a PDF: no PDF package is
 * installed, and adding a dependency is not a decision to slip into a phase. The
 * browser's own print-to-PDF produces the same document.
 */
class InvoiceRenderer
{
    /**
     * Everything the invoice shows, read once.
     *
     * @return array<string, mixed>
     */
    public function data(Order $order): array
    {
        $order->loadMissing(['customer', 'laundry', 'service', 'items.item', 'pickupAddress', 'deliveryAddress']);

        // The final set if the laundry has counted, the customer's estimate if it
        // has not — the same figures the order itself displays.
        $phase = $order->hasFinalPrice() ? 'final' : 'estimated';
        $lines = [];

        foreach ($order->items->where('phase', $phase) as $item) {
            $lines[] = [
                'name' => $item->item ? getLocalizedValueDashboard($item->item, 'name') : '-',
                'qty' => $item->qty,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ];
        }

        $subtotal = $order->hasFinalPrice()
            ? (float) $order->final_subtotal
            : (float) $order->estimated_subtotal;

        return [
            'order' => $order,
            'number' => 'INV-'.$order->code,
            'issued_at' => $order->confirmed_at ?? $order->created_at,
            'is_final' => $order->hasFinalPrice(),
            'lines' => $lines,
            'subtotal' => $subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount_total,
            'total' => $order->payableTotal(),
            'paid' => $order->payment_status === 'paid',
            // «رقم المعاملة», when there is one.
            'transaction_reference' => $order->payments()->captured()->value('provider_reference'),
        ];
    }
}
