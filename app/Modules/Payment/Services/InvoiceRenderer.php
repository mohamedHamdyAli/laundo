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
     * One half of the journey, as the invoice says it.
     *
     * @return array<string, string|null>
     */
    private function leg($date, ?string $window, $address, ?string $method): array
    {
        return [
            'date' => $date ? humanDate($date, 'Y-m-d') : null,
            'window' => $window,
            'address' => $this->addressLine($address),
            // «Leave at the door» is not a detail: it is the difference between a
            // handover somebody signed for and a bag left on a step, and it is
            // the first thing asked about when a delivery is disputed.
            'method' => $method === null
                ? null
                : ($method === 'leave' ? __('Leave at the door') : __('Hand to the customer')),
        ];
    }

    /**
     * An address on one line, skipping the parts that are not filled.
     *
     * Composed here rather than on the model: the panel shows these fields in a
     * stacked list where a missing floor simply leaves a gap, and only a printed
     * document needs them run together without stray commas.
     */
    private function addressLine($address): ?string
    {
        if ($address === null) {
            return null;
        }

        $parts = array_filter([
            $address->street,
            $address->building ? __('Building').' '.$address->building : null,
            $address->floor ? __('Floor').' '.$address->floor : null,
            $address->apartment ? __('Apartment').' '.$address->apartment : null,
            $address->landmark,
        ], fn ($part) => filled($part));

        return $parts === [] ? null : implode('، ', $parts);
    }

    /**
     * Who the invoice is from — and nothing that only looks like it.
     *
     * Every value goes through `realSetting()`, the same guard that keeps seed
     * data off the landing page, because **every issuer setting on this install
     * is a placeholder**: `App_Name` is «BaseCode», `Email` is
     * `nahrPhpTeam@nahrPhpTeam.com`, the logo is the shipped `logo1.png` and
     * `Call` is empty. Printing those on a customer's invoice is worse than
     * printing nothing, and printing nothing is exactly what this does.
     *
     * `Invoice_Legal_Name` rather than `App_Name` where it exists: a registered
     * business is often called something other than the product, and the invoice
     * is the one document that has to use the registered name.
     *
     * @return array<string, string|null>
     */
    private function issuer(): array
    {
        return [
            'name' => realSetting('Invoice_Legal_Name')
                ?? realSetting('App_Name')
                ?? config('app.name'),
            'address' => realSetting('Invoice_Address'),
            'tax_number' => realSetting('Invoice_Tax_Number'),
            'phone' => realSetting('Call') ?? realSetting('Hotline'),
            'email' => realSetting('Email'),
        ];
    }

    /**
     * Everything the invoice shows, read once.
     *
     * @return array<string, mixed>
     */
    public function data(Order $order): array
    {
        $order->loadMissing([
            'customer', 'laundry', 'service', 'items.item',
            'pickupAddress', 'deliveryAddress', 'pickupSlot', 'deliverySlot',
        ]);

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
            // Who is billing. Every one of these is nullable and every one is
            // printed only when it is real — see `issuer()`.
            'issuer' => $this->issuer(),
            // Who did the work. A laundry's own row carries a real phone, email
            // and address, unlike the platform settings on this install.
            'laundry' => $order->laundry,
            'service' => $order->service,
            'status_label' => __($order->status->label()),
            // The two halves of the journey. An invoice with a tax line and no
            // service date says what was charged and never when it was done.
            'collection' => $this->leg(
                $order->pickup_date,
                $order->pickupSlot?->label(),
                $order->pickupAddress,
                $order->pickup_method,
            ),
            'delivery' => $this->leg(
                $order->delivery_date,
                $order->deliverySlot?->label(),
                $order->deliveryAddress ?? $order->pickupAddress,
                $order->delivery_method,
            ),
            'number' => 'INV-'.$order->code,
            'issued_at' => $order->confirmed_at ?? $order->created_at,
            'is_final' => $order->hasFinalPrice(),
            'lines' => $lines,
            // **The rows come from the order, not from here.** This class and
            // the order screen's pricing card each used to assemble them, and
            // they disagreed: «Subtotal» against «Estimated subtotal», and a
            // before-tax line on one and not the other.
            'money_rows' => $order->moneyRows(),
            'subtotal' => $subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount_total,
            // Both were computed into the total and shown on neither line. A
            // charge the customer cannot find on the invoice is a charge they
            // will phone about.
            'cash_surcharge' => (float) $order->cash_surcharge,
            'tax_rate' => $order->taxRate(),
            'tax' => $order->payableTax(),
            'pre_tax_total' => $order->preTaxTotal(),
            'total' => $order->payableTotal(),
            'paid' => $order->payment_status === 'paid',
            // «رقم المعاملة», when there is one.
            'transaction_reference' => $order->payments()->captured()->value('provider_reference'),
        ];
    }
}
