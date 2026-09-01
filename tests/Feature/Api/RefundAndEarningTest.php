<?php

namespace Tests\Feature\Api;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\TaskService;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\Refund;
use App\Modules\Payment\Services\EarningService;
use App\Modules\Payment\Services\RefundService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Giving money back, and paying drivers.
 *
 * Two claims. **A refund is reviewed, not granted** — the design draws
 * «قيد المراجعة» on one, so a person decides and only then does money move. And
 * **a driver's earnings are pending until the order completes**: paying for a
 * delivery that is later returned would have to be clawed back from somebody who
 * has already spent it.
 */
class RefundAndEarningTest extends TestCase
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

        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    // -------------------------------------------------------------- refunds

    #[Test]
    public function nothing_can_be_refunded_on_an_unpaid_order(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        // Refunding against money nobody ever collected would create it.
        $this->postJson("/api/v1/orders/{$order->id}/refunds",
            ['reason' => 'damaged'], $this->apiHeaders())->assertStatus(400);

        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function a_request_is_reviewed_rather_than_granted(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->customer);

        $response = $this->postJson("/api/v1/orders/{$order->id}/refunds",
            ['amount' => 20, 'reason' => 'damaged', 'note' => 'قميص اتقطع'], $this->apiHeaders());

        $response->assertCreated()->assertJsonPath('data.status', Refund::PENDING);

        $refund = Refund::firstOrFail();
        $this->assertTrue($refund->isPending());

        // «قيد المراجعة» — nothing has moved.
        $this->assertSame('0.00', app(WalletService::class)->forUser($this->customer)->balance);
        $this->assertNull($refund->settled_at);
    }

    #[Test]
    public function omitting_the_amount_asks_for_everything_refundable(): void
    {
        $order = $this->paidOrder();
        $due = $order->payableTotal();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/refunds",
            ['reason' => 'not_happy'], $this->apiHeaders())->assertCreated();

        $this->assertEquals($due, (float) Refund::firstOrFail()->amount);
    }

    #[Test]
    public function a_request_beyond_what_was_paid_is_refused(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/refunds",
            ['amount' => $order->payableTotal() + 100, 'reason' => 'x'], $this->apiHeaders())
            ->assertStatus(400);

        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function only_one_request_may_be_open_at_a_time(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/refunds",
            ['amount' => 10, 'reason' => 'a'], $this->apiHeaders())->assertCreated();

        // Two open requests would let two reviewers approve the same money twice.
        $this->postJson("/api/v1/orders/{$order->id}/refunds",
            ['amount' => 10, 'reason' => 'b'], $this->apiHeaders())->assertStatus(400);

        $this->assertSame(1, Refund::count());
    }

    #[Test]
    public function approving_to_the_wallet_credits_it_and_settles(): void
    {
        $order = $this->paidOrder();
        $refunds = app(RefundService::class);

        $refund = $refunds->request($order, $this->customer, 25, 'damaged');
        $refunds->approve($refund, $this->superAdmin(), Refund::TO_WALLET, 'agreed');

        $refund->refresh();
        $wallet = app(WalletService::class)->forUser($this->customer)->fresh();

        $this->assertSame(Refund::SETTLED, $refund->status);
        $this->assertNotNull($refund->settled_at);
        $this->assertSame('25.00', $wallet->balance);
        $this->assertTrue($wallet->isReconciled());

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'direction' => 'credit',
            'reason' => TransactionReason::Refund->value,
        ]);
    }

    #[Test]
    public function rejecting_moves_no_money(): void
    {
        $order = $this->paidOrder();
        $refunds = app(RefundService::class);

        $refund = $refunds->request($order, $this->customer, 25, 'damaged');
        $refunds->reject($refund, $this->superAdmin(), 'not our fault');

        $this->assertSame(Refund::REJECTED, $refund->fresh()->status);
        $this->assertSame('0.00', app(WalletService::class)->forUser($this->customer)->balance);
    }

    #[Test]
    public function a_decided_request_cannot_be_decided_again(): void
    {
        $order = $this->paidOrder();
        $refunds = app(RefundService::class);
        $admin = $this->superAdmin();

        $refund = $refunds->request($order, $this->customer, 25, 'damaged');
        $refunds->approve($refund, $admin, Refund::TO_WALLET);

        $this->expectException(RuntimeException::class);
        $refunds->reject($refund->fresh(), $admin);
    }

    #[Test]
    public function approved_refunds_reduce_what_is_still_refundable(): void
    {
        $order = $this->paidOrder();
        $refunds = app(RefundService::class);
        $due = $order->payableTotal();

        $refund = $refunds->request($order, $this->customer, 20, 'partial');
        $refunds->approve($refund, $this->superAdmin(), Refund::TO_WALLET);

        $this->assertEquals(round($due - 20, 2), $refunds->refundableAmount($order->fresh()));
    }

    #[Test]
    public function a_customer_cannot_request_a_refund_on_another_customers_order(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->customer('01088776655'));

        $this->postJson("/api/v1/orders/{$order->id}/refunds",
            ['amount' => 10, 'reason' => 'x'], $this->apiHeaders())->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/refunds", $this->apiHeaders())->assertNotFound();

        $this->assertSame(0, Refund::count());
    }

    // ------------------------------------------------------------- earnings

    #[Test]
    public function a_completed_leg_earns_a_share_of_the_delivery_fee(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $this->walk($order, TaskType::PickupFromCustomer, $driver, signed: true);

        $earning = DriverEarning::firstOrFail();
        $fee = (float) $order->fresh()->delivery_fee;

        // The fee split across the four legs, times the configured share.
        $this->assertEquals(round($fee / 4, 2), (float) $earning->basis);
        $this->assertEquals(round(($fee / 4) * 0.20, 2), (float) $earning->amount);
        $this->assertSame(DriverEarning::PENDING, $earning->status);

        // Pending, not spendable.
        $wallet = app(WalletService::class)->forUser($driver)->fresh();
        $this->assertEquals((float) $earning->amount, (float) $wallet->pending_balance);
        $this->assertSame('0.00', $wallet->balance);
    }

    #[Test]
    public function the_share_comes_from_a_setting_not_from_code(): void
    {
        Setting::create(['key' => 'Driver_Earning_Rate', 'value' => '35']);
        Cache::forget('setting_Driver_Earning_Rate');

        $this->assertEquals(0.35, app(EarningService::class)->rate());

        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $this->walk($order, TaskType::PickupFromCustomer, $driver, signed: true);

        $earning = DriverEarning::firstOrFail();
        $this->assertEquals(0.35, (float) $earning->rate);
        $this->assertEquals(round((float) $earning->basis * 0.35, 2), (float) $earning->amount);
    }

    #[Test]
    public function the_rate_is_stored_on_the_row_so_a_change_cannot_restate_the_past(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $this->walk($order, TaskType::PickupFromCustomer, $driver, signed: true);

        $before = DriverEarning::firstOrFail();
        $amount = (float) $before->amount;

        // The rate moves next month. Last month's earnings must not.
        Setting::create(['key' => 'Driver_Earning_Rate', 'value' => '90']);
        Cache::forget('setting_Driver_Earning_Rate');

        $this->assertEquals($amount, (float) $before->fresh()->amount);
        $this->assertEquals(0.20, (float) $before->fresh()->rate);
    }

    #[Test]
    public function a_replayed_completion_cannot_pay_twice(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $this->walk($order, TaskType::PickupFromCustomer, $driver, signed: true);

        $task = OrderTask::where('order_id', $order->id)
            ->where('type', TaskType::PickupFromCustomer->value)->firstOrFail();

        foreach (range(1, 3) as $ignored) {
            app(EarningService::class)->recordFor($task->fresh());
        }

        $this->assertSame(1, DriverEarning::count());
    }

    #[Test]
    public function earnings_become_spendable_only_when_the_order_completes(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->deliveredOrder($driver);

        $pendingBefore = (float) app(WalletService::class)->forUser($driver)->fresh()->pending_balance;
        $this->assertGreaterThan(0, $pendingBefore);
        $this->assertSame('0.00', app(WalletService::class)->forUser($driver)->fresh()->balance);

        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::Completed, 'admin');

        $wallet = app(WalletService::class)->forUser($driver)->fresh();

        // By now the money has arrived, so it is safe to hand over.
        $this->assertSame('0.00', $wallet->pending_balance);
        $this->assertEquals($pendingBefore, (float) $wallet->balance);
        $this->assertTrue($wallet->isReconciled());

        $this->assertSame(0, DriverEarning::pending()->count());
        $this->assertSame(4, DriverEarning::released()->count());
    }

    #[Test]
    public function a_cancelled_order_pays_nothing(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $this->walk($order, TaskType::PickupFromCustomer, $driver, signed: true);
        $this->assertGreaterThan(0, (float) app(WalletService::class)->forUser($driver)->fresh()->pending_balance);

        // Reached from picked_up via the only legal route to a dead end.
        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::Reviewed, 'laundry');
        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::Returned, 'admin');

        $wallet = app(WalletService::class)->forUser($driver)->fresh();

        $this->assertSame('0.00', $wallet->pending_balance);
        $this->assertSame('0.00', $wallet->balance);
        $this->assertSame(1, DriverEarning::where('status', DriverEarning::CANCELLED)->count());
    }

    #[Test]
    public function the_driver_sees_their_earnings_with_the_sum_behind_each(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $this->walk($order, TaskType::PickupFromCustomer, $driver, signed: true);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/v1/driver/earnings', $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.released', 0);
        $this->assertGreaterThan(0, $response->json('data.pending'));
        $this->assertCount(1, $response->json('data.recent'));

        // A driver asking why a job paid what it did has to be shown the sum.
        $this->assertNotEmpty($response->json('data.recent.0.calculation'));
    }

    #[Test]
    public function a_customer_token_cannot_read_driver_earnings(): void
    {
        Sanctum::actingAs($this->customer);

        $this->getJson('/api/v1/driver/earnings', $this->apiHeaders())->assertForbidden();
    }

    // ------------------------------------------------------------------ helpers

    private function eligibleDriver(): Driver
    {
        return $this->driverUser('01044440001', zoneIds: [$this->geo['zones'][0]->id]);
    }

    private function placedOrder(): Order
    {
        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    private function reviewedOrder(): Order
    {
        $order = $this->placedOrder();
        $machine = app(OrderStateMachine::class);

        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $reviews = app(OrderReviewService::class);
        $reviews->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);

        return $reviews->confirm($order->fresh(), $this->customer)->fresh();
    }

    /** Paid in cash at the door, which is the simplest way to have money to refund. */
    private function paidOrder(): Order
    {
        $order = $this->reviewedOrder();
        $order->update(['payment_status' => 'paid', 'paid_at' => now(), 'payment_method' => 'cash']);

        return $order->fresh();
    }

    /** Walked through all four legs, but not yet completed. */
    private function deliveredOrder(Driver $driver): Order
    {
        $order = $this->placedOrder();

        $this->walk($order, TaskType::PickupFromCustomer, $driver, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, $driver);

        $reviews = app(OrderReviewService::class);
        $reviews->review($order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);
        $reviews->confirm($order->fresh(), $this->customer);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');

        $this->walk($order->fresh(), TaskType::CollectFromLaundry, $driver);
        $this->walk($order->fresh(), TaskType::DeliverToCustomer, $driver, signed: true);

        return $order->fresh();
    }

    private function walk(Order $order, TaskType $type, Driver $driver, bool $signed = false): void
    {
        $task = OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();

        if ($task->driver_id === null) {
            app(DriverDispatcher::class)->assign($task, $driver);
            $task->refresh();
        }

        app(TaskService::class)->start($task->fresh(), $driver);

        app(TaskService::class)->complete(
            $task->fresh(),
            $driver,
            $type->countsPieces() ? ['piece_count' => 2] : [],
            [],
            $signed ? UploadedFile::fake()->image('sig.png') : null,
        );
    }
}
