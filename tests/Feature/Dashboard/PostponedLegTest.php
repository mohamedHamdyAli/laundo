<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Driver\Repositories\DriverRepository;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\TaskService;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «بتظهر في الداشبورد failed مش تأجيل» (the owner, 2026-11-11, order 103).
 *
 * A leg the customer was asked to rebook — postponed, or the customer not there
 * — is stored `failed`, and the panel read it that way: a red «Failed» on the
 * order screen, a red row and a «failed» count on the dispatch board, and a
 * driver dropdown for a trip with no time. It is a postponement waiting on the
 * customer, and the panel now says so.
 */
class PostponedLegTest extends TestCase
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

        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->customer = $this->customer('+201055550001');
    }

    private function order(): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);
        $slot = TimeSlot::firstOrCreate(
            ['start_time' => '10:00:00', 'end_time' => '12:00:00'],
            ['applies_to' => 'both', 'sort_order' => 1, 'status' => 'active'],
        );

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $order->forceFill([
            'laundry_id' => $this->tenant['laundry']->id,
            'status' => OrderStatus::ReadyForDelivery->value,
            'pickup_slot_id' => $slot->id,
            'pickup_date' => now()->addDay()->toDateString(),
            'delivery_slot_id' => $slot->id,
            'delivery_date' => now()->addDays(3)->toDateString(),
        ])->save();

        return $order->fresh();
    }

    /**
     * The delivery leg, failed by its driver because the customer was not there.
     */
    private function absentAtTheDelivery(Order $order): OrderTask
    {
        $driver = $this->driverUser('+201066660001');
        $task = $order->tasks()->where('type', TaskType::DeliverToCustomer->value)->firstOrFail();
        $task->forceFill(['status' => TaskStatus::Started, 'driver_id' => $driver->id])->save();

        return app(TaskService::class)->fail($task->fresh(), $driver, TaskFailureReason::CustomerUnavailable);
    }

    #[Test]
    public function the_order_screen_shows_a_postponement_not_a_failure(): void
    {
        $order = $this->order();
        $task = $this->absentAtTheDelivery($order);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee('data-awaiting-new-time="'.$task->id.'"', false)
            ->assertSee(__('Waiting for the customer to pick a new time'))
            ->assertSee('<span class="status-pill tone-warn">'.e(__('Postponed')).'</span>', false)
            ->assertDontSee('<span class="badge bg-danger">', false);
    }

    #[Test]
    public function a_leg_that_really_failed_still_reads_failed(): void
    {
        // A wrong address is an operator's problem, not the customer's choice.
        $order = $this->order();
        $task = $order->tasks()->where('type', TaskType::DeliverToCustomer->value)->firstOrFail();
        $task->forceFill([
            'status' => TaskStatus::Failed, 'failure_reason' => TaskFailureReason::WrongAddress,
            'attempts' => 2, 'driver_id' => null,
        ])->save();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertDontSee('data-awaiting-new-time="'.$task->id.'"', false)
            ->assertSee('<span class="badge bg-danger">', false);
    }

    #[Test]
    public function the_dispatch_board_does_not_count_it_as_failed_but_lets_a_person_assign_it(): void
    {
        $order = $this->order();
        $task = $this->absentAtTheDelivery($order);

        // A second order whose delivery really failed — the counter still sees it.
        $other = $this->order();
        $other->tasks()->where('type', TaskType::DeliverToCustomer->value)->firstOrFail()->forceFill([
            'status' => TaskStatus::Failed, 'failure_reason' => TaskFailureReason::WrongAddress,
            'attempts' => 2, 'driver_id' => null,
        ])->save();

        // Somebody who could take it, so the dropdown would have been drawn.
        $this->driverUser('+201066660002', zoneIds: [$this->geo['zones'][0]->id]);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index', ['leg' => TaskType::DeliverToCustomer->value]))
            ->assertOk()
            ->assertSee('data-awaiting-new-time="'.$task->id.'"', false);

        $this->assertSame(1, $response->viewData('counts')['failed']);

        // Still the assign form: a customer who agrees a time by phone must not
        // leave the order stuck — past the pickup it cannot be cancelled (the
        // owner, 2026-11-11, after code review).
        $this->assertStringContainsString(
            route('admin.order.tasks.assign', $task->id).'"',
            $response->getContent(),
        );
    }

    #[Test]
    public function a_person_can_give_it_to_a_driver_and_the_customer_is_no_longer_asked(): void
    {
        $order = $this->order();
        $task = $this->absentAtTheDelivery($order);
        $driver = $this->driverUser('+201066660002', zoneIds: [$this->geo['zones'][0]->id]);

        $this->actingAs($this->superAdmin())
            ->from(route('admin.order.show', $order->id))
            ->post(route('admin.order.tasks.assign', $task->id), ['driver_id' => $driver->id])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertSame(TaskStatus::Assigned, $task->fresh()->status);
        $this->assertSame($driver->id, $task->fresh()->driver_id);
        $this->assertFalse($task->fresh()->awaitsNewTime());

        // The app's «choose a new time» stops offering it.
        $this->actingAs($this->customer)
            ->getJson("/api/v1/orders/{$order->id}/reschedule")
            ->assertOk()
            ->assertJsonPath('data.needs_new_time', false);
    }

    #[Test]
    public function the_driver_map_pins_no_leg_waiting_on_the_customer(): void
    {
        $order = $this->order();
        $task = $this->absentAtTheDelivery($order);

        $pinned = app(DriverRepository::class)->waitingLegsForMap()->pluck('id');

        $this->assertNotContains($task->id, $pinned);
        // The order's other open legs are still there.
        $this->assertNotEmpty($pinned);
    }

    #[Test]
    public function an_order_loaded_without_its_window_columns_is_asked_rather_than_guessed(): void
    {
        // An old absence that kept its window, read through a column list that
        // leaves the window out, must not read as «postponed».
        $order = $this->order();
        $task = $order->tasks()->where('type', TaskType::DeliverToCustomer->value)->firstOrFail();
        $task->forceFill([
            'status' => TaskStatus::Failed, 'failure_reason' => TaskFailureReason::CustomerUnavailable,
            'attempts' => 2, 'driver_id' => null,
        ])->save();

        $partial = OrderTask::with('order:id,code')->findOrFail($task->id);

        $this->assertFalse($partial->awaitsNewTime());
    }
}
