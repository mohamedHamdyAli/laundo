<?php

namespace Tests\Feature\Api;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Models\CouponRedemption;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Discount codes.
 *
 * «خصم الترحيب» has been in the design's own arithmetic since P6 — التنظيف 270 +
 * التوصيل 20 − الخصم 10 = 280 — while `orders.discount_total` had never once been
 * non-zero. This is what fills it.
 *
 * The rule these tests defend: **checking a code never spends it.** Most baskets a
 * code is asked about are never ordered.
 */
class CouponTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private User $customer;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    #[Test]
    public function a_fixed_code_takes_its_value_off(): void
    {
        $this->coupon(['code' => 'WELCOME10', 'type' => Coupon::FIXED, 'value' => 10]);

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/coupons/check',
            ['code' => 'WELCOME10', 'subtotal' => 100], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.discount', 10);
    }

    #[Test]
    public function a_percentage_is_capped_by_its_ceiling(): void
    {
        // A percentage without a ceiling is an open cheque on a large order.
        $this->coupon([
            'code' => 'HALF', 'type' => Coupon::PERCENTAGE, 'value' => 50, 'max_discount' => 30,
        ]);

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/coupons/check',
            ['code' => 'HALF', 'subtotal' => 40], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.discount', 20);

        $this->postJson('/api/v1/coupons/check',
            ['code' => 'HALF', 'subtotal' => 1000], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.discount', 30);
    }

    #[Test]
    public function a_delivery_code_discounts_the_trip_and_an_ordinary_one_does_not(): void
    {
        $this->coupon(['code' => 'FREEDEL', 'type' => Coupon::FIXED, 'value' => 20, 'applies_to_delivery' => true]);
        $this->coupon(['code' => 'PIECES', 'type' => Coupon::PERCENTAGE, 'value' => 100]);

        Sanctum::actingAs($this->customer);

        // 100 + 20 delivery, 100% off the pieces only.
        $this->postJson('/api/v1/coupons/check',
            ['code' => 'PIECES', 'subtotal' => 100, 'delivery_fee' => 20], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.discount', 100);

        $this->postJson('/api/v1/coupons/check',
            ['code' => 'FREEDEL', 'subtotal' => 100, 'delivery_fee' => 20], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.applies_to_delivery', true);
    }

    #[Test]
    public function every_reason_a_code_can_be_refused_is_reported(): void
    {
        Sanctum::actingAs($this->customer);

        $cases = [
            ['NOSUCH', [], 100],
            ['OFF', ['status' => 'inactive'], 100],
            ['SOON', ['starts_at' => now()->addWeek()], 100],
            ['GONE', ['ends_at' => now()->subDay()], 100],
            ['DRAINED', ['max_redemptions' => 1], 100],
            ['BIGONLY', ['min_order_total' => 500], 100],
        ];

        foreach ($cases as [$code, $attributes, $subtotal]) {
            if ($code !== 'NOSUCH') {
                $coupon = $this->coupon(['code' => $code] + $attributes);

                // redemptions_count is not fillable — the service owns it — so a
                // drained coupon is drained the way the application drains one.
                if ($code === 'DRAINED') {
                    $coupon->forceFill(['redemptions_count' => 1])->save();
                }
            }

            $this->postJson('/api/v1/coupons/check',
                ['code' => $code, 'subtotal' => $subtotal], $this->apiHeaders())
                ->assertStatus(422);
        }
    }

    #[Test]
    public function checking_a_code_never_spends_it(): void
    {
        $coupon = $this->coupon(['code' => 'WELCOME10', 'type' => Coupon::FIXED, 'value' => 10]);

        Sanctum::actingAs($this->customer);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/coupons/check',
                ['code' => 'WELCOME10', 'subtotal' => 100], $this->apiHeaders())->assertOk();
        }

        // Five screens, none of them an order.
        $this->assertSame(0, $coupon->fresh()->redemptions_count);
        $this->assertSame(0, CouponRedemption::count());
    }

    #[Test]
    public function placing_an_order_applies_and_spends_the_code(): void
    {
        $coupon = $this->coupon(['code' => 'WELCOME10', 'type' => Coupon::FIXED, 'value' => 10]);

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
            'coupon_code' => 'WELCOME10',
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        // 2 x 17 = 34, plus 20 delivery, minus 10.
        $this->assertSame('10.00', $order->discount_total);
        $this->assertSame('44.00', $order->estimated_total);
        $this->assertSame('WELCOME10', $order->coupon_code);

        $this->assertSame(1, $coupon->fresh()->redemptions_count);
        $this->assertDatabaseHas('coupon_redemptions', [
            'coupon_id' => $coupon->id, 'order_id' => $order->id, 'amount' => 10.00,
        ]);
    }

    #[Test]
    public function a_bad_code_does_not_stop_the_order(): void
    {
        Sanctum::actingAs($this->customer);

        // The customer asked for a discount, not for the order to fail.
        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
            'coupon_code' => 'NOSUCHCODE',
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('0.00', $order->discount_total);
        $this->assertNull($order->coupon_code);
    }

    #[Test]
    public function the_quote_shows_the_discount_and_explains_a_refusal(): void
    {
        $this->coupon(['code' => 'WELCOME10', 'type' => Coupon::FIXED, 'value' => 10]);

        Sanctum::actingAs($this->customer);

        $good = $this->postJson('/api/v1/orders/quote', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'coupon_code' => 'WELCOME10',
        ], $this->apiHeaders());

        $good->assertOk()->assertJsonPath('data.discount', 10);

        $bad = $this->postJson('/api/v1/orders/quote', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'coupon_code' => 'NOPE',
        ], $this->apiHeaders());

        $bad->assertOk()->assertJsonPath('data.discount', 0);
        $this->assertNotNull($bad->json('data.coupon_error'));
    }

    #[Test]
    public function one_customer_cannot_use_a_single_use_code_twice(): void
    {
        $this->coupon(['code' => 'ONCE', 'type' => Coupon::FIXED, 'value' => 5, 'max_per_user' => 1]);

        Sanctum::actingAs($this->customer);

        $payload = [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
            'coupon_code' => 'ONCE',
        ];

        $this->postJson('/api/v1/orders', $payload, $this->apiHeaders())->assertCreated();
        $this->postJson('/api/v1/orders', $payload, $this->apiHeaders())->assertCreated();

        $orders = Order::withoutGlobalScopes()->orderBy('id')->get();

        $this->assertSame('5.00', $orders[0]->discount_total);
        // The second order still happens — it just does not get the discount.
        $this->assertSame('0.00', $orders[1]->discount_total);
        $this->assertSame(1, CouponRedemption::count());
    }

    #[Test]
    public function cancelling_an_order_gives_the_redemption_back(): void
    {
        $coupon = $this->coupon(['code' => 'ONCE', 'type' => Coupon::FIXED, 'value' => 5, 'max_per_user' => 1]);

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
            'coupon_code' => 'ONCE',
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, $coupon->fresh()->redemptions_count);

        app(OrderService::class)->cancel($order, $this->customer, 'changed my mind');

        // An order cancelled before it was ever fulfilled should not have spent
        // the customer's one use of a welcome code.
        $this->assertSame(0, $coupon->fresh()->redemptions_count);
        $this->assertSame(0, CouponRedemption::count());
    }

    #[Test]
    public function checking_a_code_requires_a_token(): void
    {
        $this->postJson('/api/v1/coupons/check',
            ['code' => 'X', 'subtotal' => 10], $this->apiHeaders())->assertUnauthorized();
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + [
            'code' => 'TEST',
            'name' => json_encode(['en' => 'Test', 'ar' => 'تجربة'], JSON_UNESCAPED_UNICODE),
            'type' => Coupon::FIXED,
            'value' => 10,
            'max_per_user' => 5,
            'status' => 'active',
        ]);
    }
}
