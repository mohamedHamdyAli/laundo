<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderEta;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\RescheduleService;
use App\Modules\Order\Services\TaskService;
use App\Modules\Order\Services\Turnaround;
use App\Modules\Setting\Models\Setting;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Services\SlotClock;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Today's windows on the business's clock (the owner, 2026-09-30).
 *
 * «لما بطلب بميعاد ساعه فاتت بيوصل للمندوب انه متاخر»: at two in the afternoon
 * an order was taken for this morning's 08:00–10:00, and its driver was «late»
 * the moment it was placed. Two faults: nothing refused a window that had
 * ended, and every window was read as UTC while it means Cairo, three hours
 * off. These pin both, at 09:26 in Cairo — the moment in the owner's report.
 */
class BookingWindowTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private User $customer;

    private TimeSlot $morning;

    private TimeSlot $noon;

    private TimeSlot $evening;

    private const TODAY = '2026-09-29';

    private const TOMORROW = '2026-09-30';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->morning = TimeSlot::create(['start_time' => '08:00:00', 'end_time' => '10:00:00', 'applies_to' => 'both', 'sort_order' => 1, 'status' => 'active']);
        $this->noon = TimeSlot::create(['start_time' => '12:00:00', 'end_time' => '15:00:00', 'applies_to' => 'both', 'sort_order' => 2, 'status' => 'active']);
        $this->evening = TimeSlot::create(['start_time' => '18:00:00', 'end_time' => '21:00:00', 'applies_to' => 'both', 'sort_order' => 3, 'status' => 'active']);

        $this->customer = $this->customer();

        // Cairo is UTC+3 in September: 06:26 UTC is 09:26 on the owner's phone.
        config(['app.display_timezone' => 'Africa/Cairo']);
        $this->travelTo(Carbon::parse(self::TODAY.' 06:26:00', 'UTC'));
    }

    private function place(string $pickupDate, TimeSlot $pickup, string $deliveryDate, TimeSlot $delivery)
    {
        Sanctum::actingAs($this->customer);

        return $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'pickup_date' => $pickupDate,
            'pickup_slot_id' => $pickup->id,
            'delivery_date' => $deliveryDate,
            'delivery_slot_id' => $delivery->id,
            'accepts_review_terms' => true,
        ], $this->apiHeaders());
    }

    private function windows(string $date): Collection
    {
        Sanctum::actingAs($this->customer);

        return collect($this->getJson('/api/v1/time-slots?date='.$date, $this->apiHeaders())->assertOk()->json('data'))
            ->keyBy('id');
    }

    // ---------------------------------------------------------------- the clock

    #[Test]
    public function a_window_is_read_on_the_business_clock(): void
    {
        $clock = app(SlotClock::class);

        // 08:00 in Cairo is 05:00 UTC — not 08:00 UTC, as it was read.
        $this->assertSame(self::TODAY.' 05:00:00', $clock->at(self::TODAY, '08:00')->toDateTimeString());

        // A window that ends past midnight ends the next day: 01:00 on the 30th
        // in Cairo, which is 22:00 on the 29th in UTC.
        $late = new TimeSlot(['start_time' => '22:00:00', 'end_time' => '01:00:00']);
        $this->assertSame(self::TODAY.' 22:00:00', $clock->end(self::TODAY, $late)->toDateTimeString());
        $this->assertTrue($clock->end(self::TODAY, $late)->gt($clock->start(self::TODAY, $late)));
    }

    // ---------------------------------------------------------------- offered

    #[Test]
    public function todays_window_that_ends_within_the_hour_is_marked_closed(): void
    {
        $today = $this->windows(self::TODAY);

        // 08:00–10:00 closed at 09:00 with the default hour; it is 09:26.
        $this->assertTrue($today[$this->morning->id]['closed']);
        $this->assertFalse($today[$this->noon->id]['closed']);
        $this->assertFalse($today[$this->evening->id]['closed']);

        // Tomorrow nothing has closed.
        $this->assertFalse($this->windows(self::TOMORROW)->contains('closed', true));
    }

    #[Test]
    public function the_cut_off_is_a_setting(): void
    {
        // 0: open until it ends — 08:00–10:00 is still bookable at 09:26.
        Setting::updateOrCreate(['key' => 'Slot_Booking_Cutoff_Minutes'], ['value' => '0']);
        Cache::flush();
        $this->assertFalse($this->windows(self::TODAY)[$this->morning->id]['closed']);

        // Two hours: 12:00–15:00 closes at 13:00.
        Setting::updateOrCreate(['key' => 'Slot_Booking_Cutoff_Minutes'], ['value' => '120']);
        Cache::flush();
        $this->assertFalse($this->windows(self::TODAY)[$this->noon->id]['closed']);

        $this->travelTo(Carbon::parse(self::TODAY.' 10:30:00', 'UTC')); // 13:30 Cairo
        $this->assertTrue($this->windows(self::TODAY)[$this->noon->id]['closed']);
    }

    // ---------------------------------------------------------------- booked

    #[Test]
    public function an_order_into_a_closed_window_is_refused(): void
    {
        $this->place(self::TODAY, $this->morning, self::TOMORROW, $this->evening)
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_slot_id');

        $this->place(self::TODAY, $this->noon, self::TOMORROW, $this->evening)->assertCreated();
    }

    #[Test]
    public function the_service_refuses_a_closed_delivery_window_too(): void
    {
        // The backstop behind OrderRequest, for a caller that skips it. With a
        // pickup window the turnaround refuses first; with none, this does.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delivery_slot_id:');

        app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'delivery_date' => self::TODAY,
            'delivery_slot_id' => $this->morning->id,
            'accepts_review_terms' => true,
        ]);
    }

    #[Test]
    public function the_delivery_window_does_not_call_a_closed_delivery_valid(): void
    {
        // 17:30 in Cairo: today's 12:00–15:00 has ended.
        $this->travelTo(Carbon::parse(self::TODAY.' 14:30:00', 'UTC'));

        $answer = app(Turnaround::class)->window(
            $this->catalog['service'], self::TODAY, $this->morning, self::TODAY, $this->noon,
        );

        // The order would be refused for it; the sheet must say so first.
        $this->assertFalse($answer['chosen']['valid']);
        $this->assertSame('closed', $answer['chosen']['reason']);
        $this->assertSame(__('This delivery window has ended or is about to. Please choose a later one.'), $answer['chosen']['message']);
    }

    #[Test]
    public function a_leg_is_due_at_its_windows_end_on_the_business_clock_and_is_not_late(): void
    {
        $this->place(self::TODAY, $this->noon, self::TOMORROW, $this->evening)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();
        $legs = $order->tasks()->get()->keyBy(fn (OrderTask $task) => $task->type->value);

        // 15:00 in Cairo is 12:00 UTC; the handover to the laundry two hours on.
        $this->assertSame(self::TODAY.' 12:00:00', $legs[TaskType::PickupFromCustomer->value]->due_at->toDateTimeString());
        $this->assertSame(self::TODAY.' 14:00:00', $legs[TaskType::DeliverToLaundry->value]->due_at->toDateTimeString());
        $this->assertSame(self::TOMORROW.' 18:00:00', $legs[TaskType::DeliverToCustomer->value]->due_at->toDateTimeString());

        // Booked at 09:26 for 12:00–15:00: nobody is late.
        $this->assertFalse($legs->contains(fn (OrderTask $task) => $task->isLate()));
    }

    #[Test]
    public function a_pickup_cannot_be_rebooked_into_a_closed_window(): void
    {
        $this->place(self::TOMORROW, $this->noon, '2026-10-02', $this->evening)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $driver = $this->driverUser('+201066660001');
        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill(['status' => TaskStatus::Assigned, 'driver_id' => $driver->id])->save();
        app(TaskService::class)->fail($task->fresh(), $driver, TaskFailureReason::CustomerPostponed, 'النهارده');

        Sanctum::actingAs($this->customer);
        $options = collect($this->getJson("/api/v1/orders/{$order->id}/reschedule?date=".self::TODAY, $this->apiHeaders())
            ->assertOk()->json('data.slots'))->keyBy('id');
        $this->assertTrue($options[$this->morning->id]['closed']);
        $this->assertFalse($options[$this->noon->id]['closed']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slot_closed');
        app(RescheduleService::class)->reschedule($order->fresh(), $this->customer, [
            'slot_id' => $this->morning->id,
            'date' => self::TODAY,
        ]);
    }

    #[Test]
    public function the_customers_window_opens_when_the_window_does(): void
    {
        $this->place(self::TODAY, $this->noon, self::TOMORROW, $this->evening)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $eta = app(OrderEta::class)->forOrder($order->fresh(['tasks', 'pickupSlot', 'deliverySlot']));

        // `from` was the leg's due time — the window's end — so the window the
        // app was given opened and closed at the same moment.
        $this->assertSame(isoDate(Carbon::parse(self::TODAY.' 09:00:00', 'UTC')), $eta['window']['from']);
        $this->assertSame(isoDate(Carbon::parse(self::TODAY.' 12:00:00', 'UTC')), $eta['window']['to']);
    }

    // ---------------------------------------------------------------- the data

    #[Test]
    public function the_migration_re_dates_open_legs_and_leaves_finished_ones(): void
    {
        $this->place(self::TODAY, $this->noon, self::TOMORROW, $this->evening)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();
        $legs = $order->tasks()->get()->keyBy(fn (OrderTask $task) => $task->type->value);

        // As the old code wrote them: the window's wall time read as UTC.
        $pickup = $legs[TaskType::PickupFromCustomer->value];
        $pickup->forceFill(['due_at' => self::TODAY.' 15:00:00'])->save();
        $done = $legs[TaskType::DeliverToLaundry->value];
        $done->forceFill(['status' => TaskStatus::Completed, 'completed_at' => now(), 'due_at' => self::TODAY.' 17:00:00'])->save();

        (require database_path('migrations/2026_09_30_120000_recompute_open_leg_deadlines_on_the_business_clock.php'))->up();

        $this->assertSame(self::TODAY.' 12:00:00', $pickup->fresh()->due_at->toDateTimeString());
        $this->assertSame(self::TODAY.' 17:00:00', $done->fresh()->due_at->toDateTimeString());
    }
}
