<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\TaskService;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paying for an order.
 *
 * The claim these tests exist to protect: **only the webhook captures.** Not the
 * redirect the customer returns through — they can close that tab — and not the
 * gateway's optimistic reply to `charge()`, which only means the request was
 * accepted. And because providers retry, receiving the same webhook twice must
 * settle the order exactly once.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private array $tenant;

    private User $customer;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    // ------------------------------------------------------------- initiating

    #[Test]
    public function starting_a_card_payment_creates_an_attempt_and_settles_nothing(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        $response = $this->postJson("/api/v1/orders/{$order->id}/pay",
            ['method' => 'card'], $this->apiHeaders());

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertNotNull($response->json('data.transaction_reference'));

        $payment = Payment::firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        // The redirect has not been followed. Nothing is paid.
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    #[Test]
    public function an_order_without_a_final_price_cannot_be_paid(): void
    {
        $order = $this->collectedOrder();
        $this->assertFalse($order->hasFinalPrice());

        Sanctum::actingAs($this->customer);

        // Paying an estimate would bind the customer to a figure the laundry has
        // not agreed to either.
        $this->postJson("/api/v1/orders/{$order->id}/pay",
            ['method' => 'card'], $this->apiHeaders())->assertStatus(400);

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function choosing_cash_records_the_method_and_charges_nothing(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        $response = $this->postJson("/api/v1/orders/{$order->id}/pay",
            ['method' => 'cash'], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.status', 'cash_on_delivery');

        // A choice, not a transaction.
        $this->assertSame(0, Payment::count());
        $this->assertSame('cash', $order->fresh()->payment_method);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    #[Test]
    public function a_second_attempt_abandons_the_first(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders());
        $first = Payment::firstOrFail();

        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'instapay'], $this->apiHeaders());

        // Two live references for one order would make whichever webhook landed
        // second look like a duplicate payment.
        $this->assertSame(PaymentStatus::Failed, $first->fresh()->status);
        $this->assertSame('superseded_by_a_new_attempt', $first->fresh()->failure_reason);
        $this->assertSame(2, Payment::count());
        $this->assertSame(1, Payment::open()->count());
    }

    #[Test]
    public function a_retry_after_a_failed_wallet_payment_is_not_a_key_collision(): void
    {
        $order = $this->reviewedOrder();
        $wallets = app(WalletService::class);

        Sanctum::actingAs($this->customer);

        // First attempt: nothing in the wallet.
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'wallet'], $this->apiHeaders());
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        $wallets->credit(
            $this->customer,
            $order->payableTotal(),
            TransactionReason::TopUp
        );

        // The retry lands in the same second. A second-resolution reference would
        // collide on the unique key — a constraint violation where the customer
        // should have seen a second chance.
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'wallet'], $this->apiHeaders())
            ->assertCreated();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(2, Payment::where('order_id', $order->id)->count());

        $wallet = $wallets->forUser($this->customer)->fresh();
        $this->assertSame('0.00', $wallet->balance);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function paying_from_an_empty_wallet_fails_without_moving_money(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        // An insufficient balance is a normal outcome, not a crash.
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'wallet'], $this->apiHeaders())
            ->assertCreated();

        $payment = Payment::firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('insufficient_balance', $payment->failure_reason);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    // ---------------------------------------------------------- the webhook

    #[Test]
    public function only_the_webhook_captures(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders());

        $payment = Payment::firstOrFail();
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        // The provider speaks. Unauthenticated by necessity.
        $this->postJson('/api/v1/payments/webhook/fake', [
            'reference' => $payment->provider_reference,
            'event' => 'captured',
            'amount' => (float) $payment->amount,
        ], $this->apiHeaders())->assertOk()->assertJsonPath('data.handled', true);

        $payment->refresh();
        $order->refresh();

        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertNotNull($payment->captured_at);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('card', $order->payment_method);
    }

    #[Test]
    public function a_replayed_webhook_captures_once(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders());
        $payment = Payment::firstOrFail();

        $body = [
            'reference' => $payment->provider_reference,
            'event' => 'captured',
            'amount' => (float) $payment->amount,
        ];

        // Providers retry. Three times must settle exactly once.
        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/v1/payments/webhook/fake', $body, $this->apiHeaders())->assertOk();
        }

        $this->assertSame(1, Payment::captured()->count());
        $this->assertSame(
            (float) $payment->amount,
            app(PaymentService::class)->capturedTotal($order->fresh())
        );

        $paidAt = $order->fresh()->paid_at;
        $this->postJson('/api/v1/payments/webhook/fake', $body, $this->apiHeaders())->assertOk();
        $this->assertEquals($paidAt, $order->fresh()->paid_at);
    }

    #[Test]
    public function an_unknown_reference_is_ignored_rather_than_guessed_at(): void
    {
        $this->postJson('/api/v1/payments/webhook/fake', [
            'reference' => 'FAKE-NOTOURS',
            'event' => 'captured',
        ], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.handled', false);
    }

    #[Test]
    public function a_malformed_webhook_answers_200_rather_than_making_a_provider_retry_forever(): void
    {
        $this->postJson('/api/v1/payments/webhook/fake', ['nothing' => 'useful'], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.handled', false);
    }

    #[Test]
    public function a_failure_webhook_leaves_the_order_unpaid(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders());
        $payment = Payment::firstOrFail();

        $this->postJson('/api/v1/payments/webhook/fake', [
            'reference' => $payment->provider_reference,
            'event' => 'failed',
            'reason' => 'insufficient_funds',
        ], $this->apiHeaders())->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame('insufficient_funds', $payment->fresh()->failure_reason);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    #[Test]
    public function a_late_failure_cannot_un_capture_money(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders());
        $payment = Payment::firstOrFail();

        $this->postJson('/api/v1/payments/webhook/fake', [
            'reference' => $payment->provider_reference, 'event' => 'captured',
        ], $this->apiHeaders())->assertOk();

        // A reversal is a refund, and refunds are P9b.
        $this->postJson('/api/v1/payments/webhook/fake', [
            'reference' => $payment->provider_reference, 'event' => 'failed', 'reason' => 'late',
        ], $this->apiHeaders())->assertOk();

        $this->assertSame(PaymentStatus::Captured, $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    #[Test]
    public function paying_an_already_paid_order_is_refused(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders());
        $payment = Payment::firstOrFail();

        $this->postJson('/api/v1/payments/webhook/fake', [
            'reference' => $payment->provider_reference, 'event' => 'captured',
        ], $this->apiHeaders())->assertOk();

        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders())
            ->assertStatus(400);

        $this->assertSame(1, Payment::count());
    }

    // ---------------------------------------------------- cash still works

    #[Test]
    public function cash_at_the_door_still_settles_without_any_payment_row(): void
    {
        $driver = $this->driverUser('+201044440001', zoneIds: [$this->geo['zones'][0]->id]);
        $order = $this->readyForDelivery($driver);

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'cash'], $this->apiHeaders())
            ->assertOk();

        $task = OrderTask::where('order_id', $order->id)
            ->where('type', TaskType::DeliverToCustomer->value)->firstOrFail();

        app(DriverDispatcher::class)->assign($task, $driver);
        app(TaskService::class)->start($task->fresh(), $driver);

        app(TaskService::class)->complete(
            $task->fresh(),
            $driver,
            ['collected_amount' => $order->fresh()->payableTotal()],
            [],
            UploadedFile::fake()->image('sig.png'),
        );

        $order->refresh();

        // P8's rule holds untouched: paid at the door, and no gateway involved.
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(0, Payment::count());
    }

    // ----------------------------------------------------------- isolation

    #[Test]
    public function a_customer_cannot_pay_or_read_another_customers_order(): void
    {
        $order = $this->reviewedOrder();

        $stranger = $this->customer('+201088776655');
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders())
            ->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/payments", $this->apiHeaders())
            ->assertNotFound();

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function paying_requires_a_token(): void
    {
        $order = $this->reviewedOrder();

        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders())
            ->assertUnauthorized();
        $this->getJson('/api/v1/payment-methods', $this->apiHeaders())->assertUnauthorized();
    }

    #[Test]
    public function the_methods_list_matches_the_design(): void
    {
        Sanctum::actingAs($this->customer);

        $response = $this->getJson('/api/v1/payment-methods', $this->apiHeaders());

        $response->assertOk()->assertJsonCount(4, 'data');

        $methods = collect($response->json('data'));

        $this->assertEqualsCanonicalizing(
            ['card', 'wallet', 'instapay', 'cash'],
            $methods->pluck('value')->all()
        );

        // Cash settles at the door; everything else sends the customer away.
        $this->assertFalse($methods->firstWhere('value', 'cash')['requires_redirect']);
        $this->assertTrue($methods->firstWhere('value', 'card')['requires_redirect']);
    }

    #[Test]
    public function the_payments_view_reports_what_actually_captured(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/pay", ['method' => 'card'], $this->apiHeaders());
        $payment = Payment::firstOrFail();

        $this->postJson('/api/v1/payments/webhook/fake', [
            'reference' => $payment->provider_reference, 'event' => 'captured',
        ], $this->apiHeaders())->assertOk();

        $response = $this->getJson("/api/v1/orders/{$order->id}/payments", $this->apiHeaders());

        $response->assertOk()
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('data.attempts.0.status', 'captured');

        $this->assertEquals($order->fresh()->payableTotal(), $response->json('data.captured_total'));
    }

    // ------------------------------------------------------------------ helpers

    private function placedOrder(): Order
    {
        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    private function collectedOrder(): Order
    {
        $order = $this->placedOrder();
        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');

        return $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');
    }

    /** Priced by the laundry and confirmed by the customer — the point it is payable. */
    private function reviewedOrder(): Order
    {
        $order = $this->collectedOrder();

        $reviews = app(OrderReviewService::class);
        $reviews->review(
            $order,
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            null,
            $this->tenant['owner']
        );

        return $reviews->confirm($order->fresh(), $this->customer)->fresh();
    }

    private function readyForDelivery($driver): Order
    {
        $order = $this->reviewedOrder();
        $machine = app(OrderStateMachine::class);

        $machine->transition($order, OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');

        // The three legs before the delivery.
        foreach ([
            TaskType::PickupFromCustomer,
            TaskType::DeliverToLaundry,
            TaskType::CollectFromLaundry,
        ] as $type) {
            $task = OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();

            if ($task->driver_id === null) {
                app(DriverDispatcher::class)->assign($task, $driver);
            }

            app(TaskService::class)->start($task->fresh(), $driver);
            app(TaskService::class)->complete(
                $task->fresh(),
                $driver,
                ['piece_count' => 2],
                [],
                $type->requiresSignature() ? UploadedFile::fake()->image('s.png') : null,
            );
        }

        return $order->fresh();
    }
}
