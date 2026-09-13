<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\OrderTimeline;
use App\Modules\Order\Services\TaskService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The return half of the journey, as the customer's tracking screen sees it.
 *
 * The first half is well covered: the driver is named while collecting, and the
 * map follows them to the door. The second half — collecting the clean clothes
 * from the laundry and bringing them back — is the half the customer is
 * actually waiting at home for, and it is the one nobody had tested.
 */
class TrackReturnLegTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $tenant;

    private User $customer;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer('+201099880055');
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    private function walk(Order $order, TaskType $type, User $driver): void
    {
        $task = OrderTask::withoutGlobalScopes()
            ->where('order_id', $order->id)->where('type', $type->value)->firstOrFail();

        if ($task->driver_id === null) {
            app(DriverDispatcher::class)->assign($task->fresh(), $driver);
        }

        $tasks = app(TaskService::class);
        $tasks->start($task->fresh(), $driver);
        $tasks->complete(
            $task->fresh(),
            $driver,
            $type->countsPieces() ? ['piece_count' => 2] : [],
            [],
            $type->requiresSignature() ? UploadedFile::fake()->image('sig.png') : null,
        );
    }

    /** An order sitting at the laundry, clean, waiting to go back. */
    private function readyForDelivery(User $driver): Order
    {
        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $this->walk($order, TaskType::PickupFromCustomer, $driver);
        $this->walk($order, TaskType::DeliverToLaundry, $driver);

        $reviews = app(OrderReviewService::class);
        $reviews->review(
            $order->fresh(),
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            null,
            $this->tenant['owner'],
        );
        $reviews->confirm($order->fresh(), $this->customer);

        $machine->transition($order->fresh(), OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');

        return $order->fresh();
    }

    /** @return array<string, mixed> */
    private function track(Order $order): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->customer);

        return $this->getJson("/api/v1/orders/{$order->id}/track", $this->apiHeaders())
            ->assertOk()
            ->json('data');
    }

    private function assignOnly(Order $order, TaskType $type, User $driver): OrderTask
    {
        $task = OrderTask::withoutGlobalScopes()
            ->where('order_id', $order->id)->where('type', $type->value)->firstOrFail();

        app(DriverDispatcher::class)->assign($task->fresh(), $driver);

        return $task->fresh();
    }

    // ------------------------------------------------------- collecting back

    #[Test]
    public function the_driver_collecting_from_the_laundry_is_named(): void
    {
        $driver = $this->driverUser('+201033330061', zoneIds: [$this->geo['zones'][0]->id]);
        $order = $this->readyForDelivery($driver);

        $this->assignOnly($order, TaskType::CollectFromLaundry, $driver);

        $driver->profile()->update([
            'last_lat' => 30.05, 'last_lng' => 31.21, 'located_at' => now(),
        ]);

        $data = $this->track($order);

        $this->assertNotNull($data['driver'], 'no driver on the collect-from-laundry leg');
        $this->assertSame($driver->name, $data['driver']['name']);

        // Both return legs are assigned the moment the laundry marks an order
        // ready, so this leg is what the card reports for the whole way back.
        // It used to go silent here — no number, no dot — and the customer at
        // home saw a screen that had not moved since the wash.
        $this->assertSame($driver->phone, $data['driver']['phone'], 'no phone on the way back');
        $this->assertNotNull($data['driver']['location'], 'no dot on the way back');
    }

    #[Test]
    public function the_outbound_run_to_the_laundry_stays_private(): void
    {
        // Leg two is the one that really is none of the customer's business:
        // their clothes are going away from them and there is nothing they would
        // ring a driver about. The narrowing stops here.
        $driver = $this->driverUser('+201033330065', zoneIds: [$this->geo['zones'][0]->id]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $this->walk($order, TaskType::PickupFromCustomer, $driver);
        $this->assignOnly($order->fresh(), TaskType::DeliverToLaundry, $driver);

        $driver->profile()->update([
            'last_lat' => 30.05, 'last_lng' => 31.21, 'located_at' => now(),
        ]);

        $data = $this->track($order->fresh());

        $this->assertSame($driver->name, $data['driver']['name']);
        $this->assertNull($data['driver']['phone'], 'the run to the laundry should not expose a number');
        $this->assertNull($data['driver']['location'], 'the run to the laundry should not be mapped');
    }

    // -------------------------------------------------------- bringing it back

    #[Test]
    public function the_driver_bringing_the_clothes_back_is_named_with_a_phone(): void
    {
        $driver = $this->driverUser('+201033330062', zoneIds: [$this->geo['zones'][0]->id]);
        $order = $this->readyForDelivery($driver);

        $this->walk($order, TaskType::CollectFromLaundry, $driver);
        $this->assignOnly($order->fresh(), TaskType::DeliverToCustomer, $driver);

        $data = $this->track($order->fresh());

        // This is the leg the customer is actually standing at home for.
        $this->assertNotNull($data['driver'], 'no driver on the final delivery leg');
        $this->assertSame($driver->name, $data['driver']['name']);
        $this->assertSame($driver->phone, $data['driver']['phone'], 'no phone while the driver is at the door');
    }

    #[Test]
    public function the_final_leg_reports_a_live_location(): void
    {
        $driver = $this->driverUser('+201033330063', zoneIds: [$this->geo['zones'][0]->id]);
        $order = $this->readyForDelivery($driver);

        $this->walk($order, TaskType::CollectFromLaundry, $driver);
        $this->assignOnly($order->fresh(), TaskType::DeliverToCustomer, $driver);

        $driver->profile()->update([
            'last_lat' => 30.05, 'last_lng' => 31.21, 'located_at' => now(),
        ]);

        $data = $this->track($order->fresh());

        $this->assertNotNull($data['driver']['location'], 'no dot on the way back to the customer');
        $this->assertEqualsWithDelta(30.05, $data['driver']['location']['lat'], 0.0001);
    }

    // --------------------------------------------------------------- the steps

    #[Test]
    public function the_timeline_says_the_clothes_are_on_their_way_back(): void
    {
        $driver = $this->driverUser('+201033330064', zoneIds: [$this->geo['zones'][0]->id]);
        $order = $this->readyForDelivery($driver);

        $this->walk($order, TaskType::CollectFromLaundry, $driver);
        $this->assignOnly($order->fresh(), TaskType::DeliverToCustomer, $driver);

        $data = $this->track($order->fresh());

        $steps = collect($data['steps'])->keyBy('status');

        // Eight, not the landing page's six: the two «on the way» states are
        // what the tracking screen adds.
        $this->assertCount(8, $data['steps']);

        $this->assertTrue($steps[OrderStatus::ReadyForDelivery->value]['reached']);
        $this->assertFalse($steps[OrderStatus::Delivered->value]['reached']);

        // The gap this whole test exists for. Collecting from the laundry moves
        // no status, so before this the screen said «جاهز للتسليم» for the
        // entire journey home.
        $this->assertArrayHasKey(OrderTimeline::OUT_FOR_DELIVERY, $steps->all());
        $this->assertTrue(
            $steps[OrderTimeline::OUT_FOR_DELIVERY]['reached'],
            'the timeline does not say the clothes are on their way back'
        );
        $this->assertNotNull($steps[OrderTimeline::OUT_FOR_DELIVERY]['at']);
    }

    #[Test]
    public function the_way_back_is_not_announced_before_the_clothes_leave(): void
    {
        $driver = $this->driverUser('+201033330066', zoneIds: [$this->geo['zones'][0]->id]);
        $order = $this->readyForDelivery($driver);

        // Assigned, not collected. Both return legs are assigned together the
        // moment the laundry marks an order ready, so «assigned» would light
        // this up while the clothes were still on a shelf.
        $this->assignOnly($order, TaskType::CollectFromLaundry, $driver);

        $steps = collect($this->track($order->fresh())['steps'])->keyBy('status');

        $this->assertFalse($steps[OrderTimeline::OUT_FOR_DELIVERY]['reached']);
    }

    #[Test]
    public function the_outbound_driver_step_is_on_the_timeline_too(): void
    {
        $driver = $this->driverUser('+201033330067', zoneIds: [$this->geo['zones'][0]->id]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $before = collect($this->track($order->fresh())['steps'])->keyBy('status');
        $this->assertFalse($before[OrderStatus::DriverOnWay->value]['reached']);

        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');

        // `driver_on_way` has always been a real status the order recorded, and
        // the timeline never showed it — so the screen did not move between
        // placing the order and the driver arriving either.
        $after = collect($this->track($order->fresh())['steps'])->keyBy('status');
        $this->assertTrue($after[OrderStatus::DriverOnWay->value]['reached']);
    }
}
