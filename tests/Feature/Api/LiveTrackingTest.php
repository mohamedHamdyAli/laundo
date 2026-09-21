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
    // ------------------------------------------- the last thing we knew, and when

    #[Test]
    public function a_stale_reading_is_still_handed_over_with_its_age(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();

        $this->driver->profile->forceFill(['located_at' => now()->subMinutes(5)])->save();

        $card = $this->card($order);

        // `location` is the recommendation and still refuses: five minutes old is
        // not a live dot.
        $this->assertNull($card['location']);

        // `last_seen` is the fact. The map comes back the moment the customer
        // reopens the screen — faded, and labelled with how old it is, rather
        // than «موقع المندوب غير متاح» over an empty page.
        $this->assertNotNull($card['last_seen']);
        $this->assertSame(30.05, $card['last_seen']['lat']);
        $this->assertTrue($card['last_seen']['is_stale']);
        $this->assertGreaterThanOrEqual(290, $card['last_seen']['age_seconds']);
        $this->assertLessThan(400, $card['last_seen']['age_seconds']);
    }

    #[Test]
    public function a_fresh_reading_is_not_marked_stale(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();

        $card = $this->card($order);

        $this->assertNotNull($card['location'], 'a reading seconds old is a live dot');
        $this->assertFalse($card['last_seen']['is_stale']);
        $this->assertLessThan(120, $card['last_seen']['age_seconds']);
    }

    #[Test]
    public function a_driver_who_never_reported_has_nothing_to_hand_over(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);

        $card = $this->card($order);

        // Null here means «never», which is the other question `location: null`
        // used to answer with the same word.
        $this->assertNull($card['location']);
        $this->assertNull($card['last_seen']);
    }

    #[Test]
    public function the_last_position_is_withheld_on_the_legs_the_dot_is(): void
    {
        // Same gate as the live dot, and that is the point: relaxing freshness
        // must not relax privacy. A driver whose last position outlived the leg
        // is a driver being followed off the clock.
        $order = $this->order();
        $this->leg($order, TaskType::DeliverToLaundry, TaskStatus::Started);
        $this->report();

        $card = $this->card($order);

        $this->assertNull($card['location']);
        $this->assertNull($card['last_seen']);
    }

    #[Test]
    public function a_finished_leg_hands_over_neither(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();
        $this->leg($order, TaskType::PickupFromCustomer, TaskStatus::Completed);

        $card = $this->card($order);

        $this->assertNull($card['location']);
        $this->assertNull($card['last_seen'], 'the handover is over; the position goes with it');
    }

    // ------------------------------------- the endpoint the marker is drawn from

    private function poll(Order $order): array
    {
        return $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id.'/driver-location')
            ->assertOk()
            ->json('data');
    }

    #[Test]
    public function the_polling_endpoint_carries_the_dot_and_when_to_ask_again(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();

        $data = $this->poll($order);

        $this->assertTrue($data['tracking']);
        $this->assertSame(config('tracking.poll_seconds'), $data['poll_after_seconds']);
        $this->assertSame(30.05, $data['location']['lat']);
        $this->assertFalse($data['last_seen']['is_stale']);
    }

    #[Test]
    public function nothing_to_follow_tells_the_app_to_stop_asking(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();
        $this->leg($order, TaskType::PickupFromCustomer, TaskStatus::Completed);

        $data = $this->poll($order);

        // A phone polling a finished handover is battery spent on a marker
        // nobody is drawing.
        $this->assertFalse($data['tracking']);
        $this->assertNull($data['poll_after_seconds']);
        $this->assertNull($data['location']);
        $this->assertNull($data['last_seen']);
    }

    #[Test]
    public function the_polling_endpoint_keeps_the_same_gate_as_the_card(): void
    {
        // The whole reason it is built on the card's own gate rather than a copy:
        // the run to the laundry is nobody's business through either door.
        $order = $this->order();
        $this->leg($order, TaskType::DeliverToLaundry, TaskStatus::Started);
        $this->report();

        $data = $this->poll($order);

        $this->assertFalse($data['tracking']);
        $this->assertNull($data['location']);
        $this->assertNull($data['last_seen']);
    }

    #[Test]
    public function a_stranger_cannot_poll_somebody_elses_driver(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();

        $stranger = $this->customer('+201055559999');

        $this->actingAs($stranger)
            ->getJson('/api/v1/orders/'.$order->id.'/driver-location')
            ->assertNotFound();
    }

    #[Test]
    public function the_freshness_window_follows_its_config(): void
    {
        // It is config rather than a constant so it can come down once the apps
        // report faster — and it must not come down before then, or the dot
        // blinks off between reports.
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);
        $this->report();

        $this->driver->profile->forceFill(['located_at' => now()->subSeconds(45)])->save();

        config(['tracking.fresh_for_seconds' => 120]);
        $this->assertNotNull($this->poll($order)['location'], '45s is live at a 120s window');

        config(['tracking.fresh_for_seconds' => 30]);
        $polled = $this->poll($order);
        $this->assertNull($polled['location'], '45s is stale at a 30s window');
        $this->assertTrue($polled['last_seen']['is_stale']);
    }

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
