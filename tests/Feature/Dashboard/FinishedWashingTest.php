<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\TaskHold;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\FinishedWashing;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\TaskService;
use App\Modules\Report\Data\DateRange;
use App\Modules\Report\Services\LaundryReport;
use App\Modules\User\Models\User;
use App\Services\MenuBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * «خلص الغسيل» — the client's sheet, item 18 (2026-11-08): the collection from
 * the laundry is neither offered, assigned nor started until the laundry marks
 * the order finished; then a driver is sent and the customer sees it.
 */
class FinishedWashingTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $tenant;

    private User $customer;

    private $address;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $geo['zones'][0]);
        $this->driver = $this->driverUser('+201044440001', zoneIds: [$geo['zones'][0]->id]);
        $this->grant('laundry_owner', ['order.view', 'order.update']);
    }

    #[Test]
    public function the_collection_waits_for_the_laundry_to_finish(): void
    {
        $this->assertTrue(app(DriverDispatcher::class)->assignsAutomatically());
        $order = $this->beingCleaned();
        $collect = $this->leg($order, TaskType::CollectFromLaundry);

        // An eligible driver, automatic assignment on — and still nobody holds it.
        $this->assertNull($collect->driver_id);
        $this->assertTrue($collect->awaitsWashing());
        $this->assertSame([], app(DriverDispatcher::class)->candidates($collect));
        $this->assertFalse(OrderTask::needingAPerson()->whereKey($collect->id)->exists());
        $this->assertNull(app(DriverDispatcher::class)->dispatch($collect));
        $this->assertSame(0, app(DriverDispatcher::class)->sweep());

        try {
            app(DriverDispatcher::class)->assign($collect, $this->driver);
            $this->fail('A collection was given to a driver before the laundry finished.');
        } catch (RuntimeException $e) {
            $this->assertSame('washing_not_finished', $e->getMessage());
        }

        // By hand from the order screen: refused with the reason.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.assign', $collect->id), ['driver_id' => $this->driver->id])
            ->assertSessionHas('error', __('The laundry has not finished washing this order yet — its collection is given to a driver once it does.'));
        $this->assertNull($collect->fresh()->driver_id);
    }

    #[Test]
    public function laundry_staff_mark_it_finished_and_a_driver_is_sent(): void
    {
        $order = $this->beingCleaned();
        // Staff hold only `order.view` — and they are the ones at the machines.
        $this->grant('laundry_staff', ['order.view']);
        $staff = User::create([
            'name' => 'Staff', 'email' => 'staff@test.local', 'phone' => '+201011110003',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'laundry_staff')->value('id'),
            'laundry_id' => $this->tenant['laundry']->id, 'phone_verified_at' => now(),
        ]);

        $this->actingAs($staff)->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee(route('admin.order.finish_washing', $order->id));

        $this->actingAs($staff)->post(route('admin.order.finish_washing', $order->id), ['hangers_sent' => 6])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Finished, $order->status);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id, 'to_status' => 'finished', 'actor_type' => 'laundry', 'actor_id' => $staff->id,
        ]);
        // Offered to a driver now, through the automatic path.
        $this->assertSame($this->driver->id, $this->leg($order, TaskType::CollectFromLaundry)->driver_id);
        // And the customer is told.
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->customer->id, 'event' => 'order_washing_finished', 'channel' => 'database',
        ]);

        // The customer's app: the status and its step on the tracking line.
        Sanctum::actingAs($this->customer);
        $steps = $this->getJson("/api/v1/orders/{$order->id}/track", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.status_label', __('Washing finished'))
            ->json('data.steps');
        $finished = collect($steps)->firstWhere('status', 'finished');
        $this->assertNotNull($finished);
        $this->assertTrue($finished['reached']);
    }

    #[Test]
    public function the_driver_can_start_the_collection_only_after_it(): void
    {
        $order = $this->beingCleaned();
        $collect = $this->leg($order, TaskType::CollectFromLaundry);
        // Assigned before the rule existed — the leg is theirs, but it waits.
        $collect->forceFill(['driver_id' => $this->driver->id, 'status' => TaskStatus::Assigned])->save();

        Sanctum::actingAs($this->driver);
        $this->getJson("/api/v1/driver/tasks/{$collect->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.can_start', false)
            ->assertJsonPath('data.blocked_reason', __('The laundry has not finished this order yet — collect it once the laundry marks it finished.'));
        $this->postJson("/api/v1/driver/tasks/{$collect->id}/start", [], $this->apiHeaders())
            ->assertStatus(400)
            ->assertJsonPath('msg', __('The laundry has not finished this order yet — collect it once the laundry marks it finished.'));

        app(FinishedWashing::class)->mark($order->fresh(), $this->tenant['owner']);

        $this->postJson("/api/v1/driver/tasks/{$collect->id}/start", [], $this->apiHeaders())->assertOk();
    }

    #[Test]
    public function a_collection_already_under_way_at_cleaning_still_finishes_through_the_new_step(): void
    {
        $order = $this->beingCleaned();
        $collect = $this->leg($order, TaskType::CollectFromLaundry);
        // Started before «خلص الغسيل» existed.
        $collect->forceFill(['driver_id' => $this->driver->id, 'status' => TaskStatus::Started, 'started_at' => now()])->save();

        // The app is not told it is blocked: it may finish (code review).
        Sanctum::actingAs($this->driver);
        $this->getJson("/api/v1/driver/tasks/{$collect->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.blocked_reason', null);

        app(TaskService::class)->complete($collect->fresh(), $this->driver, ['piece_count' => 2]);

        $order->refresh();
        $this->assertSame(OrderStatus::ReadyForDelivery, $order->status);
        $this->assertSame(
            ['finished', 'ready_for_delivery'],
            $order->statusLogs()->whereIn('to_status', ['finished', 'ready_for_delivery'])->orderBy('id')->pluck('to_status')->all(),
        );
    }

    #[Test]
    public function the_platform_needs_order_update_and_another_laundry_cannot_reach_it(): void
    {
        $order = $this->beingCleaned();

        // A platform account that only reads orders sees no button and cannot press it.
        $this->grant('admin', ['order.view']);
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@test.local', 'phone' => '+201077770001',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'), 'phone_verified_at' => now(),
        ]);
        $this->actingAs($viewer)->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertDontSee(route('admin.order.finish_washing', $order->id));
        $this->actingAs($viewer)->post(route('admin.order.finish_washing', $order->id), ['hangers_sent' => 6])->assertForbidden();

        // Another laundry: not its order.
        $other = $this->laundryWithOwner('B', '+201022220001', '+201022220002');
        $this->actingAs($other['owner'])->post(route('admin.order.finish_washing', $order->id), ['hangers_sent' => 6])->assertNotFound();

        $this->assertSame(OrderStatus::Cleaning, $order->fresh()->status);

        // The platform with `order.update` presses it on the laundry's behalf.
        $this->actingAs($this->superAdmin())->post(route('admin.order.finish_washing', $order->id), ['hangers_sent' => 6])
            ->assertSessionHas('success');
        $this->assertSame(OrderStatus::Finished, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_logs', ['order_id' => $order->id, 'to_status' => 'finished', 'actor_type' => 'admin']);
    }

    #[Test]
    public function only_an_order_being_cleaned_can_be_finished(): void
    {
        $order = $this->placed();

        $this->actingAs($this->tenant['owner'])->post(route('admin.order.finish_washing', $order->id), ['hangers_sent' => 6])
            ->assertSessionHas('error', __('Only an order being cleaned can be marked finished.'));
        $this->assertSame(OrderStatus::AwaitingPickup, $order->fresh()->status);
    }

    // ------------------------------------------------ code review (2026-11-08)

    #[Test]
    public function an_order_whose_laundry_forgot_the_button_is_listed_and_badged_once_its_collection_is_due(): void
    {
        $order = $this->beingCleaned();
        $collect = $this->leg($order, TaskType::CollectFromLaundry);

        // Not due yet: nothing to chase.
        $collect->forceFill(['due_at' => now()->addHour()])->save();
        $this->assertSame(0, Order::washingOverdue()->count());

        $collect->forceFill(['due_at' => now()->subHour()])->save();
        $this->assertSame([$order->id], Order::washingOverdue()->pluck('id')->all());

        // The laundry sees it in its own list and badge.
        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.order.index', ['status' => OrderRepository::WASHING_OVERDUE]))
            ->assertOk()
            ->assertSee($order->code);
        $this->assertGreaterThanOrEqual(1, MenuBadges::for('order'));

        app(FinishedWashing::class)->mark($order->fresh(), $this->tenant['owner']);
        $this->assertSame(0, Order::washingOverdue()->count());
    }

    #[Test]
    public function a_driver_who_already_holds_the_collection_is_told_it_can_go(): void
    {
        $order = $this->beingCleaned();
        $collect = $this->leg($order, TaskType::CollectFromLaundry);
        // Given to them before the rule existed.
        $collect->forceFill(['driver_id' => $this->driver->id, 'status' => TaskStatus::Assigned])->save();

        app(FinishedWashing::class)->mark($order->fresh(), $this->tenant['owner']);

        $this->assertSame($this->driver->id, $collect->fresh()->driver_id);
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->driver->id, 'event' => 'task_assigned', 'channel' => 'database',
            'subject_id' => $collect->id,
        ]);
    }

    #[Test]
    public function a_collection_is_raised_as_stuck_from_when_the_laundry_finished_not_from_the_order(): void
    {
        $this->superAdmin();
        // Nobody can take it once it is offered.
        $this->driver->profile->update(['is_available' => false]);
        $order = $this->beingCleaned();
        $collect = $this->leg($order, TaskType::CollectFromLaundry);
        $collect->forceFill(['created_at' => now()->subDays(2)])->save();
        app(FinishedWashing::class)->mark($order->fresh(), $this->tenant['owner']);
        $this->assertNull($collect->fresh()->driver_id);

        // Pressed a minute ago: not stuck, whatever the leg's age.
        $this->artisan('tasks:alert-stuck')->assertSuccessful();
        $this->assertDatabaseMissing('notification_logs', ['event' => 'task_queued_too_long', 'subject_id' => $collect->id]);

        // Three hours later it is.
        $this->travel(3)->hours();
        $this->artisan('tasks:alert-stuck')->assertSuccessful();
        $this->assertDatabaseHas('notification_logs', ['event' => 'task_queued_too_long', 'subject_id' => $collect->id]);
    }

    #[Test]
    public function the_laundry_s_turnaround_ends_when_it_says_it_finished(): void
    {
        $order = $this->beingCleaned();
        $this->travel(6)->hours();
        app(FinishedWashing::class)->mark($order->fresh(), $this->tenant['owner']);
        // The driver collects ten hours later; the laundry is not charged for it.
        $this->travel(10)->hours();
        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'driver');

        $row = collect(app(LaundryReport::class)->performance(DateRange::lastDays(7)))->first();
        $this->assertEqualsWithDelta(6, (float) $row['average_turnaround_hours'], 0.1);
    }

    // ------------------- the hangers out of the laundry (item 11, 2026-11-09)

    #[Test]
    public function the_laundry_says_how_many_hangers_the_clothes_go_out_on(): void
    {
        $order = $this->beingCleaned();

        $this->actingAs($this->tenant['owner'])->get(route('admin.order.show', $order->id))
            ->assertOk()->assertSee('name="hangers_sent"', false);

        // Not said: refused beside the field, nothing moves.
        $this->actingAs($this->tenant['owner'])->post(route('admin.order.finish_washing', $order->id))
            ->assertSessionHasErrors('hangers_sent');
        $this->assertSame(OrderStatus::Cleaning, $order->fresh()->status);

        $this->actingAs($this->tenant['owner'])->post(route('admin.order.finish_washing', $order->id), ['hangers_sent' => 6])
            ->assertSessionHas('success');
        $this->assertSame(OrderStatus::Finished, $order->fresh()->status);
        $this->assertSame(6, $order->fresh()->hangers_sent);
    }

    #[Test]
    public function the_driver_counts_the_hangers_and_a_different_count_waits_for_the_platform(): void
    {
        $leg = $this->collectionStarted(hangers: 6);

        Sanctum::actingAs($this->driver);
        $this->getJson("/api/v1/driver/tasks/{$leg->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.requires_hanger_count', true)
            ->assertJsonPath('data.hanger_count', null);

        // Five against six: held, and the laundry's figure is not given away.
        $this->complete($leg, ['piece_count' => 2, 'hanger_count' => 5])
            ->assertStatus(409)
            ->assertJsonPath('key', 'awaiting_approval')
            ->assertJsonPath('msg', __('The hangers do not match what the laundry wrote. It was sent to the management for approval — wait for their answer.'))
            ->assertJsonPath('data.hold.kind', 'hanger_count')
            ->assertJsonPath('data.hold.expected', null);
        $this->assertSame(TaskStatus::Started, $leg->fresh()->status);

        $hold = TaskHold::firstOrFail();
        $this->actingAs($this->superAdmin())->get(route('admin.order.show', $leg->order_id))
            ->assertOk()
            ->assertSee(__('Waiting for your approval — the hangers from the laundry'))
            ->assertSee(__('Received :reported hangers at the laundry against the :expected it wrote.', ['reported' => 5, 'expected' => 6]));

        $this->actingAs($this->superAdmin())->post(route('admin.order.hold.approve', $hold->id))->assertRedirect();

        $leg->refresh();
        $this->assertSame(TaskStatus::Completed, $leg->status);
        $this->assertSame(5, $leg->hangers_collected);
        $this->assertSame(OrderStatus::ReadyForDelivery, $leg->order->fresh()->status);
    }

    #[Test]
    public function the_same_hangers_complete_at_once(): void
    {
        $leg = $this->collectionStarted(hangers: 6);

        $this->complete($leg, ['piece_count' => 2, 'hanger_count' => 6])->assertOk();

        $this->assertSame(6, $leg->fresh()->hangers_collected);
        $this->assertSame(0, TaskHold::count());
    }

    #[Test]
    public function pieces_and_hangers_both_wrong_is_one_question_with_both_figures(): void
    {
        $leg = $this->collectionStarted(hangers: 6);

        $this->complete($leg, ['piece_count' => 3, 'hanger_count' => 5])->assertStatus(409);

        $hold = TaskHold::sole();
        $this->assertSame('piece_count', $hold->kind);
        $this->assertStringContainsString(
            __('Received :reported hangers at the laundry against the :expected it wrote.', ['reported' => 5, 'expected' => 6]),
            $hold->describe(),
        );

        // One approval accepts the whole confirmation.
        app(TaskService::class)->approveHold($hold, $this->platformOperator());
        $this->assertSame([3, 5], [$leg->fresh()->piece_count, $leg->fresh()->hangers_collected]);
    }

    #[Test]
    public function an_app_that_does_not_ask_yet_keeps_completing_collections(): void
    {
        $leg = $this->collectionStarted(hangers: 6);

        $this->complete($leg, ['piece_count' => 2])->assertOk();
        $this->assertNull($leg->fresh()->hangers_collected);
    }

    #[Test]
    public function a_driver_held_over_the_hangers_cannot_leave_them_out_the_next_time(): void
    {
        // Code review (2026-11-09): resending without `hanger_count` completed
        // the leg and closed the question as «withdrawn», nobody asked.
        $leg = $this->collectionStarted(hangers: 6);
        $this->complete($leg, ['piece_count' => 2, 'hanger_count' => 5])->assertStatus(409);

        $this->complete($leg, ['piece_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.hanger_count.0', __('Please enter the number of hangers you received.'));
        $this->assertSame(TaskStatus::Started, $leg->fresh()->status);
        $this->assertSame(TaskHold::PENDING, TaskHold::sole()->status);

        // Refused by the platform: still asked for.
        app(TaskService::class)->rejectHold(TaskHold::sole(), $this->platformOperator(), 'Count again');
        $this->complete($leg, ['piece_count' => 2])->assertStatus(422);

        // With the right figure it completes.
        $this->complete($leg, ['piece_count' => 2, 'hanger_count' => 6])->assertOk();
        $this->assertSame(6, $leg->fresh()->hangers_collected);
    }

    #[Test]
    public function an_order_finished_without_a_figure_is_not_checked(): void
    {
        $leg = $this->collectionStarted(hangers: null);

        Sanctum::actingAs($this->driver);
        $this->getJson("/api/v1/driver/tasks/{$leg->id}", $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.requires_hanger_count', false);
        $this->complete($leg, ['piece_count' => 2, 'hanger_count' => 9])->assertOk();
    }

    /**
     * Washed with `$hangers` written, the collection given to the driver and
     * started.
     */
    private function collectionStarted(?int $hangers): OrderTask
    {
        $order = $this->beingCleaned();
        app(FinishedWashing::class)->mark($order->fresh(), $this->tenant['owner'], $hangers);

        $leg = $this->leg($order, TaskType::CollectFromLaundry);
        $this->assertSame($this->driver->id, $leg->driver_id);

        return app(TaskService::class)->start($leg, $this->driver);
    }

    private function complete(OrderTask $leg, array $data)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->driver);

        return $this->postJson("/api/v1/driver/tasks/{$leg->id}/complete", $data, $this->apiHeaders());
    }

    #[Test]
    public function the_state_machine_no_longer_skips_the_step(): void
    {
        $this->assertSame([OrderStatus::Finished], OrderStatus::Cleaning->allowedNext());
        $this->assertSame([OrderStatus::ReadyForDelivery], OrderStatus::Finished->allowedNext());
        $this->assertFalse(OrderStatus::Finished->isCancellable());
        $this->assertTrue(OrderStatus::Finished->isInCustody());
    }

    /**
     * Counted (2 pieces), priced, sent, confirmed: `cleaning`, the pieces at the
     * laundry. The first two legs done.
     */
    private function beingCleaned(): Order
    {
        $order = $this->placed();

        foreach ([TaskType::PickupFromCustomer, TaskType::DeliverToLaundry] as $type) {
            $this->leg($order, $type)->forceFill(['status' => TaskStatus::Completed, 'completed_at' => now(), 'driver_id' => $this->driver->id])->save();
        }

        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $reviews = app(OrderReviewService::class);
        $reviews->review($order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);
        $this->sendReview($order);
        $reviews->confirm($order->fresh(), $this->customer);

        $order->refresh();
        $this->assertSame(OrderStatus::Cleaning, $order->status);

        return $order;
    }

    private function placed(): Order
    {
        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'payment_method' => 'cash',
            'accepts_review_terms' => true,
        ]);
    }

    private function leg(Order $order, TaskType $type): OrderTask
    {
        return OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();
    }
}
