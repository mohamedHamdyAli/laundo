<?php

namespace Tests\Feature\Api;

use App\Modules\City\Models\City;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\TaskGenerator;
use App\Modules\Order\Services\TaskService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four legs, and the driver app that walks them.
 *
 * The safety property everything else rests on: **nothing can be delivered that
 * was never collected.** The chain is created all at once, but three quarters of
 * it is not doable until the leg before it is done.
 */
class DriverTaskTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private array $tenant;

    private User $customer;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        foreach ($this->geo['zones'] as $zone) {
            $zone->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
        }

        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    // ------------------------------------------------------------- generation

    #[Test]
    public function placing_an_order_creates_all_four_legs(): void
    {
        // At placement, not at confirmation: the first thing that has to happen
        // to a new order is somebody going to collect it.
        $order = $this->placedOrder();

        $tasks = OrderTask::where('order_id', $order->id)->orderBy('sequence')->get();

        $this->assertCount(4, $tasks);
        $this->assertSame(
            ['pickup_from_customer', 'deliver_to_laundry', 'collect_from_laundry', 'deliver_to_customer'],
            $tasks->pluck('type')->map(fn ($t) => $t->value)->all()
        );
        $this->assertSame([1, 2, 3, 4], $tasks->pluck('sequence')->all());
    }

    #[Test]
    public function generating_twice_does_not_double_book_a_doorstep(): void
    {
        $order = $this->placedOrder();

        app(TaskGenerator::class)->generate($order);
        app(TaskGenerator::class)->generate($order);

        $this->assertSame(4, OrderTask::where('order_id', $order->id)->count());
    }

    #[Test]
    public function a_leg_cannot_be_started_before_its_predecessor_completes(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $second = OrderTask::where('order_id', $order->id)
            ->where('type', TaskType::DeliverToLaundry->value)->firstOrFail();

        // It exists and it is assigned — but leg 1 has not happened.
        $this->assertFalse($second->predecessorComplete());
        $this->assertFalse($second->isActionable());

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$second->id}/start", [], $this->apiHeaders())
            ->assertStatus(400);
    }

    // --------------------------------------------------------------- dispatch

    #[Test]
    public function tasks_go_to_a_driver_who_serves_the_zone(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $this->assertSame(
            4,
            OrderTask::where('order_id', $order->id)->where('driver_id', $driver->id)->count()
        );
    }

    #[Test]
    public function a_task_nobody_can_take_waits_in_the_queue(): void
    {
        // A driver who serves a different zone entirely.
        $this->driverUser('01033330001', zoneIds: [$this->geo['zones'][1]->id]);

        $order = $this->placedOrder();

        $tasks = OrderTask::where('order_id', $order->id)->get();

        // Accepted and queued rather than forced on somebody ineligible.
        $this->assertTrue($tasks->every(fn ($t) => $t->driver_id === null));
        $this->assertTrue($tasks->every(fn ($t) => $t->status === TaskStatus::Pending));
    }

    #[Test]
    public function an_unavailable_driver_is_not_offered_work(): void
    {
        $this->driverUser('01033330001', available: false, zoneIds: [$this->geo['zones'][0]->id]);

        $this->placedOrder();

        $this->assertSame(4, OrderTask::queued()->count());
    }

    #[Test]
    public function a_driver_at_capacity_is_skipped(): void
    {
        $driver = $this->eligibleDriver();
        $driver->profile->update(['max_concurrent_orders' => 1]);

        $this->placedOrder();
        // One order in hand; the cap is one.
        $this->assertSame(1, app(DriverDispatcher::class)->activeOrders($driver));

        $second = $this->placedOrder('01099887777');

        $this->assertSame(
            4,
            OrderTask::where('order_id', $second->id)->whereNull('driver_id')->count()
        );
    }

    #[Test]
    public function a_driver_in_another_city_is_skipped(): void
    {
        $driver = $this->eligibleDriver();

        // A city that is not the order's.
        $elsewhere = City::create([
            'name' => json_encode(['en' => 'Alexandria', 'ar' => 'الإسكندرية'], JSON_UNESCAPED_UNICODE),
            'country_id' => $this->geo['country']->id, 'status' => 'active',
        ]);
        $driver->profile->update(['city_id' => $elsewhere->id]);

        $this->placedOrder();

        $this->assertSame(4, OrderTask::queued()->count());
    }

    #[Test]
    public function the_sweep_picks_up_a_driver_who_came_on_shift_later(): void
    {
        // Nobody eligible when the order was placed.
        $order = $this->placedOrder();
        $this->assertSame(4, OrderTask::queued()->count());

        // A driver becomes available afterwards — an event the queue never hears.
        $driver = $this->eligibleDriver();

        $assigned = app(DriverDispatcher::class)->sweep();

        $this->assertSame(4, $assigned);
        $this->assertSame(
            4,
            OrderTask::where('order_id', $order->id)->where('driver_id', $driver->id)->count()
        );
    }

    // ------------------------------------------------------------ the driver

    #[Test]
    public function a_driver_walks_the_first_leg_end_to_end(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->leg($order, TaskType::PickupFromCustomer);

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/start", [], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'started');

        // Starting the first leg is «في الطريق للاستلام» — the tracking screen has
        // to say so while it is happening, not after.
        $this->assertSame(OrderStatus::DriverOnWay, $order->fresh()->status);

        // The QR check happens before anything is signed for.
        $this->postJson("/api/v1/driver/tasks/{$task->id}/verify",
            ['token' => $order->qr_token], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", [
            'piece_count' => 9,
            'signature' => UploadedFile::fake()->image('signature.png'),
        ], $this->apiHeaders())->assertOk();

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(9, $task->piece_count);
        $this->assertNotNull($task->signature_path);
        $this->assertNotNull($task->durationMinutes());

        // And the order moved, through the state machine.
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id, 'to_status' => 'picked_up', 'actor_type' => 'driver',
        ]);
    }

    #[Test]
    public function the_wrong_qr_code_is_refused(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->started($order, TaskType::PickupFromCustomer, $driver);

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/verify",
            ['token' => 'a-token-from-another-parcel'], $this->apiHeaders())
            ->assertStatus(400);
    }

    #[Test]
    public function a_customer_leg_cannot_be_completed_without_a_signature(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->started($order, TaskType::PickupFromCustomer, $driver);

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete",
            ['piece_count' => 5], $this->apiHeaders())
            ->assertStatus(422);

        $this->assertSame(TaskStatus::Started, $task->fresh()->status);
    }

    #[Test]
    public function the_laundry_leg_needs_no_signature_but_does_need_a_count(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $this->walk($order, TaskType::PickupFromCustomer, $driver, ['piece_count' => 9], signed: true);

        $task = $this->started($order, TaskType::DeliverToLaundry, $driver);

        Sanctum::actingAs($driver);

        // No count: refused.
        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete",
            ['receiver_name' => 'محمود'], $this->apiHeaders())
            ->assertStatus(422);

        // A count and no signature: accepted.
        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete",
            ['piece_count' => 9, 'receiver_name' => 'محمود'], $this->apiHeaders())
            ->assertOk();

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame('محمود', $task->receiver_name);
        $this->assertNull($task->signature_path);
    }

    #[Test]
    public function cash_collected_in_full_marks_the_order_paid(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->deliveredReadyOrder($driver);
        $task = $this->started($order->fresh(), TaskType::DeliverToCustomer, $driver);

        $due = $order->fresh()->payableTotal();

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", [
            'collected_amount' => $due,
            'signature' => UploadedFile::fake()->image('sig.png'),
        ], $this->apiHeaders())->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(OrderStatus::Delivered, $order->status);
    }

    #[Test]
    public function a_short_collection_is_recorded_and_the_order_stays_unpaid(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->deliveredReadyOrder($driver);
        $task = $this->started($order->fresh(), TaskType::DeliverToCustomer, $driver);

        $due = $order->fresh()->payableTotal();

        Sanctum::actingAs($driver);

        // By decision the delivery completes: a driver at the customer's door is
        // the worst possible place to argue about the difference.
        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", [
            'collected_amount' => $due - 5,
            'signature' => UploadedFile::fake()->image('sig.png'),
        ], $this->apiHeaders())->assertOk();

        $task->refresh();
        $order->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertEquals($due - 5, (float) $task->collected_amount);
        // Surfaced as an unpaid order rather than lost in a rounding difference.
        $this->assertSame('unpaid', $order->payment_status);
    }

    // ------------------------------------------------------------- failures

    #[Test]
    public function a_failed_leg_returns_to_the_queue(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->started($order, TaskType::PickupFromCustomer, $driver);

        // Nobody else to take it, so it stays queued rather than bouncing back.
        $driver->profile->update(['is_available' => false]);

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/fail",
            ['reason' => 'customer_unavailable'], $this->apiHeaders())
            ->assertOk();

        $task->refresh();
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertNull($task->driver_id);
        $this->assertSame(1, $task->attempts);
        $this->assertNull($task->started_at);
    }

    #[Test]
    public function two_failures_stop_the_task_going_round_again(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->leg($order, TaskType::PickupFromCustomer);

        Sanctum::actingAs($driver);

        foreach ([1, 2] as $attempt) {
            $task->refresh();

            if ($task->driver_id === null) {
                app(DriverDispatcher::class)->assign($task, $driver);
                $task->refresh();
            }

            $this->postJson("/api/v1/driver/tasks/{$task->id}/start", [], $this->apiHeaders());
            $this->postJson("/api/v1/driver/tasks/{$task->id}/fail",
                ['reason' => 'wrong_address'], $this->apiHeaders())->assertOk();
        }

        $task->refresh();
        // A task failing repeatedly is not a dispatch problem.
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(2, $task->attempts);
        $this->assertNull($task->driver_id);
    }

    #[Test]
    public function operations_can_put_an_exhausted_task_back_into_play(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->leg($order, TaskType::PickupFromCustomer);

        Sanctum::actingAs($driver);

        foreach ([1, 2] as $attempt) {
            $task->refresh();

            if ($task->driver_id === null) {
                app(DriverDispatcher::class)->assign($task, $driver);
                $task->refresh();
            }

            $this->postJson("/api/v1/driver/tasks/{$task->id}/start", [], $this->apiHeaders());
            $this->postJson("/api/v1/driver/tasks/{$task->id}/fail",
                ['reason' => 'wrong_address'], $this->apiHeaders())->assertOk();
        }

        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);

        // An escalation nobody can act on is not an escalation. Assigning it is
        // exactly how a person resolves one.
        app(DriverDispatcher::class)->assign($task->fresh(), $driver);

        $task->refresh();
        $this->assertSame(TaskStatus::Assigned, $task->status);
        $this->assertSame($driver->id, $task->driver_id);
        // The history is not laundered: the attempts still stand.
        $this->assertSame(2, $task->attempts);
    }

    #[Test]
    public function a_completed_leg_can_never_be_reassigned(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $this->walk($order, TaskType::PickupFromCustomer, $driver, ['piece_count' => 2], signed: true);

        $task = $this->leg($order, TaskType::PickupFromCustomer);
        $this->assertSame(TaskStatus::Completed, $task->status);

        // The handover happened; re-running it would ask a driver to collect
        // clothes that are no longer there.
        $this->expectException(\RuntimeException::class);
        app(DriverDispatcher::class)->assign($task, $driver);
    }

    #[Test]
    public function cancelling_an_order_closes_its_open_legs(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $this->assertSame(4, OrderTask::where('order_id', $order->id)->open()->count());

        app(OrderService::class)->cancel($order, $this->customer, 'changed my mind');

        // Nobody should be driving to collect an order that no longer exists.
        $this->assertSame(0, OrderTask::where('order_id', $order->id)->open()->count());
    }

    #[Test]
    public function a_count_dispute_halts_the_order_instead_of_requeueing(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->started($order, TaskType::PickupFromCustomer, $driver);

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/fail", [
            'reason' => 'piece_count_mismatch',
            'note' => 'العميل بيقول 12 والمكتوب 9',
        ], $this->apiHeaders())->assertOk();

        $task->refresh();

        // Stopped on the first attempt: sending another driver would move clothes
        // already in dispute.
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertNull($task->driver_id);

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'actor_type' => 'driver',
        ]);
    }

    #[Test]
    public function other_requires_an_explanation(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->started($order, TaskType::PickupFromCustomer, $driver);

        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/fail",
            ['reason' => 'other'], $this->apiHeaders())->assertStatus(422);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/fail",
            ['reason' => 'other', 'note' => 'العربية اتعطلت'], $this->apiHeaders())->assertOk();
    }

    // ------------------------------------------------------------- isolation

    #[Test]
    public function a_driver_cannot_see_or_touch_another_drivers_task(): void
    {
        $mine = $this->eligibleDriver();
        $order = $this->placedOrder();
        $task = $this->leg($order, TaskType::PickupFromCustomer);

        $stranger = $this->driverUser('01044440002', zoneIds: [$this->geo['zones'][0]->id]);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/driver/tasks/{$task->id}", $this->apiHeaders())->assertNotFound();
        $this->postJson("/api/v1/driver/tasks/{$task->id}/start", [], $this->apiHeaders())->assertNotFound();
        $this->postJson("/api/v1/driver/tasks/{$task->id}/fail",
            ['reason' => 'wrong_address'], $this->apiHeaders())->assertNotFound();

        $this->assertSame($mine->id, $task->fresh()->driver_id);
    }

    #[Test]
    public function a_customer_token_cannot_reach_the_driver_endpoints(): void
    {
        $this->eligibleDriver();
        $this->placedOrder();

        Sanctum::actingAs($this->customer);

        // Re-read through the Driver model, so a customer is not a driver even
        // with a valid token.
        $this->getJson('/api/v1/driver/tasks', $this->apiHeaders())->assertForbidden();
        $this->getJson('/api/v1/driver/summary', $this->apiHeaders())->assertForbidden();
    }

    #[Test]
    public function the_task_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/driver/tasks', $this->apiHeaders())->assertUnauthorized();
        $this->getJson('/api/v1/driver/summary', $this->apiHeaders())->assertUnauthorized();
    }

    // ------------------------------------------------------------- the lists

    #[Test]
    public function the_summary_screen_counts_what_the_design_shows(): void
    {
        $driver = $this->eligibleDriver();
        $this->placedOrder();

        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/v1/driver/summary', $this->apiHeaders());

        $response->assertOk()
            ->assertJsonPath('data.is_available', true)
            // Two collections and two deliveries per order.
            ->assertJsonPath('data.counters.collections', 2)
            ->assertJsonPath('data.counters.deliveries', 2)
            ->assertJsonPath('data.counters.completed_today', 0);

        // And the next thing to do.
        $this->assertSame('pickup_from_customer', $response->json('data.current_task.type'));
    }

    #[Test]
    public function the_task_list_filters_by_kind_and_by_state(): void
    {
        $driver = $this->eligibleDriver();
        $this->placedOrder();

        Sanctum::actingAs($driver);

        $this->assertCount(4, $this->getJson('/api/v1/driver/tasks', $this->apiHeaders())->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/driver/tasks?kind=collection', $this->apiHeaders())->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/driver/tasks?kind=delivery', $this->apiHeaders())->json('data'));
        $this->assertCount(4, $this->getJson('/api/v1/driver/tasks?state=new', $this->apiHeaders())->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/driver/tasks?state=completed', $this->apiHeaders())->json('data'));
    }

    #[Test]
    public function history_shows_durations_and_failure_reasons(): void
    {
        $driver = $this->eligibleDriver();
        $order = $this->placedOrder();

        $this->walk($order, TaskType::PickupFromCustomer, $driver, ['piece_count' => 9], signed: true);

        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/v1/driver/tasks/history', $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.0.status', 'completed');
        $this->assertNotNull($response->json('data.0.duration_minutes'));
        $this->assertNull($response->json('data.0.failure_reason'));
    }

    #[Test]
    public function the_failure_reason_list_comes_from_the_api(): void
    {
        $driver = $this->eligibleDriver();
        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/v1/driver/tasks/failure-reasons', $this->apiHeaders());

        $response->assertOk()->assertJsonCount(5, 'data');

        $other = collect($response->json('data'))->firstWhere('value', 'other');
        $this->assertTrue($other['requires_note']);
    }

    // ------------------------------------------------------------------ helpers

    private function eligibleDriver(): Driver
    {
        return $this->driverUser('01044440001', zoneIds: [$this->geo['zones'][0]->id]);
    }

    /**
     * A freshly placed order — which, from P8, already has its four legs.
     */
    private function placedOrder(string $phone = '01099887766'): Order
    {
        $customer = $phone === '01099887766' ? $this->customer : $this->customer($phone);
        $address = $phone === '01099887766'
            ? $this->address
            : $this->addressFor($customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    /**
     * An order walked, through the real legs, all the way to the final delivery.
     *
     * Deliberately not shortcut with the state machine: the point of the fixture
     * is that the pipeline is driven by the tasks, so a helper that forced the
     * statuses would test nothing.
     */
    private function deliveredReadyOrder(Driver $driver): Order
    {
        $order = $this->placedOrder();

        // Legs 1 and 2 — collect and hand over.
        $this->walk($order, TaskType::PickupFromCustomer, $driver, ['piece_count' => 2], signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, $driver, ['piece_count' => 2]);

        // The laundry prices it and the customer agrees.
        $reviews = app(OrderReviewService::class);
        $reviews->review(
            $order->fresh(),
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            null,
            $this->tenant['owner']
        );
        $reviews->confirm($order->fresh(), $this->customer);

        // The laundry does the work.
        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');

        // Leg 3 — collect the finished order.
        $this->walk($order->fresh(), TaskType::CollectFromLaundry, $driver, ['piece_count' => 2]);

        return $order->fresh();
    }

    private function leg(Order $order, TaskType $type): OrderTask
    {
        return OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();
    }

    /**
     * A task assigned and actually started, through the service.
     *
     * Deliberately not a direct status write: starting the first leg is what
     * moves the order to «في الطريق للاستلام», and a helper that skipped it would
     * leave the order in a state its own completion could not advance from —
     * which is exactly the bug this helper had on its first attempt.
     */
    private function started(Order $order, TaskType $type, Driver $driver): OrderTask
    {
        $task = $this->leg($order, $type);

        if ($task->driver_id === null) {
            app(DriverDispatcher::class)->assign($task, $driver);
            $task->refresh();
        }

        return app(TaskService::class)->start($task, $driver);
    }

    private function walk(Order $order, TaskType $type, Driver $driver, array $data = [], bool $signed = false): void
    {
        $task = $this->started($order, $type, $driver);

        if ($signed) {
            $data['signature'] = UploadedFile::fake()->image('sig.png');
        }

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", $data, $this->apiHeaders())
            ->assertOk();
    }
}
