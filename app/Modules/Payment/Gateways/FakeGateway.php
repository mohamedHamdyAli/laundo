<?php

namespace App\Modules\Payment\Gateways;

use App\Modules\Payment\Contracts\PaymentGateway;
use App\Modules\Payment\Data\ChargeRequest;
use App\Modules\Payment\Data\ChargeResult;
use App\Modules\Payment\Data\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A provider that does nothing, correctly.
 *
 * It exists so every path in P9a — initiate, capture by webhook, fail, replay —
 * is exercisable before a real provider has been chosen, and so tests never
 * depend on somebody's sandbox being up.
 *
 * The one thing it deliberately does NOT do is settle immediately. A fake that
 * returned "paid" from `charge()` would let the whole application be built around
 * a flow no real provider offers, and the first integration would rewrite it.
 * This one hands back a reference and waits to be told, exactly as a hosted
 * checkout does.
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        if ($request->amount <= 0) {
            return ChargeResult::failed('amount_must_be_positive');
        }

        $reference = 'FAKE-'.strtoupper(Str::random(10));

        return new ChargeResult(
            accepted: true,
            providerReference: $reference,
            // Where a real provider would send the customer.
            redirectUrl: url("/payment/fake/{$reference}"),
            settledImmediately: false,
            payload: ['reference' => $request->reference(), 'minor_units' => $request->amountInMinorUnits()],
        );
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        $reference = $request->input('reference');

        if (! $reference) {
            return null;
        }

        $type = match ($request->input('event')) {
            'captured', 'success' => WebhookEvent::CAPTURED,
            'refunded' => WebhookEvent::REFUNDED,
            default => WebhookEvent::FAILED,
        };

        return new WebhookEvent(
            type: $type,
            providerReference: $reference,
            amount: $request->has('amount') ? (float) $request->input('amount') : null,
            reason: $request->input('reason'),
            payload: $request->all(),
        );
    }

    public function refund(string $providerReference, float $amount): bool
    {
        return $amount > 0;
    }
}
