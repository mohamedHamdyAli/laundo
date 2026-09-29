<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Modules\Payment\Models\CommissionRule;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Services\SettlementService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use App\Support\PlatformAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «الكوميشين بتاع السوبر ادمن على كل اوردر» — and then turned round.
 *
 * The owner's first worked example was «لو الطلب كله ب 100 وبياخد من الفيندور 10
 * ف ميه يبقا هيدخل ف حسابه 10 والمغسله 90». The client later reversed it: the
 * percentage set on a laundry is what the **laundry** receives, and the platform
 * keeps the rest — «المغسله انا بقول تاخد 10% يبقا 90 ف حساب السوبر ادمن 10 ف
 * حساب المغسله». The first test is that sentence.
 *
 * What is asserted hardest is what money does, because everything else is
 * recoverable and this is not:
 *
 *  - **The halves always add back to the basis.** Two independent roundings would
 *    leave a piastre belonging to nobody, and a ledger that does not reconcile is
 *    one nobody can defend.
 *  - **Nothing moves until the order completes**, nothing moves twice, and
 *    nothing moves on terms nobody set — a laundry with no share waits.
 *  - **A laundry cannot set its own share**, even though it holds
 *    `laundry.update` on its own record by design.
 *  - **A laundry sees its own settlements and no other laundry's.**
 */
class CommissionSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer('+201099880011');
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

    private function placedOrder(): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    /** Placed, priced by the laundry and agreed by the customer. */
    private function confirmedOrder(): Order
    {
        $order = $this->placedOrder();
        $machine = app(OrderStateMachine::class);

        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $reviews = app(OrderReviewService::class);
        $reviews->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);

        return $reviews->confirm($order->fresh(), $this->customer)->fresh();
    }

    /**
     * Walk an order to Completed without going through the four driver legs.
     *
     * Cleaning -> ReadyForDelivery -> Delivered -> Completed are the legal steps
     * from a confirmed order, and the settlement hangs off the last of them.
     */
    private function complete(Order $order): Order
    {
        $machine = app(OrderStateMachine::class);

        foreach ([OrderStatus::Cleaning, OrderStatus::ReadyForDelivery, OrderStatus::Delivered, OrderStatus::Completed] as $status) {
            $order = $machine->transition($order->fresh(), $status, 'admin');
        }

        return $order->fresh();
    }

    private function attach(Laundry $laundry, CommissionRule $rule): void
    {
        $laundry->commissionRules()->syncWithoutDetaching([$rule->id]);
    }

    /**
     * A share rule — what a laundry **receives** — not yet attached to anybody.
     *
     * The number on a rule used to be the platform's cut. It is the laundry's
     * share now, so 10 means the laundry is paid 10 in the hundred and the
     * platform keeps 90.
     */
    private function share(float $percent, string $name = 'Share', string $status = 'active'): CommissionRule
    {
        return CommissionRule::create([
            'name' => json_encode(['en' => $name, 'ar' => 'نسبة'], JSON_UNESCAPED_UNICODE),
            'basis' => 'percent',
            'rate' => $percent,
            'amount' => null,
            'status' => $status,
        ]);
    }

    /**
     * What this tenant's laundry receives, as an attached rule.
     */
    private function laundryGets(float $percent, string $name = 'Share'): CommissionRule
    {
        $rule = $this->share($percent, $name);
        $this->attach($this->tenant['laundry'], $rule);

        return $rule;
    }

    private function settlementFor(Order $order): ?OrderSettlement
    {
        return OrderSettlement::withoutGlobalScope('laundry')->where('order_id', $order->id)->first();
    }

    // -------------------------------------------------------------- the split

    #[Test]
    public function the_laundry_receives_the_percentage_and_the_platform_keeps_the_rest(): void
    {
        // The client's own statement of the reversal — «المغسله انا بقول تاخد
        // 10% يبقا 90 ف حساب السوبر ادمن 10 ف حساب المغسله». The 100 is the
        // washing, not the whole order: the delivery fee was taken out of the
        // basis once the overlap with the driver's share was priced.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);

        $order = $this->confirmedOrder();
        $order->forceFill([
            'final_subtotal' => 100,
            'discount_total' => 0,
            'final_total' => 120,
            'delivery_fee' => 20,
        ])->save();

        $settlement = app(SettlementService::class)->recordFor($order->fresh());

        $this->assertSame('100.00', $settlement->basis);
        $this->assertSame('10.00', $settlement->laundry_amount);
        $this->assertSame('90.00', $settlement->commission_amount);
        $this->assertSame('10.00', $settlement->laundry_share_rate);
    }

    #[Test]
    public function the_delivery_fee_is_not_the_laundrys_to_share(): void
    {
        // The platform pays the driver out of the delivery fee, so dividing that
        // same fee with the laundry would pay for one journey twice.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);

        $order = $this->confirmedOrder();
        $order->forceFill([
            'final_subtotal' => 100,
            'discount_total' => 0,
            'final_total' => 150,
            'delivery_fee' => 50,
        ])->save();

        $settlement = app(SettlementService::class)->recordFor($order->fresh());

        // The 50 is nowhere in the split. It stays with the platform.
        $this->assertSame('100.00', $settlement->basis);
        $this->assertSame('10.00', $settlement->laundry_amount);
    }

    /**
     * An order carrying a coupon discount, borne as the order says.
     *
     * Written straight onto the order, the way placement copies it off the
     * coupon: 200 of washing, 50 off.
     */
    private function discountedOrder(?float $laundryBears, bool $coversDelivery = false, float $deliveryFee = 0): Order
    {
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '0');

        $order = $this->confirmedOrder();
        $order->forceFill([
            'final_subtotal' => 200,
            'discount_total' => 50,
            'discount_laundry_share' => $laundryBears,
            'discount_covers_delivery' => $coversDelivery,
            'final_total' => 150 + $deliveryFee,
            'delivery_fee' => $deliveryFee,
        ])->save();

        return $order->fresh();
    }

    #[Test]
    public function by_default_the_platform_pays_for_the_discount(): void
    {
        // «المفروض الكوبون يكون على السوبر أدمن». The laundry's 10% is measured
        // on its prices before the coupon, and the platform's part absorbs it.
        $this->laundryGets(10);

        $settlement = app(SettlementService::class)->recordFor($this->discountedOrder(null));

        $this->assertSame('150.00', $settlement->basis);
        $this->assertSame('20.00', $settlement->laundry_amount);
        $this->assertSame('130.00', $settlement->commission_amount);
        $this->assertSame('50.00', $settlement->discount_amount);
        $this->assertSame('0.00', $settlement->laundry_discount_amount);
        $this->assertTrue($settlement->reconciles());
    }

    #[Test]
    public function a_coupon_the_laundry_bears_comes_off_its_share(): void
    {
        $this->laundryGets(30);

        // 30% of 200 is 60; the laundry bears the whole 50.
        $settlement = app(SettlementService::class)->recordFor($this->discountedOrder(100));

        $this->assertSame('10.00', $settlement->laundry_amount);
        $this->assertSame('50.00', $settlement->laundry_discount_amount);
        $this->assertSame('140.00', $settlement->commission_amount);
        $this->assertTrue($settlement->reconciles());
        $this->assertTrue($settlement->load('lines')->linesReconcile());
    }

    #[Test]
    public function a_split_coupon_is_borne_in_the_agreed_proportion(): void
    {
        $this->laundryGets(30);

        // 60 less 40% of the 50.
        $settlement = app(SettlementService::class)->recordFor($this->discountedOrder(40));

        $this->assertSame('40.00', $settlement->laundry_amount);
        $this->assertSame('20.00', $settlement->laundry_discount_amount);
        $this->assertEquals(30.0, $settlement->platformDiscount());
        $this->assertTrue($settlement->reconciles());
    }

    #[Test]
    public function the_laundry_never_goes_below_zero_on_a_coupon(): void
    {
        // Its 10% of 200 is 20, and it was to bear the whole 50. It is paid
        // nothing on this order and the platform carries the other 30 — nobody
        // owes for having done the work.
        $this->laundryGets(10);

        $settlement = app(SettlementService::class)->recordFor($this->discountedOrder(100));

        $this->assertSame('0.00', $settlement->laundry_amount);
        $this->assertSame('20.00', $settlement->laundry_discount_amount);
        $this->assertSame('150.00', $settlement->commission_amount);
    }

    #[Test]
    public function the_delivery_part_of_a_coupon_is_always_the_platforms(): void
    {
        // Sized on 200 of pieces and 50 of delivery, so a fifth of the 50 off
        // came off the journey. The laundry bears all of the pieces' part — 40 —
        // and none of the journey's.
        $this->laundryGets(30);

        $settlement = app(SettlementService::class)->recordFor($this->discountedOrder(100, true, 50));

        $this->assertSame('40.00', $settlement->laundry_discount_amount);
        $this->assertSame('20.00', $settlement->laundry_amount);
    }

    #[Test]
    public function the_platform_pays_the_difference_from_its_wallet(): void
    {
        // A laundry on 90% with the platform bearing a 50 coupon on 200: the
        // laundry is owed 180 in full, the customer paid 150, and the platform
        // funds the 30 — out of a wallet that holds nothing yet.
        $platform = $this->superAdmin();
        $this->laundryGets(90);

        $order = $this->complete($this->discountedOrder(0));
        $settlement = $this->settlementFor($order);

        $this->assertSame(OrderSettlement::SETTLED, $settlement->status);
        $this->assertSame('180.00', $settlement->laundry_amount);
        $this->assertSame('-30.00', $settlement->commission_amount);

        $wallets = app(WalletService::class);
        $this->assertSame(180.0, (float) $wallets->forUser($this->tenant['owner'])->balance);
        $this->assertSame(-30.0, (float) $wallets->forUser($platform)->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallets->forUser($platform)->id,
            'reason' => TransactionReason::DiscountFunded->value,
        ]);
    }

    #[Test]
    public function the_tax_is_never_divided(): void
    {
        $this->setting('Tax', '10');
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);

        $order = $this->confirmedOrder();
        $settlement = $this->settlementFor($order);

        // The state's money passes through on its way to the treasury. Splitting
        // it would have both parties drawing on a sum neither is owed.
        $this->assertSame(round($order->payableTax(), 2), (float) $settlement->tax_amount);
        $this->assertSame($order->cleaningRevenue(), (float) $settlement->basis);
        $this->assertLessThan($order->payableTotal(), (float) $settlement->basis);
    }

    #[Test]
    public function the_two_halves_always_add_back_to_the_basis(): void
    {
        // A share that does not divide evenly is the case two independent
        // roundings would get wrong.
        $this->laundryGets(13.33);

        $order = $this->confirmedOrder();
        $settlement = $this->settlementFor($order);

        $this->assertTrue($settlement->reconciles());
        $this->assertSame(
            (float) $settlement->basis,
            round((float) $settlement->commission_amount + (float) $settlement->laundry_amount, 2)
        );
    }

    #[Test]
    public function the_laundrys_share_is_the_one_rounded(): void
    {
        // 10% of 17.35 is 1.735. Rounding the laundry's share gives 1.74; the
        // stop-gap of a 90% platform rule would have rounded the platform's
        // 15.615 up and left the laundry 1.73 — a piastre short at every half.
        $this->laundryGets(10);

        $split = app(SettlementService::class)->splitFor($this->tenant['laundry']->fresh(), 17.35);

        $this->assertSame(1.74, $split['laundry']);
        $this->assertSame(15.61, $split['commission']);
    }

    // --------------------------------------------------------------- the share

    #[Test]
    public function a_laundry_on_no_share_follows_the_general_share(): void
    {
        // `Commission_Rate` is the customer's platform fee and must never be
        // read as the laundry's share — the two are paid by different people.
        $this->setting('Commission_Rate', '20');
        $this->setting('Laundry_Share_Rate', '15');

        $split = app(SettlementService::class)->splitFor($this->tenant['laundry']->fresh(), 200.0);

        $this->assertSame(15.0, $split['share_rate']);
        $this->assertSame(30.0, $split['laundry']);
        $this->assertSame(170.0, $split['commission']);
        // Named as the general share on the settlement, with no rule behind it.
        $this->assertCount(1, $split['lines']);
        $this->assertNull($split['lines'][0]['commission_rule_id']);
    }

    #[Test]
    public function its_own_share_wins_over_the_general_one(): void
    {
        $this->setting('Laundry_Share_Rate', '15');
        $this->laundryGets(12);

        $split = app(SettlementService::class)->splitFor($this->tenant['laundry']->fresh(), 200.0);

        $this->assertSame(12.0, $split['share_rate']);
        $this->assertSame(24.0, $split['laundry']);
    }

    #[Test]
    public function with_no_share_anywhere_nothing_is_divided(): void
    {
        // Not zero for the laundry and everything for the platform: nobody has
        // decided, so there is no split at all and the settlement waits.
        $this->setting('Commission_Rate', '20');
        $this->setting('Laundry_Share_Rate', null);

        $split = app(SettlementService::class)->splitFor($this->tenant['laundry']->fresh(), 200.0);

        $this->assertNull($split['share_rate']);
        $this->assertSame(0.0, $split['laundry']);
        $this->assertSame(0.0, $split['commission']);
        $this->assertSame([], $split['lines']);
    }

    #[Test]
    public function a_share_of_zero_is_a_decision_and_no_share_is_not(): void
    {
        $this->setting('Laundry_Share_Rate', '15');

        // An attached 0% is somebody saying «this laundry is paid nothing».
        $this->laundryGets(0);
        $split = app(SettlementService::class)->splitFor($this->tenant['laundry']->fresh(), 200.0);

        $this->assertSame(0.0, $split['share_rate']);
        $this->assertSame(0.0, $split['laundry']);
        $this->assertSame(200.0, $split['commission']);
    }

    #[Test]
    public function an_inactive_share_falls_back_to_the_general_one(): void
    {
        $laundry = $this->tenant['laundry'];
        $this->laundryGets(10)->update(['status' => 'inactive']);

        $this->setting('Laundry_Share_Rate', '15');
        $this->assertSame(15.0, app(SettlementService::class)->splitFor($laundry->fresh(), 200.0)['share_rate']);

        // And with no general share either, nothing is divided — «switched off
        // but still paying» would make the toggle a lie.
        $this->setting('Laundry_Share_Rate', null);
        $this->assertNull(app(SettlementService::class)->splitFor($laundry->fresh(), 200.0)['share_rate']);
    }

    #[Test]
    public function a_retired_fixed_rule_pays_nothing(): void
    {
        // Written directly: no form can create one any more. It is history, and
        // reading it as terms would pay a flat sum nobody agreed to under the
        // new meaning.
        $fixed = CommissionRule::create([
            'name' => json_encode(['en' => 'Old flat'], JSON_UNESCAPED_UNICODE),
            'basis' => 'fixed', 'rate' => null, 'amount' => 5, 'status' => 'active',
        ]);
        $this->attach($this->tenant['laundry'], $fixed);

        $this->assertNull(app(SettlementService::class)->splitFor($this->tenant['laundry']->fresh(), 200.0)['share_rate']);
    }

    #[Test]
    public function two_active_shares_written_by_hand_do_not_add_up(): void
    {
        // The forms refuse this; a row written by hand gets the oldest share,
        // deterministically, rather than the two stacking the way rules used to.
        $this->laundryGets(10);
        $this->laundryGets(30);

        $split = app(SettlementService::class)->splitFor($this->tenant['laundry']->fresh(), 200.0);

        $this->assertSame(10.0, $split['share_rate']);
        $this->assertSame(20.0, $split['laundry']);
    }

    #[Test]
    public function the_settlement_records_the_laundrys_terms_as_its_line(): void
    {
        $this->setting('Commission_Rate', '0');
        $rule = $this->laundryGets(10, 'Base');

        $order = $this->confirmedOrder();
        $settlement = $this->settlementFor($order)->load('lines');

        $this->assertCount(1, $settlement->lines);
        $this->assertSame($rule->id, $settlement->lines->first()->commission_rule_id);
        // The line explains the laundry's amount now, not the platform's.
        $this->assertSame((float) $settlement->laundry_amount, (float) $settlement->lines->first()->amount);
        $this->assertTrue($settlement->linesReconcile());
        $this->assertTrue($settlement->reconciles());
    }

    #[Test]
    public function a_line_keeps_the_terms_it_was_charged_at(): void
    {
        $this->setting('Commission_Rate', '0');
        $rule = $this->laundryGets(10, 'Base');

        $order = $this->confirmedOrder();
        $before = (float) $this->settlementFor($order)->load('lines')->lines->first()->amount;

        // The terms move next quarter, and the rule is renamed.
        $rule->update(['rate' => 40, 'name' => json_encode(['en' => 'Renamed'], JSON_UNESCAPED_UNICODE)]);

        $line = $this->settlementFor($order)->load('lines')->lines->first();

        $this->assertSame($before, (float) $line->amount);
        $this->assertEquals(10.0, (float) $line->rate);
        $this->assertSame('Base', $line->name->en);
    }

    // ------------------------------------------------------------ when it moves

    #[Test]
    public function confirming_records_the_split_without_moving_anything(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);

        $order = $this->confirmedOrder();
        $settlement = $this->settlementFor($order);

        $this->assertNotNull($settlement);
        $this->assertSame(OrderSettlement::PENDING, $settlement->status);
        $this->assertNull($settlement->settled_at);

        // Both sides can see what is coming, and neither has been paid.
        $wallets = app(WalletService::class);
        $this->assertSame(0.0, (float) $wallets->forUser($this->superAdmin())->balance);
        $this->assertSame(0.0, (float) $wallets->forUser($this->tenant['owner'])->balance);
    }

    #[Test]
    public function completing_credits_both_wallets(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $platform = $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $settlement = $this->settlementFor($order);

        $this->assertSame(OrderSettlement::SETTLED, $settlement->status);
        $this->assertNotNull($settlement->settled_at);

        $wallets = app(WalletService::class);
        $this->assertSame(
            (float) $settlement->commission_amount,
            (float) $wallets->forUser($platform)->balance
        );
        $this->assertSame(
            (float) $settlement->laundry_amount,
            (float) $wallets->forUser($this->tenant['owner'])->balance
        );
    }

    #[Test]
    public function each_side_gets_a_transaction_naming_the_order(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $platform = $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $wallets = app(WalletService::class);

        $commission = WalletTransaction::where('wallet_id', $wallets->forUser($platform)->id)
            ->where('reason', TransactionReason::Commission->value)->first();
        $payout = WalletTransaction::where('wallet_id', $wallets->forUser($this->tenant['owner'])->id)
            ->where('reason', TransactionReason::LaundryPayout->value)->first();

        // «عشان يبقو شايفين كل حاجه تخصهم» — a balance that changed with no row
        // explaining it is a balance nobody can audit.
        $this->assertNotNull($commission);
        $this->assertNotNull($payout);
        $this->assertStringContainsString($order->code, (string) $commission->note);
        $this->assertStringContainsString($order->code, (string) $payout->note);

        // The settlement is the source, so a disputed figure leads back to the
        // arithmetic that produced it.
        $this->assertSame(OrderSettlement::class, $commission->source_type);
        $this->assertSame(OrderSettlement::class, $payout->source_type);
    }

    #[Test]
    public function a_replayed_completion_cannot_pay_twice(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $platform = $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());

        $balance = (float) app(WalletService::class)->forUser($platform)->balance;

        app(SettlementService::class)->settleFor($order->fresh());
        app(SettlementService::class)->settleFor($order->fresh());

        $this->assertSame($balance, (float) app(WalletService::class)->forUser($platform)->fresh()->balance);
        $this->assertSame(1, OrderSettlement::withoutGlobalScope('laundry')->where('order_id', $order->id)->count());
    }

    #[Test]
    public function an_order_that_never_completes_pays_nobody(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $this->superAdmin();

        // Returned rather than Cancelled, because the state machine will not
        // accept the latter here: Cancelled is reachable only from
        // AwaitingPickup and DriverOnWay, which are both before the price is
        // agreed and therefore before any settlement exists. Returned is the
        // real path an already-priced order takes when the argument ends and
        // the pieces go back, so it is the one worth proving.
        $order = $this->placedOrder();
        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        app(OrderReviewService::class)
            ->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);

        // Recorded early, so there is something for the return to cancel.
        app(SettlementService::class)->recordFor($order->fresh());
        $this->assertSame(OrderSettlement::PENDING, $this->settlementFor($order)->status);

        $machine->transition($order->fresh(), OrderStatus::Returned, 'admin');

        $this->assertSame(OrderSettlement::CANCELLED, $this->settlementFor($order)->status);
        $this->assertSame(0.0, (float) app(WalletService::class)->forUser($this->superAdmin())->balance);
        $this->assertSame(0.0, (float) app(WalletService::class)->forUser($this->tenant['owner'])->balance);
    }

    #[Test]
    public function a_settled_order_is_not_re_recorded_when_the_rate_changes(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        // Without a platform account the row stays pending and re-recording it
        // is correct — which is what this assertion would otherwise be quietly
        // measuring instead of the freeze it means to prove.
        $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $this->assertSame(OrderSettlement::SETTLED, $this->settlementFor($order)->status);

        $original = (float) $this->settlementFor($order)->laundry_amount;

        // Money has moved against those figures. A row that restates itself
        // afterwards is a row that cannot be audited.
        CommissionRule::query()->update(['rate' => 40]);
        app(SettlementService::class)->recordFor($order->fresh());

        $this->assertSame($original, (float) $this->settlementFor($order)->laundry_amount);
    }

    #[Test]
    public function a_platform_with_no_super_admin_leaves_the_settlement_pending(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);

        // Deliberately no superAdmin() in this test: an install with no platform
        // account is a fault somebody has to fix, and a settlement sitting at
        // «pending» on the screen is how they find out. Paying half of it, or
        // silently dropping it, would hide the fault instead.
        $this->assertNull(PlatformAccount::user());

        $order = $this->complete($this->confirmedOrder());

        $this->assertSame(OrderSettlement::PENDING, $this->settlementFor($order)->status);
        $this->assertSame(0.0, (float) app(WalletService::class)->forUser($this->tenant['owner'])->balance);
    }

    #[Test]
    public function the_oldest_super_admin_holds_the_platform_wallet(): void
    {
        $first = $this->superAdmin();

        $second = User::create([
            'name' => 'Second Super', 'email' => 'super2@test.local',
            'phone' => '+201000000099', 'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'super_admin')->value('id'),
        ]);

        // «Newest wins» would move the platform's balance the day somebody is
        // promoted, silently, and only visibly once the totals stop adding up.
        $this->assertSame($first->id, PlatformAccount::user()->id);
        $this->assertNotSame($second->id, PlatformAccount::user()->id);
    }

    // ------------------------------------------------------------- who may set it

    #[Test]
    public function a_laundry_owner_cannot_set_its_own_share(): void
    {
        $laundry = $this->tenant['laundry'];

        // The owner holds laundry.update by design — that is how they edit their
        // own record — so the share must not ride that permission. Here the
        // payee choosing its own share is the one who would gain from it.
        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.laundry.commission', $laundry->id), [
                'commission_rule_id' => $this->share(100)->id,
            ])->assertForbidden();

        $this->assertSame(0, $laundry->fresh()->commissionRules()->count());
    }

    #[Test]
    public function the_commission_cannot_ride_the_laundry_form_at_all(): void
    {
        $laundry = $this->tenant['laundry'];

        // There is no commission column left to mass-assign. The shares live
        // in a pivot that only `admin.laundry.commission` writes, and that route
        // is gated on `setting.update` — so the boundary is structural now
        // rather than a fillable list somebody could edit in good faith.
        $laundry->fill(['commission_rule_ids' => [1], 'commission_rate' => 3])->save();

        $this->assertSame(0, $laundry->fresh()->commissionRules()->count());
        $this->assertFalse(Schema::hasColumn('laundries', 'commission_rate'));
    }

    #[Test]
    public function an_operator_sets_it_from_the_laundry_list(): void
    {
        $this->grant('super_admin', ['laundry.view', 'setting.update']);
        $laundry = $this->tenant['laundry'];

        $first = $this->share(12.5);
        $second = $this->share(20);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.commission', $laundry->id), ['commission_rule_id' => $first->id])
            ->assertRedirect();

        $this->assertSame([$first->id], $laundry->fresh()->commissionRules()->pluck('commission_rules.id')->all());

        // One share at a time: choosing another replaces it, never adds to it.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.commission', $laundry->id), ['commission_rule_id' => $second->id])
            ->assertRedirect();

        $this->assertSame([$second->id], $laundry->fresh()->commissionRules()->pluck('commission_rules.id')->all());

        // None hands the laundry to the general share.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.commission', $laundry->id), ['commission_rule_id' => ''])
            ->assertRedirect();

        $this->assertSame(0, $laundry->fresh()->commissionRules()->count());
    }

    #[Test]
    public function the_laundry_list_refuses_a_share_that_pays_on_nothing(): void
    {
        $this->grant('super_admin', ['laundry.view', 'setting.update']);
        $laundry = $this->tenant['laundry'];

        $off = $this->share(10, 'Off', 'inactive');
        $fixed = CommissionRule::create([
            'name' => json_encode(['en' => 'Old flat'], JSON_UNESCAPED_UNICODE),
            'basis' => 'fixed', 'rate' => null, 'amount' => 5, 'status' => 'active',
        ]);

        foreach ([$off, $fixed] as $rule) {
            $this->actingAs($this->superAdmin())
                ->post(route('admin.laundry.commission', $laundry->id), ['commission_rule_id' => $rule->id])
                ->assertSessionHasErrors('commission_rule_id');
        }

        $this->assertSame(0, $laundry->fresh()->commissionRules()->count());
    }

    #[Test]
    public function the_rule_form_will_not_put_a_laundry_on_a_second_share(): void
    {
        $laundry = $this->tenant['laundry'];
        $this->laundryGets(10);

        $payload = [
            'name' => ['en' => 'Second'],
            'rate' => 25,
            'laundry_ids' => [$laundry->id],
            'status' => 'active',
        ];

        $this->actingAs($this->superAdmin())
            ->post(route('admin.commission_rule.store'), $payload)
            ->assertSessionHasErrors('laundry_ids');

        $this->assertSame(1, CommissionRule::count());

        // Switched off, it is not a second share, and the form accepts it.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.commission_rule.store'), ['status' => 'inactive'] + $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(2, CommissionRule::count());
    }

    #[Test]
    public function an_edit_that_leaves_the_status_out_is_still_checked(): void
    {
        // An update may omit `status`, and the rule keeps the one it has. An
        // active rule must not pick up a laundry already on another share just
        // by not resending it.
        $laundry = $this->tenant['laundry'];
        $this->laundryGets(10);
        $other = $this->share(25, 'Other');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.commission_rule.update', $other->id), [
                'name' => ['en' => 'Other'],
                'rate' => 25,
                'laundry_ids' => [$laundry->id],
            ])
            ->assertSessionHasErrors('laundry_ids');

        $this->assertSame(0, $other->fresh()->laundries()->count());
    }

    #[Test]
    public function the_rule_form_writes_a_percentage_whatever_it_is_sent(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.commission_rule.store'), [
                'name' => ['en' => 'Sent as fixed'],
                'basis' => 'fixed',
                'amount' => 5,
                'rate' => 10,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $rule = CommissionRule::latest('id')->first();

        $this->assertTrue($rule->basis === CommissionBasis::Percent);
        $this->assertNull($rule->amount);
        $this->assertEquals(10.0, (float) $rule->rate);
    }

    #[Test]
    public function a_rule_cannot_be_switched_on_over_another_share(): void
    {
        $laundry = $this->tenant['laundry'];
        $this->laundryGets(10);

        $dormant = $this->share(40, 'Dormant', 'inactive');
        $this->attach($laundry, $dormant);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.commission_rule.toggleStatus', $dormant->id), ['status' => 'active'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame('inactive', $dormant->fresh()->status);
    }

    #[Test]
    public function a_retired_fixed_rule_cannot_be_switched_back_on(): void
    {
        $fixed = CommissionRule::create([
            'name' => json_encode(['en' => 'Old flat'], JSON_UNESCAPED_UNICODE),
            'basis' => 'fixed', 'rate' => null, 'amount' => 5, 'status' => 'inactive',
        ]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.commission_rule.toggleStatus', $fixed->id), ['status' => 'active'])
            ->assertStatus(422);

        $this->assertSame('inactive', $fixed->fresh()->status);
    }

    // -------------------------------------------------- a settlement that waits

    #[Test]
    public function a_completed_order_with_no_share_waits_and_moves_nothing(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->setting('Laundry_Share_Rate', null);
        $platform = $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $settlement = $this->settlementFor($order);

        // Paying here would credit the platform the whole basis for work the
        // laundry did, on terms nobody set.
        $this->assertSame(OrderSettlement::PENDING, $settlement->status);
        $this->assertNull($settlement->laundry_share_rate);
        $this->assertTrue($settlement->load('lines')->awaitsShare());

        $wallets = app(WalletService::class);
        $this->assertSame(0.0, (float) $wallets->forUser($platform)->balance);
        $this->assertSame(0.0, (float) $wallets->forUser($this->tenant['owner'])->balance);
    }

    #[Test]
    public function settle_now_pays_it_once_a_share_is_set(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->setting('Laundry_Share_Rate', null);
        $platform = $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $settlement = $this->settlementFor($order);

        // Still nothing to divide by: the button says so and moves nothing.
        $this->actingAs($platform)
            ->post(route('admin.settlement.settle', $settlement->id))
            ->assertSessionHas('error');

        $this->assertSame(OrderSettlement::PENDING, $settlement->fresh()->status);

        // Somebody sets the laundry's share; the same button now pays it.
        $this->laundryGets(10);

        $this->actingAs($platform)
            ->post(route('admin.settlement.settle', $settlement->id))
            ->assertSessionHas('success');

        $settlement = $settlement->fresh();
        $this->assertSame(OrderSettlement::SETTLED, $settlement->status);
        $this->assertSame('10.00', $settlement->laundry_share_rate);
        $this->assertSame(round((float) $settlement->basis * 0.10, 2), (float) $settlement->laundry_amount);

        $wallets = app(WalletService::class);
        $this->assertSame((float) $settlement->laundry_amount, (float) $wallets->forUser($this->tenant['owner'])->balance);
        $this->assertSame((float) $settlement->commission_amount, (float) $wallets->forUser($platform)->balance);

        // And a second press cannot pay it again.
        $this->actingAs($platform)
            ->post(route('admin.settlement.settle', $settlement->id))
            ->assertSessionHas('error');

        $this->assertSame((float) $settlement->laundry_amount, (float) $wallets->forUser($this->tenant['owner'])->fresh()->balance);
    }

    #[Test]
    public function settle_now_will_not_pay_an_order_still_in_progress(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $platform = $this->superAdmin();

        $settlement = $this->settlementFor($this->confirmedOrder());

        $this->actingAs($platform)
            ->post(route('admin.settlement.settle', $settlement->id))
            ->assertSessionHas('error');

        $this->assertSame(OrderSettlement::PENDING, $settlement->fresh()->status);
        $this->assertSame(0.0, (float) app(WalletService::class)->forUser($this->tenant['owner'])->balance);
    }

    #[Test]
    public function settle_now_is_not_for_the_laundry_to_press(): void
    {
        $this->setting('Laundry_Share_Rate', null);
        $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $settlement = $this->settlementFor($order);

        $this->grant('laundry_owner', ['order_settlement.view']);

        // It reads the screen; it does not decide when it is paid.
        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.settlement.settle', $settlement->id))
            ->assertForbidden();

        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.settlement.index'))
            ->assertOk()
            ->assertDontSee(route('admin.settlement.settle', $settlement->id), false);
    }

    #[Test]
    public function the_button_is_drawn_only_for_somebody_who_may_use_it(): void
    {
        // The laundry owner, not a narrowed super admin: `canDo()` short-circuits
        // to true for a super admin, so a permission taken away from that role
        // proves nothing. The owner holds `laundry.view` and not
        // `setting.update`, which is exactly the case that matters — the payer
        // must not be shown the dial.
        $this->grant('laundry_owner', ['laundry.view']);

        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.laundry.index'))
            ->assertOk()
            ->assertDontSee('js-commission-btn');

        $this->grant('super_admin', ['laundry.view', 'setting.update']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.laundry.index'))
            ->assertOk()
            ->assertSee('js-commission-btn');
    }

    // ---------------------------------------------------------------- who sees it

    #[Test]
    public function a_laundry_sees_its_own_settlements_and_no_others(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);

        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');
        $this->cover($other['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $mine = $this->confirmedOrder();

        // A settlement belonging to somebody else entirely. Hung off a real
        // order because `order_id` is a constrained foreign key.
        $theirs = $this->placedOrder();
        $theirs->forceFill(['laundry_id' => $other['laundry']->id])->save();

        OrderSettlement::withoutGlobalScope('laundry')->create([
            'order_id' => $theirs->id,
            'laundry_id' => $other['laundry']->id,
            'basis' => 500, 'commission_rate' => 10, 'commission_amount' => 50,
            'laundry_amount' => 450, 'tax_amount' => 0, 'status' => OrderSettlement::PENDING,
        ]);

        $this->grant('laundry_owner', ['order_settlement.view']);

        $response = $this->actingAs($this->tenant['owner'])
            ->get(route('admin.settlement.index'))
            ->assertOk();

        $response->assertSee($mine->code);
        // The other laundry's 450 must not appear anywhere on the page.
        $response->assertDontSee(moneyFormat(450), false);
    }

    #[Test]
    public function the_search_returns_the_rows_and_the_pagination(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $this->grant('super_admin', ['order_settlement.view']);

        $order = $this->confirmedOrder();

        // The AJAX half of every list screen. A bare GET is an empty 200 by
        // design, so the header is what makes this a real request.
        $this->actingAs($this->superAdmin())
            ->getJson(route('admin.settlement.search', ['query' => $order->code]), [
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertJsonStructure(['table', 'pagination'])
            ->assertSee($order->code, false);

        // A term that matches nothing returns the empty state, not every row.
        $response = $this->actingAs($this->superAdmin())
            ->getJson(route('admin.settlement.search', ['query' => 'nothing-matches-this']), [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->assertOk();

        $this->assertStringNotContainsString($order->code, (string) $response->json('table'));
    }

    #[Test]
    public function the_settlement_screen_is_gated(): void
    {
        $this->grant('laundry_owner', ['order.view']);

        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.settlement.index'))
            ->assertForbidden();
    }

    #[Test]
    public function a_laundry_owner_reads_its_own_wallet_without_holding_wallet_view(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $settlement = $this->settlementFor($order);

        $this->grant('laundry_owner', ['order.view']);

        // «محفظتي» has no permission on purpose: reading your own balance is not
        // the same capability as reading everybody's, and wallet.view is the
        // latter because the wallet list is not tenant-scoped.
        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.wallet.mine'))
            ->assertOk()
            ->assertSee(moneyFormat($settlement->laundry_amount), false)
            ->assertSee(__('Laundry share of an order'), false);

        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.wallet.index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_super_admin_reads_the_commission_on_its_own_wallet(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $platform = $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $settlement = $this->settlementFor($order);

        $this->actingAs($platform)
            ->get(route('admin.wallet.mine'))
            ->assertOk()
            ->assertSee(moneyFormat($settlement->commission_amount), false)
            ->assertSee(__('Platform commission'), false);
    }

    #[Test]
    public function the_order_screen_shows_how_the_order_was_divided(): void
    {
        $this->setting('Commission_Rate', '0');
        $this->laundryGets(10);
        $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $settlement = $this->settlementFor($order);

        $this->grant('super_admin', ['order.view', 'order_settlement.view']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee(__('Settlement'), false)
            ->assertSee(moneyFormat($settlement->commission_amount), false)
            ->assertSee(moneyFormat($settlement->laundry_amount), false);
    }
}
