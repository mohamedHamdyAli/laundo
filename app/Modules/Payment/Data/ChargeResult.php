<?php

namespace App\Modules\Payment\Data;

/**
 * What came back from starting a charge.
 *
 * `redirectUrl` is null for a provider that settles inline — a wallet debit or a
 * test double — and present for a hosted checkout. `settledImmediately` is the
 * flag that says which: it is the difference between "send the customer away and
 * wait for a webhook" and "this is already done".
 */
class ChargeResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly bool $settledImmediately = false,
        public readonly ?string $failureReason = null,
        public readonly array $payload = [],
    ) {}

    public static function failed(string $reason): self
    {
        return new self(accepted: false, failureReason: $reason);
    }
}
