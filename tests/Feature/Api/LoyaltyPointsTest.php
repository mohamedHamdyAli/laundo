<?php

namespace Tests\Feature\Api;

use App\Modules\Address\Models\Address;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\Loyalty\Models\PointAccount;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Loyalty\Services\OrderPoints;
use App\Modules\Loyalty\Services\PointsService;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Payment\Services\SettlementService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * «النقاط» — earned per order and per hanger, spent as a discount, lapsing on
 * the owner's schedule.
 *
 * The owner's terms (2026-10-12): ten points an order, a point a pound, a point
 * a hanger, every figure a setting; the discount is the platform's, all of it;
 * points sit beside a coupon; hangers are counted at both doors and paid at
 * once; points expire.
 */
class LoyaltyPointsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        // The platform's wallet, for the settlement to pay into.
        $this->superAdmin();

        $this->points = app(PointsService::class);
        $this->set('Points_Enabled', '1');
    }

    // ------------------------------------------------------------ the ledger

    #[Test]
    public function every_change_is_a_row_and_the_balance_agrees_with_them(): void
    {
        $customer = $this->customer();

        $this->points->credit($customer, 30, PointReason::Adjustment, note: 'welcome');
        $this->points->debit($customer, 12, PointReason::Adjustment, note: 'correction');

        $account = PointAccount::where('user_id', $customer->id)->firstOrFail();

        $this->assertSame(18, $account->balance);
        $this->assertSame(18, $this->points->available($customer));
        $this->assertSame(2, PointTransaction::where('user_id', $customer->id)->count());
        $this->assertTrue($account->isReconciled());
        $this->assertSame(18, PointTransaction::where('user_id', $customer->id)->latest('id')->value('balance_after'));
    }

    #[Test]
    public function nobody_can_spend_points_they_do_not_have(): void
    {
        $customer = $this->customer();
        $this->points->credit($customer, 5, PointReason::Adjustment);

        try {
            $this->points->debit($customer, 6, PointReason::Adjustment);
            $this->fail('A spend larger than the balance went through.');
        } catch (RuntimeException $e) {
            $this->assertSame('insufficient_points', $e->getMessage());
        }

        $this->assertSame(5, PointAccount::where('user_id', $customer->id)->value('balance'));
        $this->assertSame(1, PointTransaction::where('user_id', $customer->id)->count());
    }

    #[Test]
    public function the_points_that_expire_first_are_spent_first(): void
    {
        $customer = $this->customer();

        $forever = $this->points->credit($customer, 10, PointReason::Adjustment, expiresAt: null);
        $late = $this->points->credit($customer, 10, PointReason::Adjustment, expiresAt: now()->addDays(60));
        $soon = $this->points->credit($customer, 10, PointReason::Adjustment, expiresAt: now()->addDays(5));

        $this->points->debit($customer, 15, PointReason::Adjustment);

        $this->assertSame(0, $soon->fresh()->remaining);
        $this->assertSame(5, $late->fresh()->remaining);
        $this->assertSame(10, $forever->fresh()->remaining);
        $this->assertTrue(PointAccount::where('user_id', $customer->id)->firstOrFail()->isReconciled());
    }

    #[Test]
    public function a_lapsed_point_cannot_be_spent_and_is_written_off_once(): void
    {
        $customer = $this->customer();
        $lot = $this->points->credit($customer, 20, PointReason::Adjustment, expiresAt: now()->addDays(2));

        $this->travel(3)->days();

        // Never counted once it has lapsed, whenever the write-off runs.
        $this->assertSame(0, $this->points->available($customer));

        try {
            $this->points->debit($customer, 1, PointReason::Adjustment);
            $this->fail('A lapsed point was spent.');
        } catch (RuntimeException $e) {
            $this->assertSame('insufficient_points', $e->getMessage());
        }

        // The spend wrote it off on its way, under the lock.
        $this->assertSame(0, $lot->fresh()->remaining);

        Artisan::call('loyalty:expire-points');
        Artisan::call('loyalty:expire-points');

        $this->assertSame(1, PointTransaction::where('reason', PointReason::Expired->value)->count());
        $this->assertSame(20, (int) PointTransaction::where('reason', PointReason::Expired->value)->value('points'));
        $this->assertSame(0, PointAccount::where('user_id', $customer->id)->value('balance'));
        $this->assertTrue(PointAccount::where('user_id', $customer->id)->firstOrFail()->isReconciled());
    }

    #[Test]
    public function the_expiry_is_stamped_on_the_points_when_they_are_earned(): void
    {
        $customer = $this->customer();
        $this->set('Points_Expiry_Days', '30');

        $lot = $this->points->credit($customer, 10, PointReason::Adjustment);

        // Changing the setting moves only what is earned afterwards.
        $this->set('Points_Expiry_Days', '');

        $this->assertNotNull($lot->fresh()->expires_at);
        $this->assertTrue($lot->fresh()->expires_at->greaterThan(now()->addDays(29)));
        $this->assertNull($this->points->credit($customer, 5, PointReason::Adjustment)->expires_at);
    }

    #[Test]
    public function an_automatic_credit_happens_once(): void
    {
        $customer = $this->customer();

        $this->assertNotNull($this->points->credit($customer, 10, PointReason::OrderEarned, key: 'order_earned:99'));
        $this->assertNull($this->points->credit($customer, 10, PointReason::OrderEarned, key: 'order_earned:99'));

        $this->assertSame(10, PointAccount::where('user_id', $customer->id)->value('balance'));
    }

    #[Test]
    public function returned_points_keep_their_expiry_and_never_exceed_the_spend(): void
    {
        $customer = $this->customer();
        $expiry = now()->addDays(40)->startOfSecond();
        $this->points->credit($customer, 30, PointReason::Adjustment, expiresAt: $expiry);

        $spend = $this->points->debit($customer, 30, PointReason::Redeemed);

        // Keep 20 spent, then none: 10 come back, then the other 20, then
        // nothing — whatever is asked, never more than went out.
        $this->assertSame(10, $this->points->giveBack($spend, 20));
        $this->assertSame(20, $this->points->giveBack($spend, 0));
        $this->assertSame(0, $this->points->giveBack($spend, 0));
        // Asked to keep more than is still spent returns nothing either.
        $this->assertSame(0, $this->points->giveBack($spend, 25));

        // Back with the time they had: the same business day, never earlier.
        $returned = PointTransaction::where('reverses_id', $spend->id)->get();
        $day = fn ($at) => $at->copy()->setTimezone(displayTimezone())->toDateString();
        $this->assertSame(30, (int) $returned->sum('points'));
        $this->assertTrue($returned->every(fn (PointTransaction $lot) => $day($lot->expires_at) === $day($expiry)
            && $lot->expires_at->greaterThanOrEqualTo($expiry)));
        $this->assertSame(30, $this->points->available($customer));
    }

    #[Test]
    public function points_that_lapsed_while_an_order_held_them_come_back_with_the_time_they_had(): void
    {
        $customer = $this->customer();
        $this->set('Points_Expiry_Days', '30');
        $this->points->credit($customer, 10, PointReason::Adjustment, expiresAt: now()->addDays(2));
        $spend = $this->points->debit($customer, 10, PointReason::Redeemed);

        $this->travel(5)->days();

        $this->points->giveBack($spend);

        // Not lost — and not renewed for thirty days either, which would let a
        // customer keep points alive for ever by placing and cancelling orders.
        $back = PointTransaction::where('reverses_id', $spend->id)->firstOrFail();
        $this->assertTrue($back->expires_at->isFuture());
        $this->assertTrue($back->expires_at->lessThanOrEqualTo(now()->addDays(3)));
        $this->assertSame(10, $this->points->available($customer));
    }

    #[Test]
    public function no_reminder_goes_out_while_the_programme_is_off(): void
    {
        $customer = $this->customer();
        $this->set('Points_Enabled', '0');
        $lot = $this->points->credit($customer, 30, PointReason::Adjustment, expiresAt: now()->addDays(3));

        Artisan::call('loyalty:expire-points');

        $this->assertSame(0, NotificationLog::where('event', NotificationEvent::PointsExpiring->value)->count());
        // Not used up either: switched back on, the lot is still to be told.
        $this->assertNull($lot->fresh()->expiry_warned_at);

        $this->set('Points_Enabled', '1');
        Artisan::call('loyalty:expire-points');

        $this->assertNotNull($lot->fresh()->expiry_warned_at);
    }

    #[Test]
    public function a_one_day_reminder_comes_the_day_before(): void
    {
        $customer = $this->customer();
        $this->set('Points_Expiry_Days', '1');
        $this->set('Points_Expiry_Warning_Days', '1');

        // Earned now with a one-day life: it lapses at the end of tomorrow,
        // so «1 day before» is today's run, not tomorrow's.
        $lot = $this->points->credit($customer, 20, PointReason::Adjustment);
        Artisan::call('loyalty:expire-points');

        $this->assertNotNull($lot->fresh()->expiry_warned_at);
    }

    #[Test]
    public function returned_points_already_announced_are_not_announced_again(): void
    {
        $customer = $this->customer();
        $lot = $this->points->credit($customer, 30, PointReason::Adjustment, expiresAt: now()->addDays(3));
        Artisan::call('loyalty:expire-points');
        $this->assertNotNull($lot->fresh()->expiry_warned_at);

        $spend = $this->points->debit($customer, 30, PointReason::Redeemed);
        $this->points->giveBack($spend);
        Artisan::call('loyalty:expire-points');

        $this->assertSame(1, NotificationLog::where('event', NotificationEvent::PointsExpiring->value)
            ->where('user_id', $customer->id)->where('channel', 'database')->count());
    }

    #[Test]
    public function points_handed_back_on_their_last_day_keep_the_time_they_had(): void
    {
        $customer = $this->customer();
        $lotEnd = now()->addDays(6);
        $lot = $this->points->credit($customer, 50, PointReason::Adjustment, expiresAt: $lotEnd);
        $spend = $this->points->debit($customer, 50, PointReason::Redeemed);

        // Back an hour before the lot's own end: they must not lapse within
        // the hour — the order held them for five days and change.
        $this->travelTo($lotEnd->copy()->subHour());
        $this->points->giveBack($spend);

        $back = PointTransaction::where('reverses_id', $spend->id)->firstOrFail();
        $this->assertTrue($back->expires_at->greaterThan(now()->addDays(5)));
        $this->assertNull($back->expiry_warned_at);
        $this->assertSame(50, $this->points->available($customer));
    }

    #[Test]
    public function a_credit_after_points_lapsed_records_the_balance_the_customer_really_has(): void
    {
        $customer = $this->customer();
        $this->points->credit($customer, 30, PointReason::Adjustment, expiresAt: now()->addDay());

        $this->travel(2)->days();
        $credit = $this->points->credit($customer, 10, PointReason::Adjustment, expiresAt: null);

        // Not 40: the 30 lapsed before the 10 arrived, and the push quotes this.
        $this->assertSame(10, $credit->balance_after);
        $this->assertSame(1, PointTransaction::where('reason', PointReason::Expired->value)->count());
        $this->assertTrue(PointAccount::where('user_id', $customer->id)->firstOrFail()->isReconciled());
    }

    #[Test]
    public function a_closed_accounts_points_still_lapse(): void
    {
        $customer = $this->customer();
        $this->points->credit($customer, 30, PointReason::Adjustment, expiresAt: now()->addDay());
        $customer->delete();

        $this->travel(2)->days();
        Artisan::call('loyalty:expire-points');

        $this->assertSame(0, PointAccount::where('user_id', $customer->id)->value('balance'));
        $this->assertSame(1, PointTransaction::where('reason', PointReason::Expired->value)->count());
    }

    // ------------------------------------------------------------ checkout

    #[Test]
    public function the_quote_offers_the_points_and_spends_them_only_when_asked(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 40, PointReason::Adjustment);
        Sanctum::actingAs($customer);

        // 3 shirts at 17 = 51.
        $plain = $this->postJson('/api/v1/orders/quote', $this->basket($address), $this->apiHeaders())->assertOk();

        $this->assertSame(40, $plain->json('data.points.available'));
        $this->assertSame(40, $plain->json('data.points.redeemable'));
        $this->assertSame(0, $plain->json('data.points.used'));
        $this->assertSame(10, $plain->json('data.points.to_earn'));

        $spent = $this->postJson('/api/v1/orders/quote', $this->basket($address) + ['use_points' => true], $this->apiHeaders())->assertOk();

        $this->assertSame(40, $spent->json('data.points.used'));
        $this->assertEquals(40.0, $spent->json('data.points.discount'));
        $this->assertEqualsWithDelta((float) $plain->json('data.total') - 40, (float) $spent->json('data.total'), 0.001);

        // A quote spends nothing.
        $this->assertSame(40, $this->points->available($customer));
    }

    #[Test]
    public function points_come_off_what_the_coupon_left_and_stop_at_the_cap(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 100, PointReason::Adjustment);
        $this->set('Points_Max_Redeem_Percent', '50');
        Coupon::create([
            'code' => 'TEN', 'name' => json_encode(['en' => 'Ten', 'ar' => 'عشرة'], JSON_UNESCAPED_UNICODE),
            'type' => Coupon::FIXED, 'value' => 10, 'max_per_user' => 5, 'status' => 'active',
        ]);
        Sanctum::actingAs($customer);

        $quote = $this->postJson('/api/v1/orders/quote', $this->basket($address) + [
            'coupon_code' => 'TEN', 'use_points' => true,
        ], $this->apiHeaders())->assertOk();

        // 51 - 10 = 41 of washing; half of it is 20.5, so 20 whole points.
        $this->assertEquals(10.0, $quote->json('data.discount'));
        $this->assertSame(20, $quote->json('data.points.used'));
        $this->assertEquals(20.0, $quote->json('data.points.discount'));
    }

    #[Test]
    public function a_point_worth_more_than_a_pound_buys_fewer_of_them(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 100, PointReason::Adjustment);
        $this->set('Point_Value', '2.5');
        Sanctum::actingAs($customer);

        $quote = $this->postJson('/api/v1/orders/quote', $this->basket($address) + ['use_points' => true], $this->apiHeaders())->assertOk();

        // 51 / 2.5 = 20.4 → 20 points, 50 off.
        $this->assertSame(20, $quote->json('data.points.used'));
        $this->assertEquals(50.0, $quote->json('data.points.discount'));
    }

    #[Test]
    public function a_balance_under_the_minimum_cannot_be_spent(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 40, PointReason::Adjustment);
        $this->set('Points_Min_Redeem', '50');
        Sanctum::actingAs($customer);

        $quote = $this->postJson('/api/v1/orders/quote', $this->basket($address) + ['use_points' => true], $this->apiHeaders())->assertOk();

        $this->assertSame(0, $quote->json('data.points.used'));
        $this->assertSame(0, $quote->json('data.points.redeemable'));
    }

    #[Test]
    public function with_the_programme_off_nothing_is_spent_or_promised(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 40, PointReason::Adjustment);
        $this->set('Points_Enabled', '0');

        $order = $this->place($customer, $address, ['use_points' => true]);

        $this->assertSame(0, $order->points_used);
        $this->assertSame(0, $order->points_to_earn);
        $this->assertNull($order->points_terms);
        $this->assertSame(40, $this->points->available($customer));
    }

    #[Test]
    public function placing_an_order_takes_the_points_and_stamps_the_terms(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 30, PointReason::Adjustment);

        $order = $this->place($customer, $address, ['use_points' => true]);

        $this->assertSame(30, $order->points_used);
        $this->assertSame('30.00', $order->points_discount);
        $this->assertSame(10, $order->points_to_earn);
        $this->assertEquals(1.0, $order->points_terms['point_value']);
        $this->assertEqualsWithDelta(
            (float) $order->estimated_subtotal + (float) $order->delivery_fee - 30,
            (float) $order->estimated_total,
            0.001,
        );

        $this->assertSame(0, $this->points->available($customer));
        $this->assertDatabaseHas('point_transactions', [
            'user_id' => $customer->id, 'reason' => 'redeemed', 'points' => 30, 'dedupe_key' => 'redeemed:'.$order->id,
        ]);
    }

    #[Test]
    public function points_spent_meanwhile_refuse_the_order(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 30, PointReason::Adjustment);
        $order = $this->place($customer, $address, ['use_points' => true]);

        // The same terms again, with the balance already gone — what a second
        // checkout racing the first sees once the first has the lock.
        $twin = $order->replicate(['code', 'qr_token']);

        try {
            app(OrderPoints::class)->spend($twin->forceFill(['id' => 999999]), $customer);
            $this->fail('An order was allowed to spend points that were gone.');
        } catch (RuntimeException $e) {
            $this->assertSame('points_changed', $e->getMessage());
        }
    }

    // ------------------------------------------------------------ cancel, return, review

    #[Test]
    public function a_cancelled_order_gives_every_point_back(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 30, PointReason::Adjustment);
        $order = $this->place($customer, $address, ['use_points' => true]);

        Sanctum::actingAs($customer);
        $this->putJson("/api/v1/orders/{$order->id}/cancel", [], $this->apiHeaders())->assertOk();

        $this->assertSame(30, $this->points->available($customer));
        $this->assertSame(1, PointTransaction::where('reason', PointReason::RedemptionReturned->value)->count());
        $this->assertTrue(PointAccount::where('user_id', $customer->id)->firstOrFail()->isReconciled());
    }

    #[Test]
    public function deleting_an_uncollected_order_gives_its_points_back(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 30, PointReason::Adjustment);
        $order = $this->place($customer, $address, ['use_points' => true]);
        $this->assertSame(0, $this->points->available($customer));

        app(\App\Modules\Order\Services\orderCrudService::class)->deleteRecord($order->id);

        $this->assertNull(Order::withoutGlobalScopes()->find($order->id));
        $this->assertSame(30, $this->points->available($customer));
    }

    #[Test]
    public function an_order_returned_by_operations_gives_them_back_too(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 30, PointReason::Adjustment);
        $order = $this->place($customer, $address, ['use_points' => true]);
        $order->forceFill(['status' => OrderStatus::ReviewDisputed])->save();

        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::Returned, 'admin', $this->superAdmin());

        $this->assertSame(30, $this->points->available($customer));
    }

    #[Test]
    public function fewer_pieces_counted_hand_the_extra_points_back(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 60, PointReason::Adjustment);

        // 3 shirts, 51 — all 51 paid in points.
        $order = $this->place($customer, $address, ['use_points' => true]);
        $this->assertSame(51, $order->points_used);
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();

        // One shirt arrived.
        app(OrderReviewService::class)->review($order->fresh(), [
            ['item_id' => $this->catalog['items'][0]->id, 'qty' => 1],
        ]);

        $order->refresh();

        $this->assertSame(17, $order->points_used);
        $this->assertSame('17.00', $order->points_discount);
        $this->assertEqualsWithDelta((float) $order->delivery_fee, (float) $order->final_total, 0.001);
        // 60 - 51 + 34 back.
        $this->assertSame(43, $this->points->available($customer));

        // A second count that finds them after all does not take points again.
        $order->forceFill(['status' => OrderStatus::ReviewDisputed])->save();
        app(OrderReviewService::class)->review($order->fresh(), [
            ['item_id' => $this->catalog['items'][0]->id, 'qty' => 3],
        ]);

        $this->assertSame(17, $order->fresh()->points_used);
        $this->assertSame(43, $this->points->available($customer));
    }

    // ------------------------------------------------------------ earning

    #[Test]
    public function a_completed_order_pays_its_points_once_and_says_so(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->place($customer, $address);

        $this->complete($order);
        // A replayed completion.
        app(OrderPoints::class)->earn($order->fresh());

        $this->assertSame(10, $this->points->available($customer));
        $this->assertSame(1, PointTransaction::where('dedupe_key', 'order_earned:'.$order->id)->count());
        $this->assertTrue(NotificationLog::where('event', NotificationEvent::PointsEarned->value)->where('user_id', $customer->id)->exists());
    }

    #[Test]
    public function an_order_under_the_minimum_is_promised_nothing_and_earns_nothing(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->set('Points_Min_Order_To_Earn', '100');
        Sanctum::actingAs($customer);

        // 51 of washing, under the 100 the owner set: the checkout must not
        // show «+10» that completion would refuse.
        $this->postJson('/api/v1/orders/quote', $this->basket($address), $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.points.to_earn', 0);

        $order = $this->place($customer, $address);
        $this->assertSame(0, $order->points_to_earn);

        $this->complete($order);

        $this->assertSame(0, $this->points->available($customer));
    }

    #[Test]
    public function a_coupon_that_also_covers_delivery_leaves_more_washing_for_points(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 100, PointReason::Adjustment);
        Coupon::create([
            'code' => 'RIDE', 'name' => json_encode(['en' => 'Ride', 'ar' => 'توصيل'], JSON_UNESCAPED_UNICODE),
            'type' => Coupon::FIXED, 'value' => 30, 'max_per_user' => 5, 'status' => 'active',
            'applies_to_delivery' => true,
        ]);
        Sanctum::actingAs($customer);

        $quote = $this->postJson('/api/v1/orders/quote', $this->basket($address) + [
            'coupon_code' => 'RIDE', 'use_points' => true,
        ], $this->apiHeaders())->assertOk();

        $subtotal = (float) $quote->json('data.subtotal');
        $fee = (float) $quote->json('data.delivery_fee');
        $discount = (float) $quote->json('data.discount');
        $onPieces = round($discount * $subtotal / ($subtotal + $fee), 2);

        // The coupon's delivery part does not shrink the washing points pay for.
        $this->assertSame((int) floor($subtotal - $onPieces + 1e-9), $quote->json('data.points.used'));
    }

    #[Test]
    public function use_points_sent_as_text_in_a_multipart_body_is_understood(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 20, PointReason::Adjustment);
        Sanctum::actingAs($customer);

        // An order with stain photos is multipart: every value is a string.
        $this->postJson('/api/v1/orders/quote', $this->basket($address) + ['use_points' => 'true'], $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.points.used', 20);

        // And "false" must not spend them — `empty('false')` is not empty.
        $this->postJson('/api/v1/orders/quote', $this->basket($address) + ['use_points' => 'false'], $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.points.used', 0);
    }

    #[Test]
    public function a_count_under_the_minimum_stops_promising_points(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->set('Points_Min_Order_To_Earn', '50');

        // 3 shirts, 51: over the minimum, so 10 are promised.
        $order = $this->place($customer, $address);
        $this->assertSame(10, $order->points_to_earn);
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();

        // Two arrive: 34, under it.
        app(OrderReviewService::class)->review($order->fresh(), [
            ['item_id' => $this->catalog['items'][0]->id, 'qty' => 2],
        ]);

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.points.to_earn', 0);
    }

    #[Test]
    public function a_page_size_below_one_is_still_a_page(): void
    {
        $customer = $this->customer();

        foreach (range(1, 3) as $i) {
            $this->points->credit($customer, $i, PointReason::Adjustment);
        }

        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/points/transactions?per_page=-1', $this->apiHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_points_promised_at_checkout_are_the_points_paid(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->place($customer, $address);

        // The owner changes the figure, or switches the programme off, while
        // the order is under way. The customer was shown ten.
        $this->set('Points_Per_Order', '50');
        $this->set('Points_Enabled', '0');

        $this->complete($order);

        $this->assertSame(10, $this->points->available($customer));
    }

    #[Test]
    public function a_cancelled_order_earns_nothing(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->place($customer, $address);

        app(OrderService::class)->cancel($order, $customer);

        $this->assertSame(0, PointTransaction::where('reason', PointReason::OrderEarned->value)->count());
    }

    // ------------------------------------------------------------ the settlement

    #[Test]
    public function the_laundry_is_paid_as_if_there_were_no_points(): void
    {
        $this->set('Laundry_Share_Rate', '80');
        [$customer, $address] = $this->customerWithAddress();

        $plain = $this->place($customer, $address);
        $this->points->credit($customer, 30, PointReason::Adjustment);
        $withPoints = $this->place($customer, $address, ['use_points' => true]);

        $settlements = app(SettlementService::class);
        $a = $settlements->recordFor($plain->fresh());
        $b = $settlements->recordFor($withPoints->fresh());

        $this->assertSame($a->laundry_amount, $b->laundry_amount);
        $this->assertEqualsWithDelta((float) $a->commission_amount - 30, (float) $b->commission_amount, 0.001);
        $this->assertSame('30.00', $b->points_discount_amount);
        $this->assertTrue($b->reconciles());
    }

    #[Test]
    public function points_paying_for_all_the_washing_still_record_a_settlement(): void
    {
        // Found walking the development data: a basis of 0.55 against a
        // platform part of -43.90 is -7,981.82%, past what decimal(5,2) held,
        // and MySQL refused the insert. SQLite stores anything and reports no
        // precision, so the honest rate is asserted here and the widened
        // column (`2026_10_12_100200`) was checked on MySQL.
        $this->assertSame(-7981.82, app(SettlementService::class)->effectiveRate(0.55, -43.9));
        $this->assertSame(10.0, app(SettlementService::class)->effectiveRate(100.0, 10.0));
    }

    // ------------------------------------------------------------ hangers

    #[Test]
    public function hangers_handed_back_at_pickup_earn_points_at_once(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->place($customer, $address);
        [$driver, $task] = $this->startedLeg($order, TaskType::PickupFromCustomer);

        Sanctum::actingAs($driver);
        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", [
            'piece_count' => 3,
            'hangers_returned' => 4,
        ], $this->apiHeaders())->assertOk()->assertJsonPath('data.hangers_returned', 4);

        $this->assertSame(4, $task->fresh()->hangers_returned);
        $this->assertSame(4, $this->points->available($customer));
        $this->assertDatabaseHas('point_transactions', [
            'user_id' => $customer->id, 'reason' => 'hangers_returned', 'dedupe_key' => 'hangers:'.$task->id,
        ]);
    }

    #[Test]
    public function the_hanger_rate_is_a_setting(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->set('Points_Per_Hanger', '3');
        $order = $this->place($customer, $address);
        [$driver, $task] = $this->startedLeg($order, TaskType::PickupFromCustomer);

        Sanctum::actingAs($driver);
        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", [
            'piece_count' => 3, 'hangers_returned' => 2,
        ], $this->apiHeaders())->assertOk();

        $this->assertSame(6, $this->points->available($customer));
    }

    #[Test]
    public function more_hangers_than_a_visit_allows_are_refused(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->set('Max_Hangers_Per_Visit', '10');
        $order = $this->place($customer, $address);
        [$driver, $task] = $this->startedLeg($order, TaskType::PickupFromCustomer);

        Sanctum::actingAs($driver);
        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", [
            'piece_count' => 3, 'hangers_returned' => 11,
        ], $this->apiHeaders())->assertStatus(422)->assertJsonValidationErrors('hangers_returned', 'errors');

        $this->assertSame(TaskStatus::Started, $task->fresh()->status);
        $this->assertSame(0, $this->points->available($customer));
    }

    #[Test]
    public function hangers_are_not_asked_for_at_the_laundry(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->place($customer, $address);
        $order->forceFill(['status' => OrderStatus::Confirmed])->save();
        [$driver, $task] = $this->startedLeg($order, TaskType::DeliverToLaundry);

        Sanctum::actingAs($driver);
        $this->getJson("/api/v1/driver/tasks/{$task->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.accepts_hangers', false)
            // An integer on every leg, never null: it is a setting.
            ->assertJsonPath('data.max_hangers', 50);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", [
            'piece_count' => 3, 'hangers_returned' => 5, 'receiver_name' => 'محمود',
        ], $this->apiHeaders())->assertOk();

        $this->assertNull($task->fresh()->hangers_returned);
        $this->assertSame(0, $this->points->available($customer));
    }

    #[Test]
    public function with_the_programme_off_hangers_are_counted_but_pay_nothing(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->place($customer, $address);
        $this->set('Points_Enabled', '0');
        [$driver, $task] = $this->startedLeg($order, TaskType::PickupFromCustomer);

        Sanctum::actingAs($driver);
        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", [
            'piece_count' => 3, 'hangers_returned' => 4,
        ], $this->apiHeaders())->assertOk();

        $this->assertSame(4, $task->fresh()->hangers_returned);
        $this->assertSame(0, $this->points->available($customer));
    }

    // ------------------------------------------------------------ the customer's screens

    #[Test]
    public function the_customer_sees_their_balance_its_worth_and_the_next_expiry(): void
    {
        $customer = $this->customer();
        $this->set('Point_Value', '0.5');
        $this->points->credit($customer, 30, PointReason::Adjustment, expiresAt: now()->addDays(10));
        $this->points->credit($customer, 10, PointReason::Adjustment, expiresAt: null);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/points', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.balance', 40)
            ->assertJsonPath('data.balance_value', 20)
            ->assertJsonPath('data.next_expiry.points', 30)
            ->assertJsonPath('data.rules.per_order', 10);
    }

    #[Test]
    public function the_history_names_the_order_and_filters_by_tab(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 30, PointReason::Adjustment);
        $order = $this->place($customer, $address, ['use_points' => true]);
        Sanctum::actingAs($customer);

        $all = $this->getJson('/api/v1/points/transactions', $this->apiHeaders())->assertOk();
        $this->assertSame(-30, $all->json('data.0.points'));
        $this->assertSame($order->code, $all->json('data.0.order_code'));

        $spent = $this->getJson('/api/v1/points/transactions?group=spent', $this->apiHeaders())->assertOk();
        $this->assertCount(1, $spent->json('data'));
        $this->assertSame('redeemed', $spent->json('data.0.reason'));
    }

    #[Test]
    public function nobody_reads_another_customers_points(): void
    {
        $owner = $this->customer('+201099880001');
        $other = $this->customer('+201099880002');
        $this->points->credit($owner, 30, PointReason::Adjustment);
        Sanctum::actingAs($other);

        $this->getJson('/api/v1/points', $this->apiHeaders())->assertOk()->assertJsonPath('data.balance', 0);
        $this->getJson('/api/v1/points/transactions', $this->apiHeaders())->assertOk()->assertJsonCount(0, 'data');
        // Reading created nothing.
        $this->assertSame(0, PointAccount::where('user_id', $other->id)->count());
    }

    #[Test]
    public function the_order_says_what_it_spent_and_what_it_earned(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $this->points->credit($customer, 30, PointReason::Adjustment);
        $order = $this->place($customer, $address, ['use_points' => true]);
        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.pricing.points_used', 30)
            ->assertJsonPath('data.points.to_earn', 10)
            ->assertJsonPath('data.points.earned', 0);
    }

    // ------------------------------------------------------------ expiry reminders

    #[Test]
    public function the_customer_is_reminded_once_before_points_lapse(): void
    {
        $customer = $this->customer();
        $this->set('Points_Expiry_Warning_Days', '7');
        $lot = $this->points->credit($customer, 25, PointReason::Adjustment, expiresAt: now()->addDays(3));
        $later = $this->points->credit($customer, 5, PointReason::Adjustment, expiresAt: now()->addDays(30));

        Artisan::call('loyalty:expire-points');
        Artisan::call('loyalty:expire-points');

        $this->assertSame(1, NotificationLog::where('event', NotificationEvent::PointsExpiring->value)
            ->where('user_id', $customer->id)->where('channel', 'database')->count());
        $this->assertNotNull($lot->fresh()->expiry_warned_at);
        $this->assertNull($later->fresh()->expiry_warned_at);
    }

    // ------------------------------------------------------------ helpers

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);

        // getSettingValue caches for ever.
        Cache::flush();
    }

    /**
     * @return array{0: User, 1: Address}
     */
    private function customerWithAddress(): array
    {
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][0]);
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        return [$customer, $address];
    }

    /**
     * @return array<string, mixed>
     */
    private function basket(Address $address, int $shirts = 3): array
    {
        return [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => $shirts]],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function place(User $customer, Address $address, array $extra = []): Order
    {
        $order = app(OrderService::class)->place($customer, $this->basket($address) + $extra);

        return Order::withoutGlobalScopes()->findOrFail($order->id);
    }

    /**
     * Straight to the end: delivered and paid, which is what completes it.
     */
    private function complete(Order $order): void
    {
        $order = Order::withoutGlobalScopes()->findOrFail($order->id);
        $order->forceFill([
            'status' => OrderStatus::Delivered,
            'payment_status' => 'paid',
            'paid_at' => now(),
        ])->save();

        app(OrderStateMachine::class)->completeIfPaid($order->fresh());

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    /**
     * One of the order's own legs, assigned and started, with the ones before
     * it already done.
     *
     * @return array{0: User, 1: OrderTask}
     */
    private function startedLeg(Order $order, TaskType $type): array
    {
        static $phone = 0;
        $driver = $this->driverUser('+2010333300'.str_pad((string) (++$phone), 2, '0', STR_PAD_LEFT));

        // On the way to collect, as starting the first leg would have left it.
        if ($type === TaskType::PickupFromCustomer) {
            $order->forceFill(['status' => OrderStatus::DriverOnWay])->save();
        }

        $tasks = OrderTask::where('order_id', $order->id)->orderBy('sequence')->get();

        foreach ($tasks as $task) {
            if ($task->type === $type) {
                $task->forceFill([
                    'driver_id' => $driver->id,
                    'status' => TaskStatus::Started,
                    'started_at' => now(),
                    'signature_path' => $type->requiresSignature() ? 'signatures/test.png' : null,
                ])->save();

                return [$driver, $task->fresh()];
            }

            $task->forceFill([
                'driver_id' => $driver->id,
                'status' => TaskStatus::Completed,
                'completed_at' => now(),
                'signature_path' => 'signatures/test.png',
            ])->save();
        }

        $this->fail("The order has no {$type->value} leg.");
    }
}
