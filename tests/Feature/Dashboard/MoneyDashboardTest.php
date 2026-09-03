<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Models\CouponRedemption;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Payment\Models\Refund;
use App\Modules\Payment\Services\RefundService;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The money screens.
 *
 * These exist because the services were unreachable: operations had no way to
 * create a coupon or approve a refund except through code. What the tests check
 * is that the screens do not quietly break the guarantees the services make —
 * above all that **no screen sets a balance**, because nothing anywhere does.
 */
class MoneyDashboardTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

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
    }

    // -------------------------------------------------------------- coupons

    #[Test]
    public function an_operator_can_create_a_code_from_the_dashboard(): void
    {
        $this->actingAs($this->superAdmin())->post('/admin/coupon/store', [
            'code' => 'welcome10',
            'name' => ['en' => 'Welcome', 'ar' => 'ترحيب'],
            'type' => Coupon::FIXED,
            'value' => 10,
            'max_per_user' => 1,
            'status' => 'active',
        ])->assertRedirect();

        $coupon = Coupon::firstOrFail();

        // Upper-cased on the way in, so a customer typing either case matches.
        $this->assertSame('WELCOME10', $coupon->code);
        $this->assertSame('Welcome', $coupon->name->en);
        $this->assertSame(0, $coupon->redemptions_count);
    }

    #[Test]
    public function a_percentage_over_one_hundred_is_refused(): void
    {
        $this->actingAs($this->superAdmin())->post('/admin/coupon/store', [
            'code' => 'TOOMUCH',
            'name' => ['en' => 'Too much'],
            'type' => Coupon::PERCENTAGE,
            'value' => 150,
            'max_per_user' => 1,
            'status' => 'active',
        ])->assertSessionHasErrors('value');

        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function a_duplicate_code_is_refused(): void
    {
        $this->coupon(['code' => 'TAKEN']);

        $this->actingAs($this->superAdmin())->post('/admin/coupon/store', [
            'code' => 'TAKEN',
            'name' => ['en' => 'Clash'],
            'type' => Coupon::FIXED,
            'value' => 5,
            'max_per_user' => 1,
            'status' => 'active',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Coupon::count());
    }

    #[Test]
    public function limits_can_be_cleared_again(): void
    {
        $coupon = $this->coupon(['code' => 'CAPPED', 'max_discount' => 30, 'max_redemptions' => 100]);

        $this->actingAs($this->superAdmin())->put("/admin/coupon/update/{$coupon->id}", [
            'code' => 'CAPPED',
            'name' => ['en' => 'Capped'],
            'type' => Coupon::FIXED,
            'value' => 10,
            'max_per_user' => 1,
            'status' => 'active',
            'max_discount' => '',
            'max_redemptions' => '',
        ])->assertRedirect();

        // A ceiling that can be set but never removed is a campaign nobody can
        // loosen.
        $coupon->refresh();
        $this->assertNull($coupon->max_discount);
        $this->assertNull($coupon->max_redemptions);
    }

    #[Test]
    public function a_used_code_cannot_be_deleted(): void
    {
        $coupon = $this->coupon(['code' => 'USED']);
        $order = $this->placedOrder();

        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'amount' => 10,
        ]);

        $this->actingAs($this->superAdmin())->delete("/admin/coupon/delete/{$coupon->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        // It is part of an order's history; deleting it would leave that order
        // pointing at nothing.
        $this->assertSame(1, Coupon::count());
    }

    #[Test]
    public function an_unused_code_can_be_deleted(): void
    {
        $coupon = $this->coupon(['code' => 'UNUSED']);

        $this->actingAs($this->superAdmin())->delete("/admin/coupon/delete/{$coupon->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function the_coupon_list_and_detail_render(): void
    {
        $coupon = $this->coupon(['code' => 'SHOWME']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/admin/coupon')->assertOk()->assertSee('SHOWME', false);
        $this->actingAs($admin)->get("/admin/coupon/show/{$coupon->id}")
            ->assertOk()
            ->assertSee(__('Who has used it'), false);
    }

    // -------------------------------------------------------------- refunds

    #[Test]
    public function the_queue_shows_what_is_waiting_and_what_is_stuck(): void
    {
        $refund = $this->pendingRefund();

        $this->actingAs($this->superAdmin())->get('/admin/refund')
            ->assertOk()
            ->assertSee(__('Under review'), false)
            // The case somebody has to chase.
            ->assertSee(__('Approved but unpaid'), false)
            ->assertSee(moneyFormat($refund->amount), false);
    }

    #[Test]
    public function approving_to_the_wallet_credits_the_customer(): void
    {
        $refund = $this->pendingRefund();

        $this->actingAs($this->superAdmin())->post("/admin/refund/approve/{$refund->id}", [
            'destination' => Refund::TO_WALLET,
            'note' => 'agreed',
        ])->assertRedirect()->assertSessionHas('success');

        $refund->refresh();
        $this->assertSame(Refund::SETTLED, $refund->status);

        $wallet = app(WalletService::class)->forUser($refund->customer)->fresh();
        $this->assertEquals((float) $refund->amount, (float) $wallet->balance);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function rejecting_moves_nothing(): void
    {
        $refund = $this->pendingRefund();

        $this->actingAs($this->superAdmin())->post("/admin/refund/reject/{$refund->id}",
            ['note' => 'not our fault'])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(Refund::REJECTED, $refund->fresh()->status);
        $this->assertSame(0, WalletTransaction::count());
    }

    #[Test]
    public function a_decided_request_cannot_be_decided_twice(): void
    {
        $refund = $this->pendingRefund();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/admin/refund/approve/{$refund->id}",
            ['destination' => Refund::TO_WALLET]);

        $this->actingAs($admin)->post("/admin/refund/reject/{$refund->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(Refund::SETTLED, $refund->fresh()->status);
    }

    #[Test]
    public function a_card_refund_with_no_card_payment_is_refused_with_advice(): void
    {
        // The order was paid in cash, so there is no card to send it back to.
        $refund = $this->pendingRefund();

        $this->actingAs($this->superAdmin())->post("/admin/refund/approve/{$refund->id}",
            ['destination' => Refund::TO_SOURCE])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(Refund::PENDING, $refund->fresh()->status);
    }

    #[Test]
    public function refunds_are_gated_on_their_permissions(): void
    {
        $refund = $this->pendingRefund();
        $this->grant('laundry_owner', ['laundry.view']);

        $this->actingAs($this->tenant['owner'])->get('/admin/refund')->assertForbidden();
        $this->actingAs($this->tenant['owner'])->post("/admin/refund/approve/{$refund->id}",
            ['destination' => Refund::TO_WALLET])->assertForbidden();
    }

    // -------------------------------------------------------------- wallets

    #[Test]
    public function the_wallet_list_reports_whether_the_ledger_balances(): void
    {
        $customer = $this->customer();
        app(WalletService::class)->credit($customer, 100, TransactionReason::TopUp);

        $this->actingAs($this->superAdmin())->get('/admin/wallet')
            ->assertOk()
            ->assertSee(__('Balanced'), false)
            ->assertSee(__('Out of balance'), false);
    }

    #[Test]
    public function a_drifted_wallet_is_called_out_rather_than_left_to_be_found(): void
    {
        $customer = $this->customer();
        $wallets = app(WalletService::class);
        $wallets->credit($customer, 100, TransactionReason::TopUp);

        // Simulate the fault this screen exists to surface.
        $wallet = $wallets->forUser($customer);
        $wallet->forceFill(['balance' => 999])->save();

        $this->assertFalse($wallet->fresh()->isReconciled());

        $this->actingAs($this->superAdmin())->get("/admin/wallet/show/{$wallet->id}")
            ->assertOk()
            ->assertSee(__('Does not match the ledger'), false);
    }

    #[Test]
    public function an_adjustment_writes_a_transaction_and_never_sets_a_balance(): void
    {
        $customer = $this->customer();
        $wallets = app(WalletService::class);
        $wallets->credit($customer, 50, TransactionReason::TopUp);
        $wallet = $wallets->forUser($customer);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/admin/wallet/adjust/{$wallet->id}", [
            'direction' => WalletTransaction::CREDIT,
            'amount' => 25,
            'note' => 'goodwill after a late delivery',
        ])->assertRedirect()->assertSessionHas('success');

        $wallet->refresh();
        $this->assertSame('75.00', $wallet->balance);
        $this->assertTrue($wallet->isReconciled());

        $adjustment = WalletTransaction::where('reason', TransactionReason::Adjustment->value)->firstOrFail();
        $this->assertSame('goodwill after a late delivery', $adjustment->note);
        // Recorded against the person who made it.
        $this->assertSame($admin->id, $adjustment->created_by);
    }

    #[Test]
    public function an_adjustment_without_a_reason_is_refused(): void
    {
        $customer = $this->customer();
        $wallet = app(WalletService::class)->forUser($customer);

        // An adjustment nobody explained is one nobody can defend later.
        $this->actingAs($this->superAdmin())->post("/admin/wallet/adjust/{$wallet->id}", [
            'direction' => WalletTransaction::CREDIT,
            'amount' => 25,
        ])->assertSessionHasErrors('note');

        $this->assertSame(0, WalletTransaction::count());
    }

    #[Test]
    public function an_adjustment_beyond_the_balance_is_refused(): void
    {
        $customer = $this->customer();
        $wallets = app(WalletService::class);
        $wallets->credit($customer, 20, TransactionReason::TopUp);
        $wallet = $wallets->forUser($customer);

        $this->actingAs($this->superAdmin())->post("/admin/wallet/adjust/{$wallet->id}", [
            'direction' => WalletTransaction::DEBIT,
            'amount' => 100,
            'note' => 'clawback',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('20.00', $wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::count());
    }

    #[Test]
    public function a_wallet_can_be_put_on_hold_and_released(): void
    {
        $wallet = app(WalletService::class)->forUser($this->customer());
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/admin/wallet/freeze/{$wallet->id}")->assertRedirect();
        $this->assertTrue($wallet->fresh()->is_frozen);

        $this->actingAs($admin)->post("/admin/wallet/freeze/{$wallet->id}")->assertRedirect();
        $this->assertFalse($wallet->fresh()->is_frozen);
    }

    #[Test]
    public function money_screens_are_gated_and_a_customer_cannot_reach_them(): void
    {
        $this->grant('laundry_owner', ['laundry.view']);

        $customer = $this->customer();

        foreach (['/admin/coupon', '/admin/refund', '/admin/wallet'] as $url) {
            $this->actingAs($this->tenant['owner'])->get($url)->assertForbidden();
            $this->actingAs($customer)->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function the_sidebar_offers_the_money_group_to_a_super_admin(): void
    {
        $response = $this->actingAs($this->superAdmin())->get('/admin/home');

        $response->assertOk();

        foreach ([__('Discount Codes'), __('Refunds'), __('Wallets')] as $label) {
            $response->assertSee($label, false);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + [
            'code' => 'TEST',
            'name' => json_encode(['en' => 'Test', 'ar' => 'تجربة'], JSON_UNESCAPED_UNICODE),
            'type' => Coupon::FIXED,
            'value' => 10,
            'max_per_user' => 1,
            'status' => 'active',
        ]);
    }

    private function placedOrder(): Order
    {
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    /** A paid order with an open refund request against it. */
    private function pendingRefund(): Refund
    {
        $order = $this->placedOrder();
        $customer = User::findOrFail($order->user_id);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $reviews = app(OrderReviewService::class);
        $reviews->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);
        $reviews->confirm($order->fresh(), $customer);

        $order->fresh()->update(['payment_status' => 'paid', 'paid_at' => now(), 'payment_method' => 'cash']);

        return app(RefundService::class)->request($order->fresh(), $customer, 25, 'damaged');
    }
}
