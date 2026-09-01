<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\PaymentGateway;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Paying for an order.
 *
 * Isolation as everywhere else: the order is fetched through
 * `$request->user()->orders()`, so somebody else's id is a 404.
 *
 * The webhook is the exception — it is unauthenticated by necessity, since a
 * provider has no token. Its safety comes from the driver verifying the
 * signature and from the reference having to match a payment we already created.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * «طريقة الدفع» — what the app may offer.
     */
    public function methods(): JsonResponse
    {
        $payload = [];

        foreach (PaymentMethod::cases() as $method) {
            $payload[] = [
                'value' => $method->value,
                'label' => __($method->label()),
                // Cash settles at the door; everything else redirects.
                'requires_redirect' => $method->usesGateway(),
            ];
        }

        return successReturnData($payload);
    }

    /**
     * «ادفع الآن».
     */
    public function pay(Request $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $request->validate([
            'method' => ['required', Rule::in(PaymentMethod::values())],
        ]);

        $method = PaymentMethod::from($request->get('method'));

        try {
            $payment = $this->payments->initiate($order, $request->user(), $method);
        } catch (RuntimeException $e) {
            return $this->translate($e, $order);
        }

        return successReturnCreated([
            'payment_id' => $payment->id,
            'status' => $payment->status->value,
            // «رقم المعاملة» on the confirmation screen.
            'transaction_reference' => $payment->provider_reference,
            'amount' => (float) $payment->amount,
            'redirect_url' => $payment->payload['redirect_url'] ?? ($this->redirectFor($payment)),
        ], __('Payment started.'));
    }

    /**
     * Where an attempt got to — what the app polls after the customer returns.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $payments = Payment::where('order_id', $order->id)->latest('id')->get();

        $payload = [];

        foreach ($payments as $payment) {
            $payload[] = [
                'id' => $payment->id,
                'method' => $payment->method->value,
                'method_label' => __($payment->method->label()),
                'status' => $payment->status->value,
                'status_label' => __($payment->status->label()),
                'amount' => (float) $payment->amount,
                'transaction_reference' => $payment->provider_reference,
                'paid_at' => $payment->captured_at ? humanDate($payment->captured_at) : null,
                'failure_reason' => $payment->failure_reason,
            ];
        }

        return successReturnData([
            'order_code' => $order->code,
            'amount_due' => $order->payableTotal(),
            'paid' => $order->payment_status === 'paid',
            'payment_status' => $order->payment_status,
            'captured_total' => $this->payments->capturedTotal($order),
            'attempts' => $payload,
        ]);
    }

    /**
     * The provider telling us what happened.
     *
     * Always answers 200, even for an event we do not recognise: a provider that
     * receives an error retries, and retrying forever over a message we were
     * never going to act on helps nobody.
     */
    public function webhook(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $event = $gateway->parseWebhook($request);

        if (! $event) {
            return successReturnData(['handled' => false]);
        }

        $payment = $this->payments->handleWebhook($event);

        return successReturnData([
            'handled' => $payment !== null,
            'status' => $payment?->status->value,
        ]);
    }

    private function redirectFor(Payment $payment): ?string
    {
        return $payment->payload['redirect_url'] ?? null;
    }

    private function translate(RuntimeException $e, Order $order): JsonResponse
    {
        return match ($e->getMessage()) {
            'already_paid' => failReturnMsg(__('This order has already been paid.')),
            'no_final_price' => failReturnMsg(__('The final price is not ready yet.')),
            'nothing_to_pay' => failReturnMsg(__('There is nothing to pay on this order.')),
            'cash_is_collected_on_delivery' => successReturnData([
                'payment_id' => null,
                'status' => 'cash_on_delivery',
                'amount' => $order->payableTotal(),
                'redirect_url' => null,
            ], __('The amount will be collected when your order is delivered.')),
            default => failReturnMsg(__('We could not start the payment.')),
        };
    }
}
