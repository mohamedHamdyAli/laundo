<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What «تتبّع الطلب» hands the app besides the dot.
 *
 * The mobile team's report was that the screen could not be drawn: the driver
 * card could not say whether the person was coming to collect or to deliver,
 * the destination was coordinates with no name on them, and there was no answer
 * at all to «هيوصل امتى؟».
 *
 * The live position itself is LiveTrackingTest's subject; this file is the rest
 * of the payload.
 */
class TrackPayloadTest extends TestCase
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

    private function slot(): TimeSlot
    {
        return TimeSlot::firstOrCreate(
            ['start_time' => '09:00:00', 'end_time' => '12:00:00'],
            ['applies_to' => 'both', 'sort_order' => 1, 'status' => 'active', 'capacity' => 20],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function order(array $extra = []): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($this->customer, $extra + [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    private function leg(Order $order, TaskType $type, TaskStatus $status = TaskStatus::Started): OrderTask
    {
        $task = $order->tasks()->where('type', $type->value)->firstOrFail();
        // Stamped, because `DriverDispatcher::assignTo()` stamps it and
        // `release()` nulls it: a leg carrying a driver but no handover time is
        // a row production cannot produce, and `DriverCard::lastSeen()` reads
        // that time to decide whether a stored position belongs to this journey
        // or to the one before it. An hour back so a reading made "now" in a
        // test is unambiguously after the handover.
        $task->forceFill([
            'driver_id' => $this->driver->id,
            'status' => $status->value,
            'assigned_at' => now()->subHour(),
            // Only a *started* leg carries this: `TaskService::start()` is the
            // one writer, so an assigned leg with a start time is a row
            // production cannot produce.
            'started_at' => $status === TaskStatus::Started ? now()->subHour() : null,
        ])->save();

        return $task->fresh();
    }

    /** @return array<string, mixed> */
    private function track(Order $order): array
    {
        return $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id.'/track')
            ->assertOk()
            ->json('data');
    }

    // ------------------------------------------------------------- the driver

    /**
     * **The field that changes the wording on screen.** `role` is a translated
     * label, so a client switching on it is switching on the request language —
     * which is why the app could not tell a collection from a delivery.
     */
    #[Test]
    public function the_driver_card_names_its_leg_as_a_key_not_only_as_prose(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);

        $card = $this->track($order)['driver'];

        $this->assertSame('pickup', $card['leg']);
        $this->assertSame('pickup_from_customer', $card['task_type']);
        // The prose is still there, and is not the key.
        $this->assertNotSame('pickup', $card['role']);
    }

    #[Test]
    public function the_return_journey_reports_the_other_side(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::DeliverToCustomer);

        $card = $this->track($order)['driver'];

        $this->assertSame('delivery', $card['leg']);
        $this->assertSame('deliver_to_customer', $card['task_type']);
    }

    /**
     * The app reads the avatar as `photo`; the endpoint has always sent `image`.
     */
    #[Test]
    public function the_avatar_is_reachable_under_both_names(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer);

        $card = $this->track($order)['driver'];

        $this->assertSame($card['image'], $card['photo']);
    }

    // ---------------------------------------------------------- the two doors

    /**
     * Coordinates place a pin. They do not tell a customer where their clothes
     * are going, and the screen names the destination.
     */
    #[Test]
    public function both_doors_come_back_readable_as_well_as_as_points(): void
    {
        $order = $this->order();

        $data = $this->track($order);

        $this->assertSame($order->pickup_address_id, $data['pickup_address']['id']);
        $this->assertNotNull($data['pickup_address']['label']);
        $this->assertSame($data['pickup_address']['lat'], $data['pickup_location']['lat']);

        // This order is a round trip, so the delivery door is the pickup door.
        $this->assertSame($order->delivery_address_id, $data['delivery_address']['id']);
    }

    // ----------------------------------------------------------- the handover

    /**
     * Two values, because the service has two. There are no collection points
     * or lockers anywhere in this system.
     */
    #[Test]
    public function the_handover_method_comes_back_with_a_label(): void
    {
        $order = $this->order(['delivery_method' => 'leave']);

        $data = $this->track($order);

        $this->assertSame('leave', $data['delivery_method']);
        $this->assertNotNull($data['delivery_method_label']);
        $this->assertNotSame('leave', $data['delivery_method_label']);
    }

    // ----------------------------------------------------------------- the ETA

    /**
     * The booked window, as a real instant — not relative prose, which is
     * localised and different on every request.
     */
    #[Test]
    public function the_eta_is_the_window_the_customer_was_promised(): void
    {
        $slot = $this->slot();
        $date = now()->addDay()->toDateString();

        $order = $this->order([
            'pickup_slot_id' => $slot->id,
            'pickup_date' => $date,
        ]);

        $this->leg($order, TaskType::PickupFromCustomer, TaskStatus::Assigned);

        $data = $this->track($order);

        $this->assertNotNull($data['eta_iso']);
        $this->assertSame($date, substr((string) $data['eta_iso'], 0, 10));
        $this->assertSame('time_slot', $data['eta_source']);
        // Both ends, so the screen can name a window rather than promise a
        // minute nothing in this system can know.
        $this->assertNotNull($data['eta_window']['to']);
        $this->assertNotNull($data['eta_window']['label']);
        $this->assertGreaterThan(0, $data['eta_minutes']);
    }

    /**
     * A window that has already opened is «any moment», not «twenty minutes
     * ago». A negative countdown on a waiting screen is a bug the customer
     * reads as lateness.
     */
    #[Test]
    public function a_window_already_open_counts_down_to_zero_not_below(): void
    {
        $slot = $this->slot();

        $order = $this->order([
            'pickup_slot_id' => $slot->id,
            'pickup_date' => now()->subDay()->toDateString(),
        ]);

        $this->leg($order, TaskType::PickupFromCustomer, TaskStatus::Assigned);

        $this->assertSame(0, $this->track($order)['eta_minutes']);
    }

    /**
     * Null rather than a guess. Nothing in this system routes a van, so an
     * order with no window booked has no arrival time to give — and inventing
     * one puts a promise on the screen we cannot keep.
     */
    #[Test]
    public function an_order_with_no_window_gives_no_time_rather_than_a_guess(): void
    {
        $order = $this->order();
        $this->leg($order, TaskType::PickupFromCustomer, TaskStatus::Assigned);

        $data = $this->track($order);

        $this->assertNull($data['eta_iso']);
        $this->assertNull($data['eta_minutes']);
        $this->assertNull($data['eta_window']);
    }

    /**
     * And nothing at all once the journey is over.
     */
    #[Test]
    public function a_finished_journey_has_no_eta(): void
    {
        $slot = $this->slot();

        $order = $this->order([
            'pickup_slot_id' => $slot->id,
            'pickup_date' => now()->addDay()->toDateString(),
        ]);

        foreach ($order->tasks as $task) {
            $task->forceFill(['status' => TaskStatus::Completed->value])->save();
        }

        $this->assertNull($this->track($order->fresh())['eta_iso']);
    }

    /**
     * The step timestamps the app asked for. Relative prose alone is not usable
     * — it is localised and different on every request.
     */
    #[Test]
    public function every_reached_step_carries_a_machine_readable_timestamp(): void
    {
        $order = $this->order();

        // A fresh order has reached nothing — the timeline starts at «the driver
        // is on the way», which has not happened yet.
        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $steps = collect($this->track($order->fresh())['steps'])->where('reached', true);

        $this->assertNotEmpty($steps);

        foreach ($steps as $step) {
            // `at_iso` is null only where the timestamp genuinely is not known —
            // a step settled by the monotonic rule rather than by its own log.
            if ($step['at'] !== null) {
                $this->assertNotNull($step['at_iso'], $step['status'].' has prose but no timestamp');
            }
        }
    }
}
