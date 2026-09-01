<?php

namespace App\Modules\Payment\Data;

use App\Modules\Order\Models\Order;

/**
 * What a provider needs in order to take money.
 *
 * A plain object rather than an array so a driver cannot be handed a payload with
 * a typo'd key and fail at the provider instead of at the call site.
 */
class ChargeRequest
{
    public function __construct(
        public readonly Order $order,
        public readonly float $amount,
        public readonly string $method,
        public readonly string $currency = 'EGP',
        public readonly ?string $returnUrl = null,
        public readonly ?string $callbackUrl = null,
    ) {}

    /**
     * The customer-visible reference. The order's code rather than its id, since
     * that is what appears on the invoice and what a customer quotes to support.
     */
    public function reference(): string
    {
        return $this->order->code;
    }

    public function amountInMinorUnits(): int
    {
        // Most Egyptian providers price in piastres. Rounded once, here, so no
        // driver has to remember to.
        return (int) round($this->amount * 100);
    }
}
