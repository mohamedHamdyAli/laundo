<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dispatch from the dashboard.
 *
 * Two claims worth protecting. First, an operator can reassign and release but
 * never *complete* — a leg is finished in the field with a scan and a signature,
 * and ticking one off from a desk would destroy the only proof the handover
 * happened. Second, the capacity and city fields exist on the driver form at all:
 * they were added in P6 and left unreachable, which is why every driver had null
 * for both and the rules built on them never bit.
 */
class DispatchDashboardTest extends TestCase
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

        foreach ($this->geo['zones'] as $zone) {
            $zone->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
        }

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
    }

    #[Test]
    public function the_order_page_shows_the_four_legs(): void
    {
        $order = $this->placedOrder();

        $response = $this->actingAs($this->superAdmin())->get("/admin/order/show/{$order->id}");

        $response->assertOk()->assertSee(__('Transport'), false);

        foreach (TaskType::chain() as $type) {
            $response->assertSee(__($type->label()), false);
        }
    }

    #[Test]
    public function an_undispatched_leg_is_shown_as_queued(): void
    {
        // Nobody serves the zone.
        $order = $this->placedOrder();

        $this->assertSame(4, OrderTask::queued()->count());

        $this->actingAs($this->superAdmin())->get("/admin/order/show/{$order->id}")
            ->assertOk()
            ->assertSee(__('In the queue'), false);
    }

    #[Test]
    public function operations_can_assign_a_queued_leg_to_an_eligible_driver(): void
    {
        $order = $this->placedOrder();
        $driver = $this->eligibleDriver();

        $task = $this->leg($order, TaskType::PickupFromCustomer);
        $this->assertNull($task->driver_id);

        $this->actingAs($this->superAdmin())
            ->post("/admin/order/task/assign/{$task->id}", ['driver_id' => $driver->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame($driver->id, $task->driver_id);
        $this->assertSame(TaskStatus::Assigned, $task->status);
    }

    #[Test]
    public function an_override_still_obeys_the_eligibility_rules(): void
    {
        $order = $this->placedOrder();

        // A driver who serves a different zone entirely.
        $wrong = $this->driverUser('+201033330009', zoneIds: [$this->geo['zones'][1]->id]);
        $task = $this->leg($order, TaskType::PickupFromCustomer);

        // An override is a person choosing between qualified drivers, not a way
        // to give a task to somebody who cannot do it.
        $this->actingAs($this->superAdmin())
            ->post("/admin/order/task/assign/{$task->id}", ['driver_id' => $wrong->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($task->fresh()->driver_id);
    }

    #[Test]
    public function operations_can_return_a_leg_to_the_queue(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $task = $this->leg($order, TaskType::PickupFromCustomer);
        $this->assertSame($driver->id, $task->driver_id);

        $this->actingAs($this->superAdmin())
            ->post("/admin/order/task/release/{$task->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertNull($task->driver_id);
        $this->assertSame(TaskStatus::Pending, $task->status);
    }

    #[Test]
    public function there_is_no_way_to_complete_a_leg_from_the_dashboard(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->leg($order, TaskType::PickupFromCustomer);

        // A leg is finished in the field with a scan and a signature. No route
        // exists to tick one off, and the page offers no control to do it.
        $this->assertFalse(Route::has('admin.order.tasks.complete'));

        $this->actingAs($this->superAdmin())->get("/admin/order/show/{$order->id}")
            ->assertOk()
            ->assertDontSee('order/task/complete', false);

        $this->assertSame(TaskStatus::Assigned, $task->fresh()->status);
    }

    #[Test]
    public function dispatch_is_gated_on_the_update_permission(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->leg($order, TaskType::PickupFromCustomer);

        $this->grant('laundry_owner', ['laundry.view', 'order.view']);

        $this->actingAs($this->tenant['owner'])
            ->post("/admin/order/task/assign/{$task->id}", ['driver_id' => $driver->id])
            ->assertForbidden();
    }

    #[Test]
    public function a_laundry_cannot_dispatch_another_laundrys_leg(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->leg($order, TaskType::PickupFromCustomer);

        $intruder = $this->laundryWithOwner('B', '+201022220001', '+201022220002');

        // Reached through the tenant-scoped Order, so another laundry's task is
        // simply not found.
        $this->actingAs($intruder['owner'])
            ->post("/admin/order/task/release/{$task->id}")
            ->assertNotFound();

        $this->assertSame($driver->id, $task->fresh()->driver_id);
    }

    // ------------------------------------------------- the P6 gap, closed here

    #[Test]
    public function the_driver_form_exposes_capacity_and_city(): void
    {
        $driver = $this->eligibleDriver();

        $response = $this->actingAs($this->superAdmin())->get("/admin/driver/edit/{$driver->id}");

        // Added in P6 and never given a field, which is why the dispatch rules
        // built on them never bit.
        $response->assertOk()
            ->assertSee('name="max_concurrent_orders"', false)
            ->assertSee('name="city_id"', false);
    }

    #[Test]
    public function saving_capacity_and_city_persists_them(): void
    {
        $driver = $this->eligibleDriver();
        $city = $this->geo['city'];

        $this->actingAs($this->superAdmin())->put("/admin/driver/update/{$driver->id}", [
            'name' => $driver->name,
            'phone' => $driver->phone,
            'status' => 'active',
            'max_concurrent_orders' => 3,
            'city_id' => $city->id,
            'is_available' => 1,
        ])->assertRedirect();

        $profile = $driver->fresh()->profile;
        $this->assertSame(3, $profile->max_concurrent_orders);
        $this->assertSame($city->id, $profile->city_id);
    }

    #[Test]
    public function both_fields_can_be_cleared_again(): void
    {
        $driver = $this->eligibleDriver();
        $driver->profile->update(['max_concurrent_orders' => 3, 'city_id' => $this->geo['city']->id]);

        $this->actingAs($this->superAdmin())->put("/admin/driver/update/{$driver->id}", [
            'name' => $driver->name,
            'phone' => $driver->phone,
            'status' => 'active',
            'max_concurrent_orders' => '',
            'city_id' => '',
            'is_available' => 1,
        ])->assertRedirect();

        // A cap that can be set but never removed is a driver permanently
        // throttled by a typo.
        $profile = $driver->fresh()->profile;
        $this->assertNull($profile->max_concurrent_orders);
        $this->assertNull($profile->city_id);
    }

    // ------------------------------------------------------------------ helpers

    private function eligibleDriver(): Driver
    {
        return $this->driverUser('+201044440001', zoneIds: [$this->geo['zones'][0]->id]);
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

    private function leg(Order $order, TaskType $type): OrderTask
    {
        return OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();
    }
}
