<?php

namespace Tests\Feature\Api;

use App\Modules\Address\Models\Address;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Offer\Models\Offer;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The customer's order endpoints.
 */
class OrderTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        // A priced zone, or every fee comes back unknown.
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
    }

    #[Test]
    public function a_quote_prices_the_basket_without_saving_anything(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/quote', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [
                ['item_id' => $this->catalog['items'][0]->id, 'qty' => 2],
                ['item_id' => $this->catalog['items'][1]->id, 'qty' => 1],
            ],
        ], $this->apiHeaders());

        $response->assertOk();

        // 2 x 17 + 1 x 23 = 57.
        // assertEquals, not assertSame: PHP encodes a whole float as `57`, so the
        // decoded value is an int. That is ordinary JSON behaviour, not a bug —
        // the assertion should not pin down a type the format does not preserve.
        $this->assertEquals(57.0, $response->json('data.subtotal'));
        $this->assertSame(3, $response->json('data.items_count'));
        $this->assertNotNull($response->json('data.delivery_fee'));
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_quote_reports_an_unknown_fee_instead_of_a_free_delivery(): void
    {
        [$customer, $address] = $this->customerWithAddress(cover: false);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/quote', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders());

        $response->assertOk();
        $this->assertNull($response->json('data.delivery_fee'));
        $this->assertSame('no_laundry_assigned', $response->json('data.delivery_fee_reason'));
        $this->assertNull($response->json('data.laundry'));
    }

    #[Test]
    public function placing_an_order_copies_the_prices_onto_it(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 3]],
            'pickup_date' => now()->addDay()->toDateString(),
            'accepts_review_terms' => true,
        ], $this->apiHeaders());

        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(OrderStatus::AwaitingPickup, $order->status);
        $this->assertSame(3, $order->estimated_items_count);
        $this->assertSame('51.00', $order->estimated_subtotal);
        $this->assertNotEmpty($order->qr_token);

        // Now move the price matrix. The order must not follow.
        ItemPrice::where('item_id', $this->catalog['items'][0]->id)->update(['price' => 99]);

        $this->assertSame('17.00', OrderItem::where('order_id', $order->id)->value('unit_price'));
        $this->assertSame('51.00', $order->fresh()->estimated_subtotal);
    }

    #[Test]
    public function placing_an_order_opens_its_history(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'awaiting_pickup',
            'actor_type' => 'customer',
            'actor_id' => $customer->id,
        ]);
    }

    #[Test]
    public function an_order_is_accepted_even_when_no_laundry_covers_it(): void
    {
        [$customer, $address] = $this->customerWithAddress(cover: false);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        // Accepted by decision, and left for operations to place.
        $this->assertNull($order->laundry_id);
        $this->assertSame('0.00', $order->delivery_fee);
    }

    #[Test]
    public function a_piece_the_service_cannot_price_is_refused_not_given_away(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        Sanctum::actingAs($customer);

        // Priced for the per-item service, but this basket asks for the quoted one
        // — where these pieces have no price at all.
        $unpriced = $this->catalog['items'][0];
        ItemPrice::where('item_id', $unpriced->id)->delete();

        $response = $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $unpriced->id, 'qty' => 1]],
        ], $this->apiHeaders());

        $response->assertStatus(422);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function an_empty_basket_is_refused_for_a_per_item_service(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertStatus(422);
    }

    #[Test]
    public function a_quote_priced_service_may_be_ordered_with_no_basket(): void
    {
        [$customer, $address] = $this->customerWithAddress(serviceId: null);
        $this->cover(
            Laundry::withoutGlobalScopes()->firstOrFail(),
            $this->geo['zones'][0]->id,
            $this->catalog['quoted']->id
        );

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['quoted']->id,
            'pickup_address_id' => $address->id,
            'items' => [],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(0, $order->estimated_items_count);
        $this->assertSame('0.00', $order->estimated_subtotal);
    }

    #[Test]
    public function a_customer_cannot_order_to_another_customers_address(): void
    {
        [$customer] = $this->customerWithAddress();
        $stranger = $this->customer('+201088776655');
        $theirAddress = $this->addressFor($stranger, $this->geo['zones'][0]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $theirAddress->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertNotFound();

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_customer_cannot_read_another_customers_order(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $stranger = $this->customer('+201088776655');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/track", $this->apiHeaders())->assertNotFound();
        $this->putJson("/api/v1/orders/{$order->id}/cancel", [], $this->apiHeaders())->assertNotFound();
    }

    #[Test]
    public function the_tabs_split_orders_the_way_the_design_does(): void
    {
        [$customer, $address] = $this->customerWithAddress();

        $active = $this->makeOrder($customer, $address);
        $done = $this->makeOrder($customer, $address);
        $gone = $this->makeOrder($customer, $address);

        $done->update(['status' => OrderStatus::Completed]);
        $gone->update(['status' => OrderStatus::Cancelled]);

        Sanctum::actingAs($customer);

        $this->assertCount(3, $this->getJson('/api/v1/orders?tab=all', $this->apiHeaders())->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/orders?tab=active', $this->apiHeaders())->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/orders?tab=completed', $this->apiHeaders())->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/orders?tab=cancelled', $this->apiHeaders())->json('data'));

        $this->assertSame($active->code, $this->getJson('/api/v1/orders?tab=active', $this->apiHeaders())->json('data.0.code'));
    }

    #[Test]
    public function cancelling_is_allowed_before_pickup_and_refused_after(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->makeOrder($customer, $address);

        Sanctum::actingAs($customer);

        $this->putJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'changed my mind'], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'to_status' => 'cancelled',
            'note' => 'changed my mind',
        ]);

        // A second order, already collected.
        $collected = $this->makeOrder($customer, $address);
        $collected->update(['status' => OrderStatus::PickedUp]);

        $this->putJson("/api/v1/orders/{$collected->id}/cancel", [], $this->apiHeaders())
            ->assertStatus(400);

        $this->assertSame(OrderStatus::PickedUp, $collected->fresh()->status);
    }

    #[Test]
    public function tracking_shows_which_steps_have_been_reached(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->makeOrder($customer, $address);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        Sanctum::actingAs($customer);

        $response = $this->getJson("/api/v1/orders/{$order->id}/track", $this->apiHeaders());

        $response->assertOk()
            ->assertJsonPath('data.status', 'picked_up')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonCount(6, 'data.steps')
            ->assertJsonPath('data.steps.0.status', 'picked_up')
            ->assertJsonPath('data.steps.0.reached', true)
            ->assertJsonPath('data.steps.1.reached', false);
    }

    #[Test]
    public function reorder_returns_the_basket_and_creates_nothing(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $order = $this->makeOrder($customer, $address);

        Sanctum::actingAs($customer);

        $response = $this->getJson("/api/v1/orders/{$order->id}/reorder", $this->apiHeaders());

        $response->assertOk()
            ->assertJsonPath('data.service_id', $this->catalog['service']->id)
            ->assertJsonPath('data.items.0.item_id', $this->catalog['items'][0]->id);

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function orders_require_a_token(): void
    {
        $this->getJson('/api/v1/orders', $this->apiHeaders())->assertUnauthorized();
        $this->postJson('/api/v1/orders', [], $this->apiHeaders())->assertUnauthorized();
        $this->postJson('/api/v1/orders/quote', [], $this->apiHeaders())->assertUnauthorized();
    }

    /**
     * The home screen's «طلبك الحالي» card is a row from this list, and it draws
     * the pickup window and a «مسح QR» button. Both used to be detail-only, so
     * the card could not be rendered without a second request per order.
     */
    #[Test]
    public function the_order_list_carries_the_pickup_window_and_the_qr(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $slot = $this->pickupSlot();

        $order = $this->makeOrder($customer, $address);
        $order->forceFill(['pickup_slot_id' => $slot->id])->save();

        Sanctum::actingAs($customer);

        $row = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->json('data.0');

        // A window, not a single time: a driver on a route cannot promise a
        // minute, which is why slots are ranges.
        $this->assertSame($slot->label(), $row['pickup_slot']);
        $this->assertSame($order->qr_token, $row['qr']);
        $this->assertNotEmpty($row['qr']);
    }

    /**
     * `index()` eager-loads `pickupSlot` precisely because the summary reads its
     * label. Without it every order in the page fires its own slot query, which
     * is invisible until a customer with a page of orders opens the app.
     */
    #[Test]
    public function the_order_list_does_not_query_a_slot_per_row(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $slot = $this->pickupSlot();

        for ($i = 0; $i < 5; $i++) {
            $this->makeOrder($customer, $address)
                ->forceFill(['pickup_slot_id' => $slot->id])->save();
        }

        Sanctum::actingAs($customer);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(5, 'data');
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Five orders must not mean five slot queries. The ceiling is loose on
        // purpose — it is guarding the shape, not a specific count.
        $this->assertLessThan(
            15,
            $queries,
            "the pickup window should not cost a query per order; ran $queries"
        );
    }

    // ------------------------------------------------- one discount per order

    /**
     * An offer carrying a live coupon — the «عروض متميزة» card a customer taps
     * on the home screen to get here.
     */
    private function offerWithDiscount(string $code = 'WINTER20'): Offer
    {
        $coupon = Coupon::create([
            'code' => $code,
            'name' => json_encode(['en' => 'Winter', 'ar' => 'شتاء'], JSON_UNESCAPED_UNICODE),
            'type' => Coupon::PERCENTAGE,
            'value' => 10,
            'max_per_user' => 1,
            'status' => 'active',
        ]);

        return Offer::create([
            'title' => json_encode(['ar' => 'باقة غسيل البطاطين'], JSON_UNESCAPED_UNICODE),
            'description' => json_encode(['ar' => 'استعد للشتاء'], JSON_UNESCAPED_UNICODE),
            'coupon_id' => $coupon->id,
            'status' => 'active',
        ]);
    }

    /**
     * The rule: a customer may type a promo code, or arrive through an offer —
     * never both. The offer is what the card promised them, so it wins, and the
     * code is refused rather than dropped. Silently charging the offer's price
     * while they believe a second discount applied is the worst outcome of the
     * three.
     */
    #[Test]
    public function a_promo_code_is_refused_on_top_of_an_offers_discount(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $offer = $this->offerWithDiscount();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
            'offer_id' => $offer->id,
            'coupon_code' => 'WINTER20',
        ], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['coupon_code']]);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    /**
     * And refused at the quote too, which is where the customer is actually
     * deciding — by the time they press submit the wizard has already told them.
     */
    #[Test]
    public function the_quote_refuses_the_same_pair(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $offer = $this->offerWithDiscount();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders/quote', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'offer_id' => $offer->id,
            'coupon_code' => 'WINTER20',
        ], $this->apiHeaders())->assertStatus(422);
    }

    /**
     * An offer on its own needs no code typed: the discount comes off the
     * coupon the card is pointing at.
     */
    #[Test]
    public function an_offer_applies_its_own_discount_without_a_code(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $offer = $this->offerWithDiscount();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
            'offer_id' => $offer->id,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('WINTER20', $order->coupon_code);
        $this->assertGreaterThan(0, (float) $order->discount_total);
        // Which card won the order — the question the offer targets were made a
        // closed set to keep answerable.
        $this->assertSame($offer->id, $order->offer_id);
    }

    /**
     * The restriction is on two *discounts*, not on offers. A card pointing at
     * a service carries no coupon, so a typed code beside it is fine.
     */
    #[Test]
    public function an_offer_without_a_coupon_leaves_a_promo_code_alone(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $offer = Offer::create([
            'title' => json_encode(['ar' => 'جرب التنظيف الجاف'], JSON_UNESCAPED_UNICODE),
            'target_type' => 'service',
            'target_value' => (string) $this->catalog['service']->id,
            'status' => 'active',
        ]);
        $coupon = Coupon::create([
            'code' => 'TYPED10',
            'name' => json_encode(['en' => 'Typed'], JSON_UNESCAPED_UNICODE),
            'type' => Coupon::PERCENTAGE, 'value' => 10, 'max_per_user' => 1, 'status' => 'active',
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
            'offer_id' => $offer->id,
            'coupon_code' => $coupon->code,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('TYPED10', $order->coupon_code);
        $this->assertGreaterThan(0, (float) $order->discount_total);
    }

    /**
     * An expired offer is not a discount, so its stale code must not be spent
     * on an order placed after it ended — and it cannot be the reason a typed
     * code is refused either.
     */
    #[Test]
    public function an_expired_offer_neither_discounts_nor_blocks(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        $offer = $this->offerWithDiscount();
        $offer->forceFill(['ends_at' => now()->subDay()])->save();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
            'offer_id' => $offer->id,
            'coupon_code' => 'WINTER20',
        ], $this->apiHeaders())->assertCreated();

        // The code the customer typed stands on its own.
        $this->assertSame('WINTER20', Order::withoutGlobalScopes()->firstOrFail()->coupon_code);
    }

    // ------------------------------------------------ handover, once per leg

    /**
     * The design asks how to hand over twice. One column could not express
     * wanting the bag taken in person and the clean clothes left at the door.
     */
    #[Test]
    public function each_leg_carries_its_own_handover_method(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        Sanctum::actingAs($customer);

        $detail = $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
            'pickup_method' => 'door',
            'delivery_method' => 'leave',
        ], $this->apiHeaders())->assertCreated()->json('data');

        $this->assertSame('door', $detail['pickup_method']);
        $this->assertSame('leave', $detail['delivery_method']);
    }

    #[Test]
    public function both_handover_methods_default_to_the_door(): void
    {
        [$customer, $address] = $this->customerWithAddress();
        Sanctum::actingAs($customer);

        $detail = $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated()->json('data');

        $this->assertSame('door', $detail['pickup_method']);
        $this->assertSame('door', $detail['delivery_method']);
    }

    /**
     * A collection window. This suite does not seed any, and depending on
     * seeded data for an assertion about a label makes the test a hostage to
     * whatever the seeder happens to hold.
     */
    private function pickupSlot(): TimeSlot
    {
        return TimeSlot::create([
            'start_time' => '16:00:00',
            'end_time' => '18:00:00',
            'applies_to' => 'both',
            'capacity' => 50,
            'sort_order' => 1,
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0: User, 1: Address}
     */
    private function customerWithAddress(bool $cover = true, ?int $serviceId = 0): array
    {
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        if ($cover) {
            $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
            $this->cover(
                $tenant['laundry'],
                $this->geo['zones'][0]->id,
                $serviceId === 0 ? $this->catalog['service']->id : ($serviceId ?? $this->catalog['service']->id)
            );
        }

        return [$customer, $address];
    }

    private function makeOrder($customer, $address): Order
    {
        return app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ]);
    }
}
