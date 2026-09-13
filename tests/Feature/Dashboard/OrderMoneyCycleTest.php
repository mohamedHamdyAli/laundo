<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Driver\Enums\BonusBasis;
use App\Modules\Driver\Models\DriverBonusRule;
use App\Modules\Driver\Models\DriverProfile;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\TaskService;
use App\Modules\Payment\Models\CommissionRule;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Services\EarningService;
use App\Modules\Payment\Services\SettlementService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use App\Support\PlatformAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One order, from placement to every wallet it touches.
 *
 * Every other money test in this suite proves one mechanism in isolation: the
 * tax, the commission split, the driver's bonus. This one walks a single order
 * through all four legs and asks the question none of them can — **do the
 * pieces agree with each other on the same order?**
 *
 * Four sums have to hold at once when it is over:
 *
 *   1. the customer's total  = subtotal + delivery − discount + surcharge + tax
 *   2. the settlement basis  = that total, less the tax
 *   3. commission + laundry share = the basis, to the piastre
 *   4. every wallet reconciles against its own ledger
 *
 * The second half walks the paths where money must **not** move. A cancelled
 * order, a returned one and an unassigned one are where a leak would actually
 * live: nobody checks the wallet of an order that failed.
 */
class OrderMoneyCycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
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

        $this->customer = $this->customer('+201099887799');
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        // The platform's own account has to exist before anything settles, or
        // the settlement is left pending by design.
        $this->superAdmin();
    }

    private function setting(string $key, ?string $value): void
    {
        if ($value === null) {
            Setting::where('key', $key)->delete();
        } else {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // getSettingValue caches for ever.
        Cache::flush();
    }

    private function charge(string $basis, float $value, string $name): CommissionRule
    {
        $rule = CommissionRule::create([
            'name' => json_encode(['en' => $name, 'ar' => 'رسوم'], JSON_UNESCAPED_UNICODE),
            'basis' => $basis,
            'rate' => $basis === 'percent' ? $value : null,
            'amount' => $basis === 'fixed' ? $value : null,
            'status' => 'active',
        ]);

        $this->tenant['laundry']->commissionRules()->syncWithoutDetaching([$rule->id]);

        return $rule;
    }

    /**
     * A driver on bonus terms.
     *
     * The basis matters to the unhappy paths: a `per_order` rule pays only on
     * the final handover, so an order cancelled or returned earlier would have
     * earned nothing and «nobody was paid» would pass without proving anything.
     * Those tests use `per_task`, which earns on the first leg.
     */
    private function driverOnBonus(BonusBasis $basis = BonusBasis::PerOrder): User
    {
        $driver = $this->driverUser('+201033330099', zoneIds: [$this->geo['zones'][0]->id]);

        $rule = DriverBonusRule::create([
            'name' => json_encode(['en' => 'Bonus', 'ar' => 'بونس'], JSON_UNESCAPED_UNICODE),
            'basis' => $basis->value,
            'amount' => 15,
            'status' => 'active',
        ]);

        DriverProfile::where('user_id', $driver->id)->update(['bonus_rule_id' => $rule->id]);

        return $driver;
    }

    private function place(?string $method = 'cash'): Order
    {
        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 3]],
            'payment_method' => $method,
            'accepts_review_terms' => true,
        ]);
    }

    /**
     * Drive one leg the way the driver app does.
     *
     * The leg type decides what `TaskService::complete()` demands: a handover to
     * a person is proved by that person's signature, and the two legs that cross
     * the laundry's door have to count the pieces. Asking the enum rather than
     * hardcoding the pairs keeps this honest when a leg's requirements change.
     */
    private function walk(Order $order, TaskType $type, User $driver): void
    {
        $task = OrderTask::withoutGlobalScopes()
            ->where('order_id', $order->id)->where('type', $type->value)->firstOrFail();

        if ($task->driver_id === null) {
            app(DriverDispatcher::class)->assign($task->fresh(), $driver);
        }

        $tasks = app(TaskService::class);
        $tasks->start($task->fresh(), $driver);

        $tasks->complete(
            $task->fresh(),
            $driver,
            $type->countsPieces() ? ['piece_count' => 3] : [],
            [],
            $type->requiresSignature() ? UploadedFile::fake()->image('sig.png') : null,
        );
    }

    // ---------------------------------------------------------------- the cycle

    #[Test]
    public function one_order_walks_from_placement_to_every_wallet(): void
    {
        $this->setting('Tax', '14');
        $this->setting('Cash_Surcharge', '10');
        $this->setting('Commission_Rate', '0');

        // Two charges that stack: 10% of the order plus a flat 5.
        $this->charge('percent', 10, 'Base commission');
        $this->charge('fixed', 5, 'Platform fee');

        $driver = $this->driverOnBonus();
        $platform = PlatformAccount::user();
        $owner = $this->tenant['owner'];

        // ---------------------------------------------------------- placed
        $order = $this->place('cash');

        $this->assertEquals(10.0, (float) $order->cash_surcharge);
        $this->assertEquals(14.0, $order->taxRate());

        // 1. The customer's total is the sum of its own lines.
        $this->assertEquals(
            round((float) $order->estimated_subtotal + (float) $order->delivery_fee
                - (float) $order->discount_total + (float) $order->cash_surcharge
                + (float) $order->estimated_tax, 2),
            (float) $order->estimated_total
        );

        // ------------------------------------------------ picked up and priced
        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $this->walk($order, TaskType::PickupFromCustomer, $driver);
        $this->walk($order, TaskType::DeliverToLaundry, $driver);

        $reviews = app(OrderReviewService::class);
        $reviews->review(
            $order->fresh(),
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 4]],
            null,
            $owner,
        );

        $order = $reviews->confirm($order->fresh(), $this->customer)->fresh();

        // The final total obeys the same arithmetic as the estimate, surcharge
        // and tax included — the bug that used to drop both on review.
        $this->assertEquals(
            round((float) $order->final_subtotal + (float) $order->delivery_fee
                - (float) $order->discount_total + (float) $order->cash_surcharge
                + (float) $order->final_tax, 2),
            (float) $order->final_total
        );

        // Confirming records the split without moving anything.
        $settlement = OrderSettlement::withoutGlobalScope('laundry')
            ->where('order_id', $order->id)->firstOrFail();

        $this->assertSame(OrderSettlement::PENDING, $settlement->status);
        $this->assertSame('0.00', app(WalletService::class)->forUser($platform)->balance);
        $this->assertSame('0.00', app(WalletService::class)->forUser($owner)->balance);

        // ------------------------------------------------------- cleaned, out
        $machine->transition($order->fresh(), OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');
        $this->walk($order->fresh(), TaskType::CollectFromLaundry, $driver);
        $this->walk($order->fresh(), TaskType::DeliverToCustomer, $driver);

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);

        // The driver's bonus is earned and held.
        $this->assertGreaterThan(0.0, (float) app(WalletService::class)->forUser($driver)->fresh()->pending_balance);
        $this->assertSame('0.00', app(WalletService::class)->forUser($driver)->fresh()->balance);

        // ----------------------------------------------------------- completed
        $machine->transition($order->fresh(), OrderStatus::Completed, 'admin');

        $settlement = $settlement->fresh()->load('lines');
        $wallets = app(WalletService::class);

        // 2. The basis is the washing after discount — NOT the whole order.
        //    The delivery fee and the cash handling fee stay with the platform,
        //    which is what pays the driver and what pays to handle notes.
        $fresh = $order->fresh();

        $this->assertEquals(
            round((float) $fresh->final_subtotal - (float) $fresh->discount_total, 2),
            (float) $settlement->basis
        );
        $this->assertEquals((float) $fresh->final_tax, (float) $settlement->tax_amount);

        // Explicitly: neither is in the basis.
        $this->assertGreaterThan(0.0, (float) $fresh->delivery_fee);
        $this->assertGreaterThan(0.0, (float) $fresh->cash_surcharge);
        $this->assertLessThan(
            round((float) $fresh->final_total - (float) $fresh->final_tax, 2),
            (float) $settlement->basis
        );

        // 3. The two halves add back to the basis, and the lines add back to the
        //    commission they explain.
        $this->assertTrue($settlement->reconciles());
        $this->assertTrue($settlement->linesReconcile());
        $this->assertCount(2, $settlement->lines);

        // The stacked charges came to what they should: 10% of the basis, plus 5.
        $this->assertEquals(
            round((float) $settlement->basis * 0.10 + 5, 2),
            (float) $settlement->commission_amount
        );

        // 4. Every wallet holds what its ledger says, and holds the right sum.
        $platformWallet = $wallets->forUser($platform)->fresh();
        $ownerWallet = $wallets->forUser($owner)->fresh();
        $driverWallet = $wallets->forUser($driver)->fresh();

        $this->assertEquals((float) $settlement->commission_amount, (float) $platformWallet->balance);
        $this->assertEquals((float) $settlement->laundry_amount, (float) $ownerWallet->balance);
        $this->assertEquals(15.0, (float) $driverWallet->balance);   // one per-order bonus
        $this->assertSame('0.00', $driverWallet->pending_balance);

        foreach ([$platformWallet, $ownerWallet, $driverWallet] as $wallet) {
            $this->assertTrue($wallet->isReconciled(), 'a wallet drifted from its own ledger');
        }

        // Each side has a row naming the order, so nothing moved unexplained.
        foreach ([
            [$platformWallet->id, TransactionReason::Commission],
            [$ownerWallet->id, TransactionReason::LaundryPayout],
            [$driverWallet->id, TransactionReason::Earning],
        ] as [$walletId, $reason]) {
            $transaction = WalletTransaction::where('wallet_id', $walletId)
                ->where('reason', $reason->value)->first();

            $this->assertNotNull($transaction, "no {$reason->value} transaction");
            $this->assertStringContainsString($order->code, (string) $transaction->note);
        }
    }

    #[Test]
    public function the_whole_order_is_accounted_for_and_nothing_is_invented(): void
    {
        $this->setting('Tax', '14');
        $this->setting('Cash_Surcharge', '0');
        $this->setting('Commission_Rate', '0');
        $this->charge('percent', 20, 'Base');

        $driver = $this->driverOnBonus();
        $order = $this->completedOrder($driver);

        $settlement = OrderSettlement::withoutGlobalScope('laundry')
            ->where('order_id', $order->id)->firstOrFail();

        $wallets = app(WalletService::class);
        $platform = (float) $wallets->forUser(PlatformAccount::user())->fresh()->balance;
        $laundry = (float) $wallets->forUser($this->tenant['owner'])->fresh()->balance;

        // Everything the customer paid is accounted for, and nothing invented.
        // Five parts, and only two of them ever reach a wallet:
        //
        //     total = tax + delivery + cash surcharge + commission + laundry share
        //
        // The tax is held for the state, and the delivery and surcharge are
        // simply not paid out — the platform keeps them because it is the
        // platform that pays the driver and pays to handle notes.
        $fresh = $order->fresh();

        $paid = (float) $fresh->final_total;
        $tax = (float) $fresh->final_tax;
        $delivery = (float) $fresh->delivery_fee;
        $surcharge = (float) $fresh->cash_surcharge;

        $this->assertEquals(
            $paid,
            round($tax + $delivery + $surcharge + $platform + $laundry, 2),
            'the order does not add up'
        );

        // And the driver's bonus comes out of the platform's side, not out of
        // thin air — it is a cost, so it is NOT part of the sum above.
        $this->assertEquals(15.0, (float) $wallets->forUser($driver)->fresh()->balance);
        $this->assertEquals((float) $settlement->commission_amount, $platform);
    }

    // -------------------------------------------------------- the unhappy paths

    #[Test]
    public function a_cancelled_order_pays_nobody(): void
    {
        $this->setting('Commission_Rate', '10');
        $driver = $this->driverOnBonus();

        // `driver_on_way` is «the last point a customer may still pull out» —
        // the state machine refuses a cancellation after the pieces have been
        // collected, so this is the only shape a real cancellation takes.
        $order = $this->place();
        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $machine->transition($order->fresh(), OrderStatus::Cancelled, 'admin');

        $this->assertNobodyWasPaid($driver);

        // Nothing was ever recorded to divide, and nothing paid out.
        $settlement = OrderSettlement::withoutGlobalScope('laundry')
            ->where('order_id', $order->id)->first();

        $this->assertTrue(
            $settlement === null || $settlement->status === OrderSettlement::CANCELLED,
            'a cancelled order left a live settlement'
        );
    }

    #[Test]
    public function cancelling_after_the_pieces_are_collected_is_refused(): void
    {
        $driver = $this->driverOnBonus(BonusBasis::PerTask);

        $order = $this->place();
        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $this->walk($order, TaskType::PickupFromCustomer, $driver);

        // The clothes are in a van. Cancelling here would leave somebody holding
        // them with no order to return them against — the way out is Returned,
        // which has its own accounting.
        $this->expectException(\RuntimeException::class);
        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::Cancelled, 'admin');
    }

    #[Test]
    public function a_returned_order_pays_nobody(): void
    {
        $this->setting('Commission_Rate', '10');

        // `per_task`, so the pickup leg actually earns something before the
        // order goes wrong. On `per_order` terms there would be nothing held and
        // the assertion would pass without proving anything.
        $driver = $this->driverOnBonus(BonusBasis::PerTask);

        $order = $this->place();
        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $this->walk($order, TaskType::PickupFromCustomer, $driver);

        // Held, not spendable — and this is what has to evaporate.
        $this->assertGreaterThan(
            0.0,
            (float) app(WalletService::class)->forUser($driver)->fresh()->pending_balance
        );

        app(OrderReviewService::class)->review(
            $order->fresh(),
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 3]],
            null,
            $this->tenant['owner'],
        );
        $machine->transition($order->fresh(), OrderStatus::Returned, 'admin');

        // A driver's pending bonus evaporates rather than being paid for work
        // the customer did not keep.
        $this->assertNobodyWasPaid($driver);
        $this->assertSame(1, DriverEarning::where('status', DriverEarning::CANCELLED)->count());
    }

    #[Test]
    public function an_order_nobody_was_assigned_to_leaves_the_settlement_pending(): void
    {
        $this->setting('Commission_Rate', '10');

        // No laundry covers the zone, so the order lands unassigned — accepted
        // by decision rather than refused at the door.
        $this->tenant['laundry']->zones()->delete();

        $order = $this->place();
        $this->assertNull($order->laundry_id);

        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');

        $settlement = app(SettlementService::class)
            ->settleFor($order->fresh());

        // Nothing to pay a laundry that does not exist — and it stays visible as
        // pending rather than being silently dropped.
        $this->assertSame(OrderSettlement::PENDING, $settlement->status);
        $this->assertSame('0.00', app(WalletService::class)->forUser($this->tenant['owner'])->balance);
    }

    #[Test]
    public function replaying_the_completion_pays_nobody_twice(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->charge('percent', 10, 'Base');

        $driver = $this->driverOnBonus();
        $order = $this->completedOrder($driver);

        $wallets = app(WalletService::class);
        $before = [
            (float) $wallets->forUser(PlatformAccount::user())->fresh()->balance,
            (float) $wallets->forUser($this->tenant['owner'])->fresh()->balance,
            (float) $wallets->forUser($driver)->fresh()->balance,
        ];

        $settlements = app(SettlementService::class);
        $earnings = app(EarningService::class);

        foreach (range(1, 3) as $ignored) {
            $settlements->settleFor($order->fresh());
            $earnings->releaseFor($order->fresh());
        }

        $this->assertSame($before, [
            (float) $wallets->forUser(PlatformAccount::user())->fresh()->balance,
            (float) $wallets->forUser($this->tenant['owner'])->fresh()->balance,
            (float) $wallets->forUser($driver)->fresh()->balance,
        ]);
    }

    #[Test]
    public function a_zero_commission_still_pays_the_laundry_everything(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->charge('percent', 0, 'Free of charge');

        $driver = $this->driverOnBonus();
        $order = $this->completedOrder($driver);

        $settlement = OrderSettlement::withoutGlobalScope('laundry')
            ->where('order_id', $order->id)->firstOrFail()->load('lines');

        $this->assertEquals(0.0, (float) $settlement->commission_amount);
        $this->assertEquals((float) $settlement->basis, (float) $settlement->laundry_amount);
        // A charge of nothing writes no line: a settlement row reading «EGP 0.00»
        // explains nothing.
        $this->assertCount(0, $settlement->lines);
        $this->assertTrue($settlement->linesReconcile());

        $this->assertSame('0.00', app(WalletService::class)->forUser(PlatformAccount::user())->fresh()->balance);
        $this->assertEquals(
            (float) $settlement->laundry_amount,
            (float) app(WalletService::class)->forUser($this->tenant['owner'])->fresh()->balance
        );
    }

    // ------------------------------------------------------------------ helpers

    /** Walk an order all the way to completed. */
    private function completedOrder(User $driver): Order
    {
        $order = $this->place('cash');
        $machine = app(OrderStateMachine::class);

        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $this->walk($order, TaskType::PickupFromCustomer, $driver);
        $this->walk($order, TaskType::DeliverToLaundry, $driver);

        $reviews = app(OrderReviewService::class);
        $reviews->review(
            $order->fresh(),
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 3]],
            null,
            $this->tenant['owner'],
        );
        $reviews->confirm($order->fresh(), $this->customer);

        $machine->transition($order->fresh(), OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');
        $this->walk($order->fresh(), TaskType::CollectFromLaundry, $driver);
        $this->walk($order->fresh(), TaskType::DeliverToCustomer, $driver);
        $machine->transition($order->fresh(), OrderStatus::Completed, 'admin');

        return $order->fresh();
    }

    private function assertNobodyWasPaid(User $driver): void
    {
        $wallets = app(WalletService::class);

        foreach ([PlatformAccount::user(), $this->tenant['owner'], $driver] as $user) {
            $wallet = $wallets->forUser($user)->fresh();

            $this->assertSame('0.00', $wallet->balance, 'somebody was paid for an order that failed');
            $this->assertSame('0.00', $wallet->pending_balance, 'something is still held for a failed order');
        }
    }
}
