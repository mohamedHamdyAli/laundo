<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
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
 * «الكوميشين بتاع السوبر ادمن على كل اوردر».
 *
 * The owner's own worked example is the specification, and the first test is
 * literally it: «لو الطلب كله ب 100 وبياخد من الفيندور 10 ف ميه يبقا هيدخل ف
 * حسابه 10 والمغسله 90».
 *
 * What is asserted hardest is what money does, because everything else is
 * recoverable and this is not:
 *
 *  - **The halves always add back to the basis.** Two independent roundings would
 *    leave a piastre belonging to nobody, and a ledger that does not reconcile is
 *    one nobody can defend.
 *  - **Nothing moves until the order completes**, and nothing moves twice.
 *  - **A laundry cannot set its own commission**, even though it holds
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

    /**
     * A commission charge, not yet attached to anybody.
     */
    private function charge(string $basis, float $value, string $name = 'Charge'): CommissionRule
    {
        return CommissionRule::create([
            'name' => json_encode(['en' => $name, 'ar' => 'رسوم'], JSON_UNESCAPED_UNICODE),
            'basis' => $basis,
            'rate' => $basis === 'percent' ? $value : null,
            'amount' => $basis === 'fixed' ? $value : null,
            'status' => 'active',
        ]);
    }

    private function attach(Laundry $laundry, CommissionRule $rule): void
    {
        $laundry->commissionRules()->syncWithoutDetaching([$rule->id]);
    }

    private function settlementFor(Order $order): ?OrderSettlement
    {
        return OrderSettlement::withoutGlobalScope('laundry')->where('order_id', $order->id)->first();
    }

    // -------------------------------------------------------------- the split

    #[Test]
    public function ten_per_cent_of_a_hundred_leaves_ninety(): void
    {
        // The owner's own example — «لو الطلب كله ب 100 وبياخد من الفيندور 10 ف
        // ميه يبقا هيدخل ف حسابه 10 والمغسله 90» — priced so the arithmetic is
        // legible. The 100 is the **washing** now, not the whole order: the
        // delivery fee was taken out of the basis once the overlap with the
        // driver's share was priced.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');

        $order = $this->confirmedOrder();
        $order->forceFill([
            'final_subtotal' => 100,
            'discount_total' => 0,
            'final_total' => 120,
            'delivery_fee' => 20,
        ])->save();

        $settlement = app(SettlementService::class)->recordFor($order->fresh());

        $this->assertSame('100.00', $settlement->basis);
        $this->assertSame('10.00', $settlement->commission_amount);
        $this->assertSame('90.00', $settlement->laundry_amount);
    }

    #[Test]
    public function the_delivery_fee_is_not_the_laundrys_to_share(): void
    {
        // The reason the basis changed. The platform pays the driver out of the
        // delivery fee, so dividing that same fee with the laundry meant paying
        // for one journey twice.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');

        $order = $this->confirmedOrder();
        $order->forceFill([
            'final_subtotal' => 100,
            'discount_total' => 0,
            'final_total' => 150,
            'delivery_fee' => 50,
        ])->save();

        $settlement = app(SettlementService::class)->recordFor($order->fresh());

        // The 50 is nowhere in the split. It stays with the platform, which is
        // what pays the driver.
        $this->assertSame('100.00', $settlement->basis);
        $this->assertSame('90.00', $settlement->laundry_amount);
    }

    #[Test]
    public function a_discount_comes_off_what_the_laundry_is_paid_on(): void
    {
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');

        $order = $this->confirmedOrder();
        $order->forceFill([
            'final_subtotal' => 200,
            'discount_total' => 50,
            'final_total' => 150,
            'delivery_fee' => 0,
        ])->save();

        $settlement = app(SettlementService::class)->recordFor($order->fresh());

        // 150 was collected for the washing, so 150 is what is divided.
        $this->assertSame('150.00', $settlement->basis);
        $this->assertSame('15.00', $settlement->commission_amount);
        $this->assertSame('135.00', $settlement->laundry_amount);
    }

    #[Test]
    public function the_tax_is_never_divided(): void
    {
        $this->setting('Tax', '10');
        $this->setting('Commission_Rate', '10');

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
        // A rate that does not divide evenly is the case two independent
        // roundings would get wrong.
        $this->setting('Commission_Rate', '13.33');

        $order = $this->confirmedOrder();
        $settlement = $this->settlementFor($order);

        $this->assertTrue($settlement->reconciles());
        $this->assertSame(
            (float) $settlement->basis,
            round((float) $settlement->commission_amount + (float) $settlement->laundry_amount, 2)
        );
    }

    // ---------------------------------------------------------------- the rate

    #[Test]
    public function a_laundry_with_nothing_attached_follows_the_general_rate(): void
    {
        $this->setting('Commission_Rate', '15');

        $laundry = $this->tenant['laundry'];

        $this->assertFalse($laundry->hasOwnCommission());

        $commission = app(SettlementService::class)->commissionFor($laundry, 200.0);

        $this->assertSame(30.0, $commission['total']);
        // Still a named line, so a settlement never shows a charge with no
        // explanation beside it.
        $this->assertCount(1, $commission['lines']);
        $this->assertNull($commission['lines'][0]['commission_rule_id']);
    }

    #[Test]
    public function attached_charges_win_over_the_general_rate(): void
    {
        $this->setting('Commission_Rate', '15');

        $this->attach($this->tenant['laundry'], $this->charge('percent', 12));

        $commission = app(SettlementService::class)->commissionFor($this->tenant['laundry']->fresh(), 200.0);

        $this->assertSame(24.0, $commission['total']);
        $this->assertCount(1, $commission['lines']);
    }

    #[Test]
    public function several_charges_add_together(): void
    {
        // The owner's decision — «تتجمع على بعض». 10% of 200, plus a flat 5,
        // plus 3% of 200.
        $this->setting('Commission_Rate', '15');
        $laundry = $this->tenant['laundry'];

        $this->attach($laundry, $this->charge('percent', 10));
        $this->attach($laundry, $this->charge('fixed', 5));
        $this->attach($laundry, $this->charge('percent', 3));

        $commission = app(SettlementService::class)->commissionFor($laundry->fresh(), 200.0);

        $this->assertSame(31.0, $commission['total']);
        $this->assertCount(3, $commission['lines']);
        $this->assertSame(
            31.0,
            round(array_sum(array_column($commission['lines'], 'amount')), 2)
        );
    }

    #[Test]
    public function a_charge_of_zero_is_a_deal_and_an_empty_list_is_not(): void
    {
        $this->setting('Commission_Rate', '15');
        $laundry = $this->tenant['laundry'];

        // The distinction the old nullable column drew, preserved: «no special
        // deal» keeps following the general rate when it moves, «free of
        // charge» must not silently start being charged.
        $this->attach($laundry, $this->charge('percent', 0));
        $this->assertSame(0.0, app(SettlementService::class)->commissionFor($laundry->fresh(), 200.0)['total']);

        $laundry->commissionRules()->detach();
        $this->assertSame(30.0, app(SettlementService::class)->commissionFor($laundry->fresh(), 200.0)['total']);
    }

    #[Test]
    public function an_inactive_charge_bills_nothing(): void
    {
        $this->setting('Commission_Rate', '0');
        $laundry = $this->tenant['laundry'];

        $rule = $this->charge('percent', 10);
        $this->attach($laundry, $rule);

        $rule->update(['status' => 'inactive']);

        // Switching a charge off is how an operator stops billing under it
        // without detaching it from forty laundries. «Inactive but still
        // charging» would make the toggle a lie.
        $this->assertSame(0.0, app(SettlementService::class)->commissionFor($laundry->fresh(), 200.0)['total']);
    }

    #[Test]
    public function stacked_charges_can_never_exceed_the_order(): void
    {
        $this->setting('Commission_Rate', '0');
        $laundry = $this->tenant['laundry'];

        $this->attach($laundry, $this->charge('percent', 80));
        $this->attach($laundry, $this->charge('percent', 80));

        $commission = app(SettlementService::class)->commissionFor($laundry->fresh(), 100.0);

        // A settlement that pays the laundry a negative number is a bill for
        // having done the work.
        $this->assertSame(100.0, $commission['total']);
        $this->assertGreaterThanOrEqual(0.0, round(100.0 - $commission['total'], 2));
    }

    #[Test]
    public function a_flat_charge_larger_than_the_order_is_capped(): void
    {
        $this->setting('Commission_Rate', '0');
        $laundry = $this->tenant['laundry'];

        $this->attach($laundry, $this->charge('fixed', 500));

        $this->assertSame(12.0, app(SettlementService::class)->commissionFor($laundry->fresh(), 12.0)['total']);
    }

    #[Test]
    public function the_settlement_records_a_line_for_every_charge(): void
    {
        $this->setting('Commission_Rate', '0');
        $laundry = $this->tenant['laundry'];

        $this->attach($laundry, $this->charge('percent', 10, 'Base'));
        $this->attach($laundry, $this->charge('fixed', 5, 'Platform fee'));

        $order = $this->confirmedOrder();
        $settlement = $this->settlementFor($order)->load('lines');

        $this->assertCount(2, $settlement->lines);
        // The lines must add back to the total they explain, or a laundry is
        // shown a breakdown that does not come to what it was charged.
        $this->assertTrue($settlement->linesReconcile());
        $this->assertTrue($settlement->reconciles());
    }

    #[Test]
    public function a_line_keeps_the_terms_it_was_charged_at(): void
    {
        $this->setting('Commission_Rate', '0');
        $laundry = $this->tenant['laundry'];
        $rule = $this->charge('percent', 10, 'Base');
        $this->attach($laundry, $rule);

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
        $this->setting('Commission_Rate', '10');

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
        $this->setting('Commission_Rate', '10');
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
        $this->setting('Commission_Rate', '10');
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
        $this->setting('Commission_Rate', '10');
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
        $this->setting('Commission_Rate', '10');
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
        $this->setting('Commission_Rate', '10');
        // Without a platform account the row stays pending and re-recording it
        // is correct — which is what this assertion would otherwise be quietly
        // measuring instead of the freeze it means to prove.
        $this->superAdmin();

        $order = $this->complete($this->confirmedOrder());
        $this->assertSame(OrderSettlement::SETTLED, $this->settlementFor($order)->status);

        $original = (float) $this->settlementFor($order)->commission_amount;

        // Money has moved against those figures. A row that restates itself
        // afterwards is a row that cannot be audited.
        $this->setting('Commission_Rate', '40');
        app(SettlementService::class)->recordFor($order->fresh());

        $this->assertSame($original, (float) $this->settlementFor($order)->commission_amount);
    }

    #[Test]
    public function a_platform_with_no_super_admin_leaves_the_settlement_pending(): void
    {
        $this->setting('Commission_Rate', '10');

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
    public function a_laundry_owner_cannot_set_its_own_commission(): void
    {
        $this->setting('Commission_Rate', '15');
        $laundry = $this->tenant['laundry'];

        // The owner holds laundry.update by design — that is how they edit their
        // own record — so the commission must not ride that permission.
        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.laundry.commission', $laundry->id), [
                'commission_rule_ids' => [$this->charge('percent', 0)->id],
            ])->assertForbidden();

        $this->assertSame(0, $laundry->fresh()->commissionRules()->count());
    }

    #[Test]
    public function the_commission_cannot_ride_the_laundry_form_at_all(): void
    {
        $laundry = $this->tenant['laundry'];

        // There is no commission column left to mass-assign. The charges live
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

        $first = $this->charge('percent', 12.5);
        $second = $this->charge('fixed', 5);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.commission', $laundry->id), [
                'commission_rule_ids' => [$first->id, $second->id],
            ])->assertRedirect();

        $this->assertSame(2, $laundry->fresh()->commissionRules()->count());

        // sync(), not attach(): a charge the operator unticked has to come off,
        // and a charge nobody can remove is the worst kind.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.commission', $laundry->id), [
                'commission_rule_ids' => [$second->id],
            ])->assertRedirect();

        $this->assertSame([$second->id], $laundry->fresh()->commissionRules()->pluck('commission_rules.id')->all());

        // An empty list returns the laundry to the general rate.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.commission', $laundry->id), [])
            ->assertRedirect();

        $this->assertSame(0, $laundry->fresh()->commissionRules()->count());
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
        $this->setting('Commission_Rate', '10');

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
        $this->setting('Commission_Rate', '10');
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
        $this->setting('Commission_Rate', '10');
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
        $this->setting('Commission_Rate', '10');
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
        $this->setting('Commission_Rate', '10');
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
