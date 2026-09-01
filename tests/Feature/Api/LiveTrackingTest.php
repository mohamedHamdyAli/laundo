<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «تتبع المندوب مباشرة» — the moving dot.
 *
 * The owner's decision was the last point only, every thirty seconds, and only
 * while a journey is in progress. Most of what is asserted here is therefore
 * about **when there is no dot**: a driver between jobs, a phone that stopped
 * reporting, a leg that runs between the laundry and back. Following somebody who
 * is not coming to you is not a feature, and a stale marker is worse than none
 * because it reads as a driver who has stopped.
 */
class LiveTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $driver;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('01055550001');
        $this->driver = $this->driverUser('01066660001');
    }

    private function order(): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    private function leg(Order $order, TaskType $type, TaskStatus $status = TaskStatus::Started): OrderTask
    {
        $task = $order->tasks()->where('type', $type->value)->firstOrFail();
        $task->forceFill(['driver_id' => $this->driver->id, 'status' => $status->value])->save();

        return $task->fresh();
    }

    private function report(float $lat = 30.05, float $lng = 31.23)
    {
        return $this->actingAs($this->driver, 'sanctum')
            ->postJson('/api/v1/driver/location', ['lat' => $lat, 'lng' => $lng]);
    }

    /** @return array<string, mixed>|null */
    private function trackedLocation(Order $order): ?array
    {
        return $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id.'/track')
            ->assertOk()
            ->json('data.driver.location');
    }

    // ------------------------------------------------------------- reporting

    #[Test]
    public function a_driver_on_a_journey_is_recorded(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);

        $this->report()->assertOk()->assertJsonPath('data.tracking', true);

        $profile = $this->driver->fresh()->profile;
        $this->assertEquals(30.05, $profile->last_lat);
        $this->assertEquals(31.23, $profile->last_lng);
        $this->assertNotNull($profile->located_at);
    }

    #[Test]
    public function a_driver_with_no_journey_is_not_tracked(): void
    {
        // Between jobs, or with the app left open on their own time. Following
        // somebody who is not working is not a feature of a laundry, and the
        // reply tells the app to stop asking.
        $this->report()->assertOk()->assertJsonPath('data.tracking', false);

        $this->assertNull($this->driver->fresh()->profile?->last_lat);
    }

    #[Test]
    public function a_finished_journey_stops_the_recording(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer, TaskStatus::Completed);

        $this->report()->assertOk()->assertJsonPath('data.tracking', false);
    }

    #[Test]
    public function a_position_off_the_planet_is_refused(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);

        $this->actingAs($this->driver, 'sanctum')
            ->postJson('/api/v1/driver/location', ['lat' => 200, 'lng' => 31.23])
            ->assertStatus(422);
    }

    #[Test]
    public function a_profile_update_cannot_move_the_driver(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report(30.05, 31.23);

        $this->actingAs($this->driver, 'sanctum')
            ->postJson('/api/v1/driver/profile', [
                'name' => 'Still Me',
                'last_lat' => 0,
                'last_lng' => 0,
            ])->assertSuccessful();

        // The columns are not fillable on purpose: the position is written after
        // a live-task check and nowhere else.
        $this->assertEquals(30.05, $this->driver->fresh()->profile->last_lat);
    }

    // -------------------------------------------------------------- the map

    #[Test]
    public function the_customer_sees_the_driver_coming(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report(30.05, 31.23);

        $location = $this->trackedLocation($order);

        $this->assertEquals(30.05, $location['lat']);
        $this->assertEquals(31.23, $location['lng']);
        $this->assertNotNull($location['updated_at']);
    }

    #[Test]
    public function a_reading_that_stopped_arriving_is_removed_not_frozen(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();

        // Four missed reports: a phone that lost signal, was closed, or ran out
        // of battery. A marker left where it was reads as «السائق واقف» and sends
        // the customer to the telephone.
        $this->driver->profile->forceFill(['located_at' => now()->subMinutes(5)])->save();

        $this->assertNull($this->trackedLocation($order));
    }

    #[Test]
    public function the_run_to_the_laundry_is_not_broadcast(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::DeliverToLaundry);
        $this->report();

        // Legs two and three run between the laundry and back and the customer is
        // waiting at neither end. Showing a live position for a journey nobody is
        // waiting on is surveillance with no purpose.
        $this->assertNull($this->trackedLocation($order));
    }

    #[Test]
    public function the_run_back_to_the_customer_is(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::DeliverToCustomer);
        $this->report();

        $this->assertNotNull($this->trackedLocation($order));
    }

    #[Test]
    public function a_stranger_cannot_watch_the_driver(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();

        $stranger = $this->customer('01055550002');

        // The position rides on the order, so the order's own boundary is what
        // protects it.
        $this->actingAs($stranger)
            ->getJson('/api/v1/orders/'.$order->id.'/track')
            ->assertNotFound();
    }

    #[Test]
    public function a_driver_who_never_reported_shows_no_dot(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);

        // The card still names them — the customer knows who is coming — but
        // there is nothing to draw on the map.
        $card = $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id.'/track')
            ->json('data.driver');

        $this->assertSame($this->driver->name, $card['name']);
        $this->assertNull($card['location']);
    }
}
