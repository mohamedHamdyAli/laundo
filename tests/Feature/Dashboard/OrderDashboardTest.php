<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Orders in the dashboard, and the tenant boundary around them.
 *
 * The isolation claim being tested is specific: a laundry sees its own orders and
 * nothing else — including that it cannot see, or grab, an unassigned one.
 */
class OrderDashboardTest extends TestCase
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
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
        $this->geo['zones'][1]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
    }

    #[Test]
    public function a_super_admin_sees_every_order(): void
    {
        [$a, $b] = $this->twoTenantsWithOneOrderEach();

        $response = $this->actingAs($this->superAdmin())->get('/admin/order');

        $response->assertOk();
        $response->assertSee('#'.$a->code);
        $response->assertSee('#'.$b->code);
    }

    #[Test]
    public function a_laundry_sees_only_its_own_orders(): void
    {
        [$a, $b, $ownerA] = $this->twoTenantsWithOneOrderEach();

        $response = $this->actingAs($ownerA)->get('/admin/order');

        $response->assertOk();
        $response->assertSee('#'.$a->code);
        $response->assertDontSee('#'.$b->code);
    }

    #[Test]
    public function a_laundry_cannot_open_another_laundrys_order(): void
    {
        [, $b, $ownerA] = $this->twoTenantsWithOneOrderEach();

        // The tenant scope makes the row invisible, so findOrFail 404s rather than
        // rendering somebody else's customer.
        $this->actingAs($ownerA)->get("/admin/order/show/{$b->id}")->assertNotFound();
    }

    #[Test]
    public function an_unassigned_order_is_invisible_to_every_tenant(): void
    {
        $customer = $this->customer();
        // An address in a zone nobody covers.
        $address = $this->addressFor($customer, $this->geo['zones'][1]);

        $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $order = $this->placeFor($customer, $address);
        $this->assertNull($order->laundry_id);

        // Not the tenant's work, so not the tenant's to see.
        $this->actingAs($tenant['owner'])->get('/admin/order')->assertOk()->assertDontSee('#'.$order->code);
        $this->actingAs($tenant['owner'])->get("/admin/order/show/{$order->id}")->assertNotFound();

        // The super admin triages it.
        $this->actingAs($this->superAdmin())->get('/admin/order')->assertOk()->assertSee('#'.$order->code);
    }

    #[Test]
    public function assigning_an_order_prices_its_delivery(): void
    {
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][1]);

        $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $order = $this->placeFor($customer, $address);
        $this->assertSame('0.00', $order->delivery_fee);

        // Coverage is extended, then the order is placed by hand.
        $this->cover($tenant['laundry'], $this->geo['zones'][1]->id, $this->catalog['service']->id);

        $this->actingAs($this->superAdmin())
            ->put("/admin/order/assign/{$order->id}", ['laundry_id' => $tenant['laundry']->id])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame($tenant['laundry']->id, $order->laundry_id);
        $this->assertGreaterThan(0, (float) $order->delivery_fee);
        $this->assertSame(
            round((float) $order->estimated_subtotal + (float) $order->delivery_fee, 2),
            round((float) $order->estimated_total, 2)
        );

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'actor_type' => 'admin',
            'note' => "Assigned to laundry #{$tenant['laundry']->id}.",
        ]);
    }

    #[Test]
    public function a_collected_order_cannot_be_reassigned(): void
    {
        [$a, , , $laundryBId] = $this->twoTenantsWithOneOrderEach();

        $a->update(['status' => OrderStatus::PickedUp]);
        $originalFee = $a->delivery_fee;

        $this->actingAs($this->superAdmin())
            ->put("/admin/order/assign/{$a->id}", ['laundry_id' => $laundryBId])
            ->assertRedirect()
            ->assertSessionHas('error');

        // Neither the laundry nor the agreed total moved.
        $this->assertNotSame($laundryBId, $a->fresh()->laundry_id);
        $this->assertSame($originalFee, $a->fresh()->delivery_fee);
    }

    #[Test]
    public function the_search_endpoint_filters_by_code_and_by_status(): void
    {
        [$a, $b] = $this->twoTenantsWithOneOrderEach();
        $b->update(['status' => OrderStatus::Completed]);

        $admin = $this->superAdmin();

        $byCode = $this->actingAs($admin)
            ->get('/admin/order/search?query='.$a->code, ['X-Requested-With' => 'XMLHttpRequest']);
        $byCode->assertOk();
        $this->assertStringContainsString('#'.$a->code, $byCode->json('table'));
        $this->assertStringNotContainsString('#'.$b->code, $byCode->json('table'));

        $byStatus = $this->actingAs($admin)
            ->get('/admin/order/search?status=completed', ['X-Requested-With' => 'XMLHttpRequest']);
        $byStatus->assertOk();
        $this->assertStringContainsString('#'.$b->code, $byStatus->json('table'));
        $this->assertStringNotContainsString('#'.$a->code, $byStatus->json('table'));
    }

    #[Test]
    public function orders_are_gated_on_the_view_permission(): void
    {
        $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->grant('laundry_owner', ['laundry.view']);

        $this->actingAs($tenant['owner'])->get('/admin/order')->assertForbidden();
    }

    #[Test]
    public function a_customer_cannot_reach_the_orders_dashboard(): void
    {
        $this->actingAs($this->customer())->get('/admin/order')->assertForbidden();
    }

    /**
     * Two tenants, each covering a different zone, each with one order.
     *
     * @return array{0: Order, 1: Order, 2: User, 3: int}
     */
    private function twoTenantsWithOneOrderEach(): array
    {
        $tenantA = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $tenantB = $this->laundryWithOwner('B', '01022220001', '01022220002');

        $this->cover($tenantA['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
        $this->cover($tenantB['laundry'], $this->geo['zones'][1]->id, $this->catalog['service']->id, 29.9600, 31.2600);

        $customerA = $this->customer('01099887766');
        $customerB = $this->customer('01099887767');

        $orderA = $this->placeFor($customerA, $this->addressFor($customerA, $this->geo['zones'][0]));
        $orderB = $this->placeFor($customerB, $this->addressFor($customerB, $this->geo['zones'][1], 29.9650, 31.2650));

        $this->assertSame($tenantA['laundry']->id, $orderA->laundry_id);
        $this->assertSame($tenantB['laundry']->id, $orderB->laundry_id);

        return [$orderA, $orderB, $tenantA['owner'], $tenantB['laundry']->id];
    }

    private function placeFor($customer, $address): Order
    {
        return app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
        ]);
    }
}
