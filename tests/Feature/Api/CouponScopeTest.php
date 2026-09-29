<?php

namespace Tests\Feature\Api;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Offer\Models\Offer;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «الأوفر على كاتيجوري معينة أو نوع خدمة معينة أو item معين» — a coupon, and so
 * the offer carrying it, limited to some services, categories or pieces. The
 * discount comes off only what it applies to.
 *
 * Shirts are 17, trousers 23, no customer fee.
 */
class CouponScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    private User $customer;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        Setting::updateOrCreate(['key' => 'Commission_Rate'], ['value' => '0']);
        Cache::flush();

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    private function coupon(array $attributes): Coupon
    {
        return Coupon::create($attributes + [
            'name' => json_encode(['en' => 'Test', 'ar' => 'تجربة'], JSON_UNESCAPED_UNICODE),
            'type' => Coupon::PERCENTAGE,
            'value' => 50,
            'max_per_user' => 5,
            'status' => 'active',
        ]);
    }

    private function shirt(): int
    {
        return $this->catalog['items'][0]->id;
    }

    private function trousers(): int
    {
        return $this->catalog['items'][1]->id;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $pieces  [item id, qty]
     * @return array<string, mixed>
     */
    private function quote(string $code, array $pieces, ?int $serviceId = null): array
    {
        Sanctum::actingAs($this->customer);

        return $this->postJson('/api/v1/orders/quote', [
            'service_id' => $serviceId ?? $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => array_map(fn ($p) => ['item_id' => $p[0], 'qty' => $p[1]], $pieces),
            'coupon_code' => $code,
        ], $this->apiHeaders())->assertOk()->json('data');
    }

    #[Test]
    public function a_code_on_particular_pieces_comes_off_only_those(): void
    {
        $this->coupon(['code' => 'SHIRTS', 'scope_type' => 'item', 'scope_ids' => [$this->shirt()]]);

        // Two shirts (34) and a pair of trousers (23): half of the shirts only.
        $quote = $this->quote('SHIRTS', [[$this->shirt(), 2], [$this->trousers(), 1]]);

        $this->assertEquals(17, $quote['discount']);
        $this->assertNull($quote['coupon_error']);
    }

    #[Test]
    public function a_code_on_a_category_needs_something_from_it_in_the_basket(): void
    {
        $bottoms = ItemCategory::create([
            'name' => json_encode(['en' => 'Bottoms', 'ar' => 'سفلي'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 2, 'status' => 'active',
        ]);
        $this->catalog['items'][1]->update(['item_category_id' => $bottoms->id]);

        $this->coupon(['code' => 'BOTTOMS', 'type' => Coupon::FIXED, 'value' => 10, 'scope_type' => 'category', 'scope_ids' => [$bottoms->id]]);

        // Shirts only: nothing in the order qualifies, and the customer is told.
        $none = $this->quote('BOTTOMS', [[$this->shirt(), 2]]);
        $this->assertEquals(0, $none['discount']);
        $this->assertSame(__('This code is for other pieces or another service — nothing in this order qualifies.'), $none['coupon_error']);

        // With trousers in it, the ten comes off.
        $this->assertEquals(10, $this->quote('BOTTOMS', [[$this->shirt(), 2], [$this->trousers(), 1]])['discount']);
    }

    #[Test]
    public function a_fixed_amount_is_never_more_than_the_pieces_it_applies_to(): void
    {
        $this->coupon(['code' => 'BIG', 'type' => Coupon::FIXED, 'value' => 100, 'scope_type' => 'item', 'scope_ids' => [$this->shirt()]]);

        // One shirt (17) among 23 of trousers: at most the 17.
        $this->assertEquals(17, $this->quote('BIG', [[$this->shirt(), 1], [$this->trousers(), 1]])['discount']);
    }

    #[Test]
    public function a_code_on_some_pieces_never_touches_the_delivery_fee(): void
    {
        // Even stored with the delivery box ticked, as an import might.
        $this->coupon([
            'code' => 'SHIRTFREE', 'type' => Coupon::PERCENTAGE, 'value' => 100,
            'applies_to_delivery' => true, 'scope_type' => 'item', 'scope_ids' => [$this->shirt()],
        ]);

        $quote = $this->quote('SHIRTFREE', [[$this->shirt(), 2]]);

        $this->assertEquals(34, $quote['discount']);
        $this->assertGreaterThan(0, (float) $quote['delivery_fee']);
    }

    #[Test]
    public function a_code_on_a_service_is_the_whole_order_of_that_service_only(): void
    {
        $this->coupon(['code' => 'WASH', 'scope_type' => 'service', 'scope_ids' => [$this->catalog['service']->id]]);
        $this->coupon(['code' => 'DRY', 'scope_type' => 'service', 'scope_ids' => [$this->catalog['quoted']->id]]);

        $this->assertEquals(20, $this->quote('WASH', [[$this->shirt(), 1], [$this->trousers(), 1]])['discount']);
        $this->assertEquals(0, $this->quote('DRY', [[$this->shirt(), 1]])['discount']);
    }

    #[Test]
    public function an_offer_carrying_a_limited_coupon_is_limited_too(): void
    {
        $coupon = $this->coupon(['code' => 'OFFERSHIRT', 'scope_type' => 'item', 'scope_ids' => [$this->shirt()]]);
        $offer = Offer::create([
            'title' => json_encode(['en' => 'Shirts half off'], JSON_UNESCAPED_UNICODE),
            'coupon_id' => $coupon->id, 'target_type' => 'none', 'status' => 'active',
        ]);

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->shirt(), 'qty' => 2], ['item_id' => $this->trousers(), 'qty' => 1]],
            'offer_id' => $offer->id,
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $this->assertSame('17.00', Order::withoutGlobalScopes()->latest('id')->value('discount_total'));

        // And the carousel says what it is on.
        $applies = $this->getJson('/api/v1/offers', $this->apiHeaders())->json('data.0.applies_to');
        $this->assertSame('item', $applies['type']);
        $this->assertSame(['Shirt'], $applies['names']);
    }

    #[Test]
    public function the_code_check_works_it_out_on_the_basket_when_it_is_sent(): void
    {
        $this->coupon(['code' => 'SHIRTS', 'scope_type' => 'item', 'scope_ids' => [$this->shirt()]]);
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/coupons/check', [
            'code' => 'SHIRTS',
            'service_id' => $this->catalog['service']->id,
            'items' => [['item_id' => $this->shirt(), 'qty' => 2], ['item_id' => $this->trousers(), 'qty' => 1]],
        ], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.discount', 17)
            ->assertJsonPath('data.applies_to.type', 'item')
            ->assertJsonPath('data.applies_to.names', ['Shirt']);

        // An app that sends only a subtotal is told why, not that the code is bad.
        $this->postJson('/api/v1/coupons/check', ['code' => 'SHIRTS', 'subtotal' => 100], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath('msg', __('This code applies to some pieces only. Add them to your order and it is worked out at checkout.'));
    }

    #[Test]
    public function a_code_on_the_whole_order_is_checked_as_before(): void
    {
        $this->coupon(['code' => 'ALL', 'type' => Coupon::FIXED, 'value' => 10]);
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/coupons/check', ['code' => 'ALL', 'subtotal' => 100], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.discount', 10)
            ->assertJsonPath('data.applies_to', null);
    }

    private function placeWith(string $code, array $pieces): Order
    {
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => array_map(fn ($p) => ['item_id' => $p[0], 'qty' => $p[1]], $pieces),
            'coupon_code' => $code,
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        return Order::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function review(Order $order, array $pieces): Order
    {
        $machine = app(OrderStateMachine::class);

        if ($order->status === OrderStatus::AwaitingPickup) {
            $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
            $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');
        }

        // A second count happens when the customer disputes the first.
        if ($order->status === OrderStatus::Reviewed) {
            $order = $machine->transition($order->fresh(), OrderStatus::ReviewDisputed, 'customer');
        }

        return app(OrderReviewService::class)->review(
            $order->fresh(),
            array_map(fn ($p) => ['item_id' => $p[0], 'qty' => $p[1]], $pieces),
            null,
            null,
        );
    }

    #[Test]
    public function the_review_works_a_limited_discount_out_again_on_the_pieces_counted(): void
    {
        $this->coupon(['code' => 'HALFSHIRTS', 'scope_type' => 'item', 'scope_ids' => [$this->shirt()]]);

        // Four shirts on the estimate: half of 68.
        $order = $this->placeWith('HALFSHIRTS', [[$this->shirt(), 4]]);
        $this->assertSame('34.00', $order->discount_total);
        $this->assertFalse($order->discount_covers_delivery);

        // The laundry counts one shirt and two pairs of trousers: half of 17.
        $order = $this->review($order, [[$this->shirt(), 1], [$this->trousers(), 2]]);
        $this->assertSame('8.50', $order->discount_total);

        // No shirts at all: no discount on trousers the code never covered.
        $order = $this->review($order, [[$this->trousers(), 3]]);
        $this->assertSame('0.00', $order->discount_total);

        // Counted again and the shirts were there after all: back to what was
        // agreed — and never above it.
        $order = $this->review($order, [[$this->shirt(), 6]]);
        $this->assertSame('34.00', $order->discount_total);
    }

    #[Test]
    public function a_code_on_some_pieces_is_never_recorded_as_covering_the_delivery(): void
    {
        // Stored with the box ticked, as an old import might have left it.
        $this->coupon([
            'code' => 'SHIRTDEL', 'type' => Coupon::FIXED, 'value' => 10,
            'applies_to_delivery' => true, 'scope_type' => 'item', 'scope_ids' => [$this->shirt()],
        ]);

        $order = $this->placeWith('SHIRTDEL', [[$this->shirt(), 2]]);

        // The settlement splits on this flag; true would move part of the
        // coupon's cost off the laundry.
        $this->assertFalse($order->discount_covers_delivery);
    }

    #[Test]
    public function an_import_cannot_tick_the_delivery_box_on_a_code_for_some_pieces(): void
    {
        $coupon = $this->coupon(['code' => 'SHEETSHIRT', 'scope_type' => 'item', 'scope_ids' => [$this->shirt()]]);

        // An edit that says nothing about the limit — as an import row does —
        // is checked against the limit the coupon already has.
        $this->actingAs($this->superAdmin())->put("/admin/coupon/update/{$coupon->id}", [
            'code' => 'SHEETSHIRT', 'type' => Coupon::PERCENTAGE, 'value' => 50,
            'max_per_user' => 5, 'status' => 'active', 'applies_to_delivery' => 1,
        ])->assertSessionHasErrors('applies_to_delivery');
    }

    #[Test]
    public function a_service_code_checked_without_the_service_says_so(): void
    {
        $this->coupon(['code' => 'WASHONLY', 'scope_type' => 'service', 'scope_ids' => [$this->catalog['service']->id]]);
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/coupons/check', ['code' => 'WASHONLY', 'subtotal' => 100], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath('msg', __('This code is for particular services. Choose the service and it is worked out at checkout.'));
    }

    #[Test]
    public function editing_a_coupon_keeps_a_piece_that_has_since_been_switched_off(): void
    {
        $coupon = $this->coupon(['code' => 'OLDPIECE', 'scope_type' => 'item', 'scope_ids' => [$this->shirt(), $this->trousers()]]);
        $this->catalog['items'][1]->update(['status' => 'inactive']);

        $html = $this->actingAs($this->superAdmin())->get("/admin/coupon/edit/{$coupon->id}")->assertOk()->getContent();

        // Still listed, and still selected — so saving the end date does not
        // quietly narrow the coupon to shirts.
        $this->assertMatchesRegularExpression('/<option value="'.$this->trousers().'"\s+selected/', $html);
    }

    #[Test]
    public function the_panel_saves_the_limit_and_refuses_what_makes_no_sense(): void
    {
        $admin = $this->superAdmin();
        $base = [
            'name' => ['en' => 'Shirts'], 'type' => Coupon::PERCENTAGE, 'value' => 20,
            'max_per_user' => 1, 'status' => 'active',
        ];

        $this->actingAs($admin)->post('/admin/coupon/store', $base + [
            'code' => 'PANELSHIRT', 'scope_type' => 'item', 'scope_ids' => [$this->shirt(), $this->trousers()],
        ])->assertRedirect();

        $coupon = Coupon::where('code', 'PANELSHIRT')->firstOrFail();
        $this->assertSame('item', $coupon->scope_type);
        $this->assertSame([$this->shirt(), $this->trousers()], $coupon->scopeIds());

        // A piece that does not exist.
        $this->actingAs($admin)->post('/admin/coupon/store', $base + [
            'code' => 'GHOST', 'scope_type' => 'item', 'scope_ids' => [999999],
        ])->assertSessionHasErrors('scope_ids');

        // A kind with nothing chosen.
        $this->actingAs($admin)->post('/admin/coupon/store', $base + [
            'code' => 'EMPTY', 'scope_type' => 'category',
        ])->assertSessionHasErrors('scope_ids');

        // Some pieces, and the delivery fee too.
        $this->actingAs($admin)->post('/admin/coupon/store', $base + [
            'code' => 'MIXED', 'scope_type' => 'item', 'scope_ids' => [$this->shirt()], 'applies_to_delivery' => 1,
        ])->assertSessionHasErrors('applies_to_delivery');

        // Back to the whole order: the list goes with the kind.
        $this->actingAs($admin)->put("/admin/coupon/update/{$coupon->id}", $base + [
            'code' => 'PANELSHIRT', 'scope_type' => '',
        ])->assertRedirect();

        $coupon->refresh();
        $this->assertNull($coupon->scope_type);
        $this->assertNull($coupon->scope_ids);
    }
}
