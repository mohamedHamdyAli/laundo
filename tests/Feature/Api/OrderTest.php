<?php

namespace Tests\Feature\Api;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Pricing\Models\ItemPrice;
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
        $stranger = $this->customer('01088776655');
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

        $stranger = $this->customer('01088776655');
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
     * @return array{0: User, 1: Address}
     */
    private function customerWithAddress(bool $cover = true, ?int $serviceId = 0): array
    {
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        if ($cover) {
            $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
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
