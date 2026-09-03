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
        $this->customer = $this->customer('+201055550001');
        $this->driver = $this->driverUser('+201066660001');
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

        $stranger = $this->customer('+201055550002');

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

    // ---------------------------------------------------------- the number

    /** @return array<string, mixed>|null */
    private function card(Order $order): ?array
    {
        return $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id.'/track')
            ->assertOk()
            ->json('data.driver');
    }

    /**
     * The design's call button. Withheld outright until now, on the reasoning
     * that a driver's personal mobile is a policy decision rather than a field
     * — revisited because a customer with a driver outside and no way to say
     * «I'm on the third floor» is what that was costing.
     */
    #[Test]
    public function the_number_is_reachable_while_the_driver_is_on_their_way(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer, TaskStatus::Started);

        $this->assertSame($this->driver->phone, $this->card($order)['phone']);
    }

    /**
     * And gone the moment the leg ends. A number reachable for ever is the
     * thing the original decision was protecting against; twenty minutes at
     * somebody's door is not.
     */
    #[Test]
    public function the_number_disappears_once_the_leg_is_finished(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer, TaskStatus::Completed);

        $card = $this->card($order);

        $this->assertNotNull($card, 'the card still names the driver');
        $this->assertNull($card['phone']);
    }

    /**
     * The two legs between us and the laundry are none of the customer's
     * business — they are not waiting at the laundry's door — so neither the
     * dot nor the number appears for them.
     */
    #[Test]
    public function the_laundry_legs_expose_neither_the_number_nor_the_dot(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::DeliverToLaundry, TaskStatus::Started);
        $this->report();

        $card = $this->card($order);

        $this->assertNull($card['phone']);
        $this->assertNull($card['location']);
    }

    /**
     * The number and the dot answer the same question, so they must not be able
     * to disagree — a number still reachable after the dot had gone would be
     * the gate having drifted apart in two places.
     */
    #[Test]
    public function the_number_and_the_dot_are_gated_together(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::DeliverToCustomer, TaskStatus::Started);
        $this->report();

        $live = $this->card($order);
        $this->assertNotNull($live['phone']);
        $this->assertNotNull($live['location']);

        $order->tasks()->where('type', TaskType::DeliverToCustomer->value)
            ->update(['status' => TaskStatus::Completed->value]);

        $done = $this->card($order);
        $this->assertNull($done['phone']);
        $this->assertNull($done['location']);
    }

    /**
     * There is no chat. Nothing in the system carries a message between a
     * customer and a driver, and the card must not imply otherwise.
     */
    #[Test]
    public function the_card_promises_no_chat(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);

        $card = $this->card($order);

        foreach (['chat', 'thread', 'conversation', 'chat_id', 'thread_id'] as $key) {
            $this->assertArrayNotHasKey($key, $card);
        }
    }
}
