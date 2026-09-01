<?php

namespace App\Modules\Payment\Data;

/**
 * A provider telling us something happened.
 *
 * Deliberately narrow: captured, failed, or refunded. Providers send a great deal
 * more than that, and mapping their vocabulary into these three is the driver's
 * job — so the service that acts on events never has to know one provider's
 * `TRANSACTION.AUTHORIZED` from another's `charge.succeeded`.
 */
class WebhookEvent
{
    public const CAPTURED = 'captured';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    public function __construct(
        public readonly string $type,
        public readonly string $providerReference,
        public readonly ?float $amount = null,
        public readonly ?string $reason = null,
        public readonly array $payload = [],
    ) {}

    public function isCapture(): bool
    {
        return $this->type === self::CAPTURED;
    }
}
