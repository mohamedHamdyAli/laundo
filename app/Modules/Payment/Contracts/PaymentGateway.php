<?php

namespace App\Modules\Payment\Contracts;

use App\Modules\Payment\Data\ChargeRequest;
use App\Modules\Payment\Data\ChargeResult;
use App\Modules\Payment\Data\WebhookEvent;
use Illuminate\Http\Request;

/**
 * What every payment provider has to be able to do.
 *
 * Four methods, and the domain talks to nothing else. Choosing Paymob or Fawry or
 * Kashier later is then a new class implementing this, not a change to how orders
 * are paid — the same reason pricing lives outside controllers.
 *
 * Note what is absent: no method returns "paid". A gateway can only report what it
 * was told; deciding that an order is settled is PaymentService's job, and it does
 * it from a webhook.
 */
interface PaymentGateway
{
    /**
     * The provider's name, as stored on the payment row.
     */
    public function name(): string;

    /**
     * Begin a charge.
     *
     * Returns where to send the customer and what the provider is calling this
     * attempt. It does NOT return success: a redirect the customer has not
     * followed yet has settled nothing.
     */
    public function charge(ChargeRequest $request): ChargeResult;

    /**
     * Turn an incoming request into an event, or null if it is not for us.
     *
     * Verification of the signature happens here, inside the driver, because the
     * scheme is the provider's business and nothing above should know it.
     */
    public function parseWebhook(Request $request): ?WebhookEvent;

    /**
     * Send money back. P9b uses it; declared here so the contract is complete and
     * a provider is implemented once.
     */
    public function refund(string $providerReference, float $amount): bool;
}
