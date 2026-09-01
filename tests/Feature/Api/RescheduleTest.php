<?php

namespace Tests\Feature\Api;

use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\TaskService;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «طلب التأجيل» — the customer picks a new time.
 *
 * The bug this closes is easy to miss by reading the code: `CustomerPostponed` was
 * not in `haltsTheOrder()`, so it fell through to the release-and-dispatch branch.
 * A driver recording "the customer asked to postpone" put the same journey back in
 * front of the next eligible driver within seconds. Nobody was waiting on
 * anything; the same failure simply happened again, and again, until the task
 * exhausted its attempts and escalated — with the escalation blaming the drivers.
 *
 * So the first test here is the one that matters: after a postponement, nothing
 * dispatches.
 */
class RescheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $tenant;

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
        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
        $this->customer = $this->customer('01055550001');
    }

    private function order(): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $order->forceFill([
            'laundry_id' => $this->tenant['laundry']->id,
            'pickup_slot_id' => $this->slot()->id,
            'pickup_date' => now()->addDay()->toDateString(),
        ])->save();

        return $order->fresh();
    }

    /**
     * A pickup slot. Created here because the test harness seeds none — every
     * other suite that needs one makes its own.
     */
    private function slot(): TimeSlot
    {
        return TimeSlot::firstOrCreate(
            ['start_time' => '10:00:00', 'end_time' => '12:00:00'],
            ['applies_to' => 'both', 'sort_order' => 1, 'status' => 'active'],
        );
    }

    /**
     * The first leg, failed as a postponement by the driver holding it.
     */
    private function postpone(Order $order): OrderTask
    {
        $driver = $this->driverUser('01066660001');

        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill(['status' => TaskStatus::Assigned, 'driver_id' => $driver->id])->save();

        return app(TaskService::class)->fail(
            $task->fresh(),
            $driver,
            TaskFailureReason::CustomerPostponed,
            'العميل قال بكرة'
        );
    }

    // ------------------------------------------------------------- the old bug

    #[Test]
    public function a_postponed_leg_is_not_offered_to_the_next_driver(): void
    {
        // Somebody else who would happily take it.
        $this->driverUser('01066660002');

        $order = $this->order();
        $task = $this->postpone($order);

        // This is the whole point. Before, `release()` and `dispatch()` ran and the
        // task went straight back out.
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertNull($task->driver_id);
    }

    #[Test]
    public function the_slot_is_cleared_so_nothing_looks_scheduled(): void
    {
        $order = $this->order();

        $this->assertNotNull($order->pickup_slot_id);

        $this->postpone($order);

        $order->refresh();

        // A stale time on a postponed order is how somebody reads it as fine.
        $this->assertNull($order->pickup_slot_id);
        $this->assertNull($order->pickup_date);
    }

    #[Test]
    public function only_the_postponed_end_of_the_order_is_cleared(): void
    {
        $order = $this->order();
        $order->forceFill([
            'delivery_slot_id' => $this->slot()->id,
            'delivery_date' => now()->addDays(3)->toDateString(),
        ])->save();

        // Leg 1 is a collection, so the delivery half was never in question.
        $this->postpone($order->fresh());

        $order->refresh();

        $this->assertNull($order->pickup_slot_id);
        $this->assertNotNull($order->delivery_slot_id);
    }

    #[Test]
    public function the_customer_is_asked_to_pick_a_new_time(): void
    {
        $order = $this->order();
        $this->postpone($order);

        $this->assertSame(
            1,
            NotificationLog::where('event', NotificationEvent::RescheduleNeeded->value)
                ->where('user_id', $this->customer->id)
                ->where('channel', 'database')
                ->count()
        );
    }

    #[Test]
    public function a_postponement_is_recorded_on_the_order(): void
    {
        $order = $this->order();
        $this->postpone($order);

        // The note is how anybody reading the order later knows why it stopped.
        $this->assertTrue(
            $order->statusLogs()->where('note', 'like', '%Postponed%')->exists()
        );
    }

    // ------------------------------------------------------- what the app sees

    #[Test]
    public function the_app_is_told_the_order_needs_a_new_time_and_which_end(): void
    {
        $order = $this->order();
        $this->postpone($order);

        $data = $this->actingAs($this->customer)
            ->getJson("/api/v1/orders/{$order->id}/reschedule")
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['needs_new_time']);
        // Inferring the leg from a status would be a guess.
        $this->assertSame('pickup', $data['leg']);
        $this->assertNotEmpty($data['slots']);
    }

    #[Test]
    public function an_order_that_was_never_postponed_needs_nothing(): void
    {
        $order = $this->order();

        $data = $this->actingAs($this->customer)
            ->getJson("/api/v1/orders/{$order->id}/reschedule")
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['needs_new_time']);
        $this->assertNull($data['leg']);
    }

    // ----------------------------------------------------------- rescheduling

    #[Test]
    public function choosing_a_new_time_puts_the_journey_back_in_play(): void
    {
        $order = $this->order();
        $task = $this->postpone($order);

        $newDate = now()->addDays(2)->toDateString();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => $this->slot()->id,
                'date' => $newDate,
            ])
            ->assertOk();

        $order->refresh();
        $task->refresh();

        $this->assertSame($newDate, $order->pickup_date?->toDateString());
        $this->assertNotNull($order->pickup_slot_id);

        // Pending again, and the failure cleared — this is a fresh attempt at a
        // time the customer chose, not a retry of the one they declined.
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertNull($task->failure_reason);
        $this->assertSame(0, $task->attempts);
    }

    #[Test]
    public function rescheduling_does_not_count_towards_exhausting_the_task(): void
    {
        $order = $this->order();
        $task = $this->postpone($order);

        $this->assertSame(1, $task->attempts);

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => $this->slot()->id,
                'date' => now()->addDay()->toDateString(),
            ])
            ->assertOk();

        // A customer choosing a better time is not a driver failing. Without the
        // reset, two postponements would escalate the task to operations and the
        // escalation would blame the drivers.
        $this->assertSame(0, $task->fresh()->attempts);
    }

    #[Test]
    public function the_due_time_follows_the_slot_that_was_chosen(): void
    {
        $order = $this->order();
        $task = $this->postpone($order);

        $slot = $this->slot();
        $date = now()->addDays(2);

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => $slot->id,
                'date' => $date->toDateString(),
            ])
            ->assertOk();

        $due = $task->fresh()->due_at;

        $this->assertNotNull($due);
        $this->assertSame($date->toDateString(), $due->toDateString());
    }

    // ------------------------------------------------------------- refusals

    #[Test]
    public function an_order_that_was_not_postponed_cannot_be_rescheduled(): void
    {
        $order = $this->order();

        // Otherwise a customer could move any order's date at will, past the slot
        // rules the wizard applies.
        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => $this->slot()->id,
                'date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(400);
    }

    #[Test]
    public function a_leg_that_failed_for_another_reason_cannot_be_rescheduled(): void
    {
        $order = $this->order();
        $driver = $this->driverUser('01066660001');

        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill(['status' => TaskStatus::Assigned, 'driver_id' => $driver->id])->save();

        // A wrong address is a data problem. Letting a customer rebook it would
        // hide the bad address behind a new date.
        app(TaskService::class)->fail($task->fresh(), $driver, TaskFailureReason::WrongAddress);

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => $this->slot()->id,
                'date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(400);
    }

    #[Test]
    public function a_date_in_the_past_is_refused(): void
    {
        $order = $this->order();
        $this->postpone($order);

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => $this->slot()->id,
                'date' => now()->subDay()->toDateString(),
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function today_is_allowed(): void
    {
        // Postponed at nine in the morning, wants the afternoon.
        $order = $this->order();
        $this->postpone($order);

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => $this->slot()->id,
                'date' => now()->toDateString(),
            ])
            ->assertOk();
    }

    #[Test]
    public function a_slot_that_does_not_exist_is_refused(): void
    {
        $order = $this->order();
        $this->postpone($order);

        $this->actingAs($this->customer)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => 99999,
                'date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(400);
    }

    #[Test]
    public function somebody_else_cannot_reschedule_your_order(): void
    {
        $order = $this->order();
        $this->postpone($order);

        $intruder = $this->customer('01055550002');

        $this->actingAs($intruder)
            ->postJson("/api/v1/orders/{$order->id}/reschedule", [
                'slot_id' => $this->slot()->id,
                'date' => now()->addDay()->toDateString(),
            ])
            ->assertNotFound();
    }

    #[Test]
    public function a_guest_cannot_reschedule(): void
    {
        $order = $this->order();
        $this->postpone($order);

        $this->postJson("/api/v1/orders/{$order->id}/reschedule", [
            'slot_id' => $this->slot()->id,
            'date' => now()->addDay()->toDateString(),
        ])->assertUnauthorized();
    }
}
