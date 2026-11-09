<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\TaskService;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Setting\Models\Setting;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «رسوم المشوار» — the client's sheet, items 3 and 4 (2026-11-08).
 *
 * The owner: each trip to the customer, the pickup and the return, is charged
 * one figure set from the panel (25); a cash order pays the pickup's at the
 * pickup; online covers both; two orders on the same address, day and window
 * share one trip; and the fee moves to the other order when its carrier is
 * cancelled.
 */
class TripFeeTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private array $tenant;

    private User $customer;

    private $address;

    private TimeSlot $morning;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $this->morning = TimeSlot::create([
            'start_time' => '10:00', 'end_time' => '13:00', 'applies_to' => 'both',
            'status' => 'active', 'sort_order' => 1,
        ]);
    }

    #[Test]
    public function each_trip_is_charged_the_set_fee_whatever_the_distance(): void
    {
        $order = $this->place();

        $this->assertSame('25.00', $order->pickup_fee);
        $this->assertSame('25.00', $order->return_fee);
        $this->assertSame('50.00', $order->delivery_fee);
    }

    #[Test]
    public function the_fee_is_the_setting_and_blank_is_twenty_five(): void
    {
        $this->setting('Trip_Fee', '30');
        $this->assertSame('60.00', $this->place()->delivery_fee);

        $this->setting('Trip_Fee', '');
        $this->assertSame('50.00', $this->place(day: 2)->delivery_fee);

        $this->setting('Trip_Fee', '0');
        $this->assertSame('0.00', $this->place(day: 3)->delivery_fee);
    }

    #[Test]
    public function a_second_order_on_the_same_visit_rides_free(): void
    {
        $first = $this->place();
        $second = $this->place();

        $this->assertSame('25.00', $first->pickup_fee);
        $this->assertSame('0.00', $second->pickup_fee);
        $this->assertSame('0.00', $second->return_fee);
        $this->assertSame('0.00', $second->delivery_fee);
    }

    #[Test]
    public function the_quote_already_says_the_trip_is_shared(): void
    {
        $this->place();
        Sanctum::actingAs($this->customer);

        $quote = $this->postJson('/api/v1/orders/quote', $this->payload(), $this->apiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertEquals(0, $quote['pickup_fee']);
        $this->assertEquals(0, $quote['delivery_fee']);
    }

    #[Test]
    public function another_day_another_window_or_another_address_is_another_trip(): void
    {
        $this->place();

        $this->assertSame('50.00', $this->place(day: 2)->delivery_fee);

        $evening = TimeSlot::create([
            'start_time' => '17:00', 'end_time' => '20:00', 'applies_to' => 'both', 'status' => 'active', 'sort_order' => 2,
        ]);
        $this->assertSame('50.00', $this->place(slot: $evening)->delivery_fee);

        $work = $this->addressFor($this->customer, $this->geo['zones'][0], label: 'Work');
        $this->assertSame('50.00', $this->place(address: $work)->delivery_fee);
    }

    #[Test]
    public function a_pickup_already_made_is_not_shared(): void
    {
        $first = $this->place();
        $first->forceFill(['status' => OrderStatus::PickedUp])->save();

        // The driver has been and gone: this one's pickup is a new trip. Its
        // return still rides with the first order's.
        $second = $this->place();

        $this->assertSame('25.00', $second->pickup_fee);
        $this->assertSame('0.00', $second->return_fee);
    }

    #[Test]
    public function cancelling_the_order_that_pays_the_trip_moves_the_fee_and_tells_the_customer(): void
    {
        $carrier = $this->place();
        $rider = $this->place();
        $before = (float) $rider->estimated_total;

        app(OrderService::class)->cancel($carrier->fresh(), $this->customer);

        $rider->refresh();
        $this->assertSame('25.00', $rider->pickup_fee);
        $this->assertSame('25.00', $rider->return_fee);
        $this->assertSame('50.00', $rider->delivery_fee);
        $this->assertEqualsWithDelta($before + 50, (float) $rider->estimated_total, 0.01);

        // The customer is told the new total, in their list.
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->customer->id,
            'event' => 'trip_fee_moved',
            'channel' => 'database',
            'subject_id' => $rider->id,
        ]);
    }

    #[Test]
    public function postponing_the_order_that_pays_the_trip_moves_the_fee_to_the_one_still_collected(): void
    {
        // Code review (2026-11-08): the carrier leaves the visit, the other order
        // is still collected at it — somebody must pay for that trip.
        $carrier = $this->place();
        $rider = $this->place();
        $driver = $this->driverUser();
        $leg = $this->leg($carrier, TaskType::PickupFromCustomer);
        $leg->forceFill(['driver_id' => $driver->id, 'status' => TaskStatus::Assigned])->save();

        app(TaskService::class)->fail($leg->fresh(), $driver, TaskFailureReason::CustomerPostponed);

        $this->assertSame('25.00', $rider->fresh()->pickup_fee);
        // The carrier keeps its own fee for the time it picks — not charged twice.
        $this->assertSame('25.00', $carrier->fresh()->pickup_fee);
    }

    #[Test]
    public function paying_online_after_the_pickup_charges_only_what_is_left(): void
    {
        $order = $this->place();
        $driver = $this->driverUser();
        $pickup = $this->leg($order, TaskType::PickupFromCustomer);
        $pickup->forceFill(['driver_id' => $driver->id, 'status' => TaskStatus::Assigned])->save();
        Sanctum::actingAs($driver);
        $this->postJson("/api/v1/driver/tasks/{$pickup->id}/start", [], $this->apiHeaders())->assertOk();
        $this->postJson("/api/v1/driver/tasks/{$pickup->id}/complete", ['collected_amount' => 25], $this->apiHeaders())->assertOk();

        $order = $this->reviewedAndSent($order);

        $payment = app(PaymentService::class)
            ->initiate($order, $this->customer, PaymentMethod::Card);

        $this->assertEqualsWithDelta($order->payableTotal() - 25, (float) $payment->amount, 0.01);
    }

    #[Test]
    public function a_paid_order_never_has_a_fee_moved_onto_it(): void
    {
        $carrier = $this->place();
        $rider = $this->place();
        $rider->forceFill(['payment_status' => 'paid'])->save();

        app(OrderService::class)->cancel($carrier->fresh(), $this->customer);

        $this->assertSame('0.00', $rider->fresh()->delivery_fee);
    }

    #[Test]
    public function a_cash_pickup_collects_the_trip_fee_and_the_delivery_the_rest(): void
    {
        $order = $this->place();
        $driver = $this->driverUser();
        $pickup = $this->leg($order, TaskType::PickupFromCustomer);
        $pickup->forceFill(['driver_id' => $driver->id, 'status' => TaskStatus::Assigned])->save();
        Sanctum::actingAs($driver);

        $detail = $this->getJson("/api/v1/driver/tasks/{$pickup->id}", $this->apiHeaders())->assertOk()->json('data');
        $this->assertTrue($detail['collects_payment']);
        $this->assertEquals(25, $detail['payment']['to_collect']);
        // Not required: an app that predates the trip fee still completes pickups.
        $this->assertFalse($detail['payment']['collect_required']);

        $this->postJson("/api/v1/driver/tasks/{$pickup->id}/start", [], $this->apiHeaders())->assertOk();

        // More than the trip fee is held for the platform (item 10), not taken.
        $this->postJson("/api/v1/driver/tasks/{$pickup->id}/complete", ['collected_amount' => 30, 'piece_count' => 2], $this->apiHeaders())
            ->assertStatus(409);

        $this->postJson("/api/v1/driver/tasks/{$pickup->id}/complete", ['collected_amount' => 25, 'piece_count' => 2], $this->apiHeaders())
            ->assertOk();

        $order->refresh();
        $this->assertSame(25.0, $order->paidSoFar());
        $this->assertNotSame('paid', $order->payment_status);
        $this->assertEqualsWithDelta((float) $order->estimated_total - 25, $order->stillOwed(), 0.01);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id, 'amount' => 25.00, 'collected_by' => $driver->id, 'order_task_id' => $pickup->id,
        ]);

        // The delivery takes what is left, and that settles it.
        $delivery = $this->leg($order, TaskType::DeliverToCustomer);
        $delivery->forceFill(['driver_id' => $driver->id, 'status' => TaskStatus::Assigned])->save();
        $this->assertEqualsWithDelta($order->stillOwed(), $delivery->fresh()->toCollect(), 0.01);
    }

    #[Test]
    public function a_pickup_that_reports_nothing_leaves_the_fee_for_the_delivery(): void
    {
        $order = $this->place();
        $pickup = $this->leg($order, TaskType::PickupFromCustomer);
        $driver = $this->driverUser();
        $pickup->forceFill(['driver_id' => $driver->id, 'status' => TaskStatus::Assigned])->save();
        Sanctum::actingAs($driver);

        $this->postJson("/api/v1/driver/tasks/{$pickup->id}/start", [], $this->apiHeaders())->assertOk();
        $this->postJson("/api/v1/driver/tasks/{$pickup->id}/complete", ['piece_count' => 2], $this->apiHeaders())->assertOk();

        $order->refresh();
        $this->assertSame(0.0, $order->paidSoFar());
        $this->assertEqualsWithDelta((float) $order->estimated_total, $this->leg($order, TaskType::DeliverToCustomer)->toCollect(), 0.01);
    }

    #[Test]
    public function an_order_paid_online_collects_nothing_at_the_pickup(): void
    {
        $order = $this->place(payment: 'card');

        $this->assertSame(0.0, $this->leg($order, TaskType::PickupFromCustomer)->toCollect());
        // Both trips are in the total it pays online.
        $this->assertSame('50.00', $order->delivery_fee);
    }

    #[Test]
    public function the_customer_is_shown_both_trips_and_what_is_paid_at_the_pickup(): void
    {
        $order = $this->place();
        Sanctum::actingAs($this->customer);

        $pricing = $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())->assertOk()->json('data.pricing');

        $this->assertEquals(25, $pricing['pickup_fee']);
        $this->assertEquals(25, $pricing['return_fee']);
        $this->assertEquals(25, $pricing['pay_at_pickup']);
        $this->assertEquals(0, $pricing['paid_so_far']);
    }

    /**
     * Counted by the laundry and sent to the customer by the platform.
     */
    private function reviewedAndSent(Order $order): Order
    {
        $order->refresh();
        $machine = app(OrderStateMachine::class);

        if ($order->status === OrderStatus::AwaitingPickup) {
            $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
            $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');
        }

        app(OrderReviewService::class)->review(
            $order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']
        );

        return $this->sendReview($order)->fresh();
    }

    private function leg(Order $order, TaskType $type): OrderTask
    {
        return OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();
    }

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::flush();
    }

    private function place(int $day = 1, ?TimeSlot $slot = null, $address = null, string $payment = 'cash'): Order
    {
        return app(OrderService::class)->place($this->customer, $this->payload($day, $slot, $address, $payment));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $day = 1, ?TimeSlot $slot = null, $address = null, string $payment = 'cash'): array
    {
        $date = now()->addDays($day)->toDateString();
        $slot ??= $this->morning;

        return [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => ($address ?? $this->address)->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'pickup_date' => $date,
            'pickup_slot_id' => $slot->id,
            // The service needs its turnaround: the return three days later.
            'delivery_date' => now()->addDays($day + 3)->toDateString(),
            'delivery_slot_id' => $slot->id,
            'payment_method' => $payment,
            'accepts_review_terms' => true,
        ];
    }
}
