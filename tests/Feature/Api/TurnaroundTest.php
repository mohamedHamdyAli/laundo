<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\RescheduleService;
use App\Modules\Order\Services\TaskService;
use App\Modules\Order\Services\Turnaround;
use App\Modules\Service\Models\Service;
use App\Modules\Setting\Models\Setting;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * «خدمة مدتها من يومين لـ 4 والعميل بيختار الاستلام والتسليم في نفس اليوم» —
 * the delivery leaves the service its time.
 *
 * The owner's rules: the middle of the range (2–4 days is 3, 24–48 hours is 36);
 * hours exact from the end of the pickup window to the start of the delivery
 * window; days whole days; a postponed pickup pushes the delivery with it.
 */
class TurnaroundTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    private User $customer;

    private TimeSlot $morning;

    private TimeSlot $evening;

    /** Monday, two days out — the pickup day throughout. */
    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['quoted']->id);

        // «24–48 ساعة» and «2–4 أيام».
        $this->catalog['service']->update(['duration_min' => 24, 'duration_max' => 48, 'duration_unit' => 'hour']);
        $this->catalog['quoted']->update(['duration_min' => 2, 'duration_max' => 4, 'duration_unit' => 'day']);

        $this->morning = TimeSlot::create(['start_time' => '09:00:00', 'end_time' => '12:00:00', 'applies_to' => 'both', 'sort_order' => 1, 'status' => 'active']);
        $this->evening = TimeSlot::create(['start_time' => '18:00:00', 'end_time' => '21:00:00', 'applies_to' => 'both', 'sort_order' => 2, 'status' => 'active']);

        $this->monday = now()->addWeek()->startOfWeek(Carbon::MONDAY);
        $this->customer = $this->customer();
    }

    private function day(int $offset): string
    {
        return $this->monday->copy()->addDays($offset)->toDateString();
    }

    private function place(Service $service, string $pickupDate, TimeSlot $pickup, string $deliveryDate, TimeSlot $delivery)
    {
        Sanctum::actingAs($this->customer);

        return $this->postJson('/api/v1/orders', [
            'service_id' => $service->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => $service->isPerItem() ? [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]] : [],
            'pickup_date' => $pickupDate,
            'pickup_slot_id' => $pickup->id,
            'delivery_date' => $deliveryDate,
            'delivery_slot_id' => $delivery->id,
            'accepts_review_terms' => true,
        ], $this->apiHeaders());
    }

    /** @return array<string, mixed> */
    private function window(Service $service, array $extra = []): array
    {
        return $this->getJson('/api/v1/delivery-window?'.http_build_query([
            'service_id' => $service->id,
            'pickup_date' => $this->day(0),
            'pickup_slot_id' => $this->morning->id,
        ] + $extra), $this->apiHeaders())->assertOk()->json('data');
    }

    #[Test]
    public function the_delivery_window_gives_the_range_from_the_pickup(): void
    {
        $data = $this->window($this->catalog['quoted']);

        // Three days: from Thursday, and fourteen days on by default.
        $this->assertSame(['value' => 3, 'unit' => 'day', 'label' => __(':count days', ['count' => 3])], $data['turnaround']);
        $this->assertSame(['date' => $this->day(3), 'time' => null], $data['earliest']);
        $this->assertSame($this->day(17), $data['latest']['date']);
        $this->assertNull($data['chosen']);

        // And a delivery already picked for it: Thursday's first window.
        $this->assertSame($this->day(3), $data['suggestion']['date']);
        $this->assertSame($this->morning->id, $data['suggestion']['time_slot']['id']);
    }

    #[Test]
    public function a_service_in_hours_gives_the_hour_too(): void
    {
        $data = $this->window($this->catalog['service']);

        // Monday 12:00 + 36 hours.
        $this->assertSame(['date' => $this->day(2), 'time' => '00:00'], $data['earliest']);
    }

    #[Test]
    public function a_delivery_too_early_is_explained_with_windows_to_pick_from(): void
    {
        $data = $this->window($this->catalog['quoted'], [
            'delivery_date' => $this->day(1),
            'delivery_slot_id' => $this->evening->id,
        ]);

        $this->assertFalse($data['chosen']['valid']);
        $this->assertSame('too_early', $data['chosen']['reason']);
        $this->assertStringContainsString($this->day(3), $data['chosen']['message']);

        // The chosen day is out of range, so the windows are the suggestion's day
        // — the bottom sheet opens on something bookable.
        $this->assertSame($this->day(3), $data['windows_date']);
        $this->assertTrue(collect($data['windows'])->every(fn ($w) => $w['available']));
    }

    #[Test]
    public function the_windows_follow_the_day_the_customer_is_looking_at(): void
    {
        // A 36-hour service: Wednesday is in range, so its windows are listed.
        $data = $this->window($this->catalog['service'], ['delivery_date' => $this->day(2)]);

        $this->assertTrue($data['chosen']['valid']);
        $this->assertSame($this->day(2), $data['windows_date']);

        // On Tuesday — before the 36 hours are up — both windows say why not.
        $tuesday = collect($this->window($this->catalog['service'], ['delivery_date' => $this->day(1)])['windows']);
        $this->assertSame($this->day(2), $this->window($this->catalog['service'], ['delivery_date' => $this->day(1)])['windows_date']);
        $this->assertNotEmpty($tuesday);
    }

    #[Test]
    public function a_delivery_past_the_booking_window_is_refused_everywhere(): void
    {
        Setting::updateOrCreate(['key' => 'Delivery_Window_Days'], ['value' => '5']);
        Cache::flush();

        // Three days to Thursday, five more to the Tuesday after.
        $data = $this->window($this->catalog['quoted'], ['delivery_date' => $this->day(9)]);
        $this->assertSame($this->day(8), $data['latest']['date']);
        $this->assertSame('too_late', $data['chosen']['reason']);
        $this->assertSame(__('Delivery can be booked up to :date.', ['date' => $this->day(8)]), $data['chosen']['message']);

        // …and the order is refused with the same words.
        $this->place($this->catalog['quoted'], $this->day(0), $this->morning, $this->day(9), $this->morning)
            ->assertStatus(422)
            ->assertJsonPath('errors.delivery_date.0', __('Delivery can be booked up to :date.', ['date' => $this->day(8)]));

        // The last day itself is fine.
        $this->place($this->catalog['quoted'], $this->day(0), $this->morning, $this->day(8), $this->evening)->assertCreated();
    }

    #[Test]
    public function the_suggestion_skips_a_window_that_is_full(): void
    {
        $this->morning->update(['capacity' => 0]);

        $data = $this->window($this->catalog['quoted']);

        // Thursday morning cannot be booked, so Thursday evening is offered.
        $this->assertSame($this->day(3), $data['suggestion']['date']);
        $this->assertSame($this->evening->id, $data['suggestion']['time_slot']['id']);
    }

    #[Test]
    public function the_booking_window_is_set_from_the_settings_screen(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.generalSetting.updateGeneralSetting'), [
            'Delivery_Window_Days' => '21',
        ])->assertRedirect();
        Cache::flush();

        $this->assertSame(21, app(Turnaround::class)->windowDays());

        $this->actingAs($this->superAdmin())->put(route('admin.generalSetting.updateGeneralSetting'), [
            'Delivery_Window_Days' => '0',
        ])->assertSessionHasErrors('Delivery_Window_Days');
    }

    #[Test]
    public function a_postponed_delivery_on_an_old_order_can_still_be_rebooked(): void
    {
        Setting::updateOrCreate(['key' => 'Delivery_Window_Days'], ['value' => '2']);
        Cache::flush();

        // Picked up Monday, a 36-hour service: the window for a *new* booking
        // closes on Friday. The laundry took its time and the delivery was
        // postponed — rebooking it for the week after must still be possible.
        $this->place($this->catalog['service'], $this->day(0), $this->morning, $this->day(2), $this->morning)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();
        OrderTask::where('order_id', $order->id)->where('type', TaskType::DeliverToCustomer->value)->firstOrFail()
            ->forceFill(['status' => TaskStatus::Failed, 'failure_reason' => TaskFailureReason::CustomerPostponed])->save();

        Sanctum::actingAs($this->customer);
        $options = collect($this->getJson("/api/v1/orders/{$order->id}/reschedule?date=".$this->day(10), $this->apiHeaders())->json('data.slots'));
        $this->assertTrue($options->every(fn ($slot) => $slot['too_late'] === null && $slot['too_early'] === false));

        app(RescheduleService::class)->reschedule($order->fresh(), $this->customer, [
            'slot_id' => $this->morning->id,
            'date' => $this->day(10),
        ]);

        $this->assertSame($this->day(10), $order->fresh()->delivery_date->toDateString());
    }

    #[Test]
    public function a_delivery_booked_far_out_is_not_pulled_in_by_a_postponed_pickup(): void
    {
        $this->place($this->catalog['service'], $this->day(0), $this->morning, $this->day(10), $this->morning)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $driver = $this->driverUser('+201066660003');
        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill(['status' => TaskStatus::Assigned, 'driver_id' => $driver->id])->save();
        app(TaskService::class)->fail($task->fresh(), $driver, TaskFailureReason::CustomerPostponed, 'بكرة');

        // The window is lowered after the booking; the delivery still fits the
        // turnaround, so it stays where the customer put it.
        Setting::updateOrCreate(['key' => 'Delivery_Window_Days'], ['value' => '2']);
        Cache::flush();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/reschedule", [
            'slot_id' => $this->evening->id,
            'date' => $this->day(1),
        ], $this->apiHeaders())->assertOk()->assertJsonPath('data.delivery_moved', null);

        $this->assertSame($this->day(10), $order->fresh()->delivery_date->toDateString());
    }

    #[Test]
    public function the_delivery_window_refuses_a_pickup_only_window_for_the_delivery(): void
    {
        $pickupOnly = TimeSlot::create(['start_time' => '12:00:00', 'end_time' => '15:00:00', 'applies_to' => 'pickup', 'sort_order' => 3, 'status' => 'active']);

        $this->getJson('/api/v1/delivery-window?'.http_build_query([
            'service_id' => $this->catalog['service']->id,
            'pickup_date' => $this->day(0),
            'delivery_date' => $this->day(3),
            'delivery_slot_id' => $pickupOnly->id,
        ]), $this->apiHeaders())->assertStatus(422)->assertJsonValidationErrors('delivery_slot_id');
    }

    #[Test]
    public function with_no_pickup_window_the_earliest_is_a_round_hour(): void
    {
        $data = $this->getJson('/api/v1/delivery-window?'.http_build_query([
            'service_id' => $this->catalog['service']->id,
            'pickup_date' => $this->day(0),
        ]), $this->apiHeaders())->json('data');

        // Monday is taken to end at midnight; 36 hours on is Wednesday 12:00.
        $this->assertSame(['date' => $this->day(2), 'time' => '12:00'], $data['earliest']);
    }

    #[Test]
    public function placing_an_order_directly_is_held_to_the_rule_too(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delivery_date:');

        app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['quoted']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [],
            'pickup_date' => $this->day(0),
            'pickup_slot_id' => $this->morning->id,
            'delivery_date' => $this->day(0),
            'delivery_slot_id' => $this->evening->id,
            'accepts_review_terms' => true,
        ]);
    }

    #[Test]
    public function released_return_legs_are_announced_when_drivers_are_assigned_by_hand(): void
    {
        Setting::updateOrCreate(['key' => 'Auto_Assign_Driver'], ['value' => '0']);
        Cache::flush();
        $admin = $this->superAdmin();

        $order = $this->postponedPickup();
        $driver = $this->driverUser('+201066660004');
        OrderTask::where('order_id', $order->id)->where('type', TaskType::DeliverToCustomer->value)->firstOrFail()
            ->forceFill(['status' => TaskStatus::Assigned, 'driver_id' => $driver->id])->save();
        $before = $admin->fresh()->notifications()->count();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/reschedule", [
            'slot_id' => $this->evening->id,
            'date' => $this->day(1),
        ], $this->apiHeaders())->assertOk();

        // Handed back by the move, and — nobody assigns automatically — said so.
        $this->assertGreaterThan($before, $admin->fresh()->notifications()->count());
    }

    #[Test]
    public function the_turnaround_is_the_middle_of_the_range(): void
    {
        $turnaround = app(Turnaround::class);
        $make = fn (?int $min, ?int $max, string $unit) => new Service(['duration_min' => $min, 'duration_max' => $max, 'duration_unit' => $unit]);

        $this->assertSame(['value' => 36, 'unit' => 'hour'], $turnaround->after($make(24, 48, 'hour')));
        $this->assertSame(['value' => 3, 'unit' => 'day'], $turnaround->after($make(2, 4, 'day')));
        $this->assertSame(['value' => 24, 'unit' => 'hour'], $turnaround->after($make(24, 24, 'hour')));
        // A day range with a half in the middle rounds up to whole days.
        $this->assertSame(['value' => 2, 'unit' => 'day'], $turnaround->after($make(1, 2, 'day')));
        // And an hour range with a half keeps it.
        $this->assertSame(['value' => 1.5, 'unit' => 'hour'], $turnaround->after($make(1, 2, 'hour')));
        $this->assertNull($turnaround->after($make(null, null, 'hour')));
    }

    #[Test]
    public function a_four_day_service_cannot_come_back_the_same_day(): void
    {
        // The bug as reported: collected and returned on Monday.
        $this->place($this->catalog['quoted'], $this->day(0), $this->morning, $this->day(0), $this->evening)
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_date');

        // Three days is Thursday; Wednesday evening is still too early…
        $refused = $this->place($this->catalog['quoted'], $this->day(0), $this->morning, $this->day(2), $this->evening);
        $refused->assertStatus(422);
        $this->assertStringContainsString($this->day(3), json_encode($refused->json('errors.delivery_date'), JSON_UNESCAPED_UNICODE));

        // …and Thursday, in any window, is fine.
        $this->place($this->catalog['quoted'], $this->day(0), $this->morning, $this->day(3), $this->morning)->assertCreated();
    }

    #[Test]
    public function a_service_in_hours_is_counted_to_the_hour(): void
    {
        // Picked up Monday 09–12; 36 hours on is Wednesday 00:00.
        $this->place($this->catalog['service'], $this->day(0), $this->morning, $this->day(1), $this->evening)
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_date');

        $this->place($this->catalog['service'], $this->day(0), $this->morning, $this->day(2), $this->morning)->assertCreated();
    }

    #[Test]
    public function a_service_with_no_turnaround_keeps_the_old_rule(): void
    {
        $this->catalog['service']->update(['duration_min' => null, 'duration_max' => null]);

        $this->place($this->catalog['service'], $this->day(0), $this->morning, $this->day(0), $this->evening)->assertCreated();
    }

    #[Test]
    public function the_windows_screen_marks_what_is_too_early(): void
    {
        Sanctum::actingAs($this->customer);

        $windows = fn (string $date) => collect($this->getJson('/api/v1/time-slots?'.http_build_query([
            'type' => 'delivery',
            'date' => $date,
            'service_id' => $this->catalog['service']->id,
            'pickup_date' => $this->day(0),
            'pickup_slot_id' => $this->morning->id,
        ]), $this->apiHeaders())->assertOk()->json('data'))->pluck('too_early', 'id');

        $this->assertTrue($windows($this->day(1))[$this->evening->id]);
        $this->assertFalse($windows($this->day(2))[$this->morning->id]);

        // Without the pickup there is nothing to measure against.
        $plain = collect($this->getJson('/api/v1/time-slots?type=delivery&date='.$this->day(1), $this->apiHeaders())->json('data'));
        $this->assertNull($plain->first()['too_early']);
    }

    #[Test]
    public function the_catalogue_says_how_long_after_the_pickup(): void
    {
        $services = collect($this->getJson('/api/v1/services', $this->apiHeaders())->assertOk()->json('data'))->keyBy('id');

        // In the service's own unit: three days is «from the third day», not
        // seventy-two hours from the end of the pickup window.
        $this->assertSame(['value' => 36, 'unit' => 'hour'], $services[$this->catalog['service']->id]['delivery_after']);
        $this->assertSame(['value' => 3, 'unit' => 'day'], $services[$this->catalog['quoted']->id]['delivery_after']);
    }

    private function postponedPickup(): Order
    {
        $this->place($this->catalog['service'], $this->day(0), $this->morning, $this->day(2), $this->morning)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $driver = $this->driverUser('+201066660001');
        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill(['status' => TaskStatus::Assigned, 'driver_id' => $driver->id])->save();

        app(TaskService::class)->fail($task->fresh(), $driver, TaskFailureReason::CustomerPostponed, 'بكرة');

        return $order->fresh();
    }

    #[Test]
    public function a_pickup_postponed_past_the_delivery_pushes_the_delivery_with_it(): void
    {
        $order = $this->postponedPickup();
        Sanctum::actingAs($this->customer);

        // The pickup moves to Tuesday evening, ending 21:00; 36 hours on is
        // Thursday 09:00 — Wednesday's delivery no longer leaves the time.
        $response = $this->postJson("/api/v1/orders/{$order->id}/reschedule", [
            'slot_id' => $this->evening->id,
            'date' => $this->day(1),
        ], $this->apiHeaders())->assertOk();

        $order->refresh();
        $this->assertSame($this->day(3), $order->delivery_date->toDateString());
        $this->assertSame($this->morning->id, $order->delivery_slot_id);

        // The customer is told, in the response and in the message.
        $this->assertSame($this->day(3), $response->json('data.delivery_moved.date'));
        $this->assertStringContainsString($this->day(3), (string) $response->json('msg'));

        // And the leg that brings the pieces back is due by the new window.
        $back = OrderTask::where('order_id', $order->id)->where('type', TaskType::DeliverToCustomer->value)->firstOrFail();
        // Read on the business's clock: the window's «12:00» is Cairo's (the
        // country the request ran in), and `due_at` is stored UTC.
        $this->assertSame($this->day(3).' 12:00', $back->due_at->timezone(displayTimezone())->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_pickup_postponed_that_still_fits_leaves_the_delivery_alone(): void
    {
        $order = $this->postponedPickup();
        Sanctum::actingAs($this->customer);

        // Monday evening: 21:00 + 36h is Wednesday 09:00 — the booked window.
        $response = $this->postJson("/api/v1/orders/{$order->id}/reschedule", [
            'slot_id' => $this->evening->id,
            'date' => $this->day(0),
        ], $this->apiHeaders())->assertOk();

        $this->assertSame($this->day(2), $order->fresh()->delivery_date->toDateString());
        $this->assertNull($response->json('data.delivery_moved'));
    }

    #[Test]
    public function a_delivery_day_with_no_window_chosen_is_judged_by_the_day(): void
    {
        // A 24-hour service picked up Monday 09–12: Tuesday from 12:00 fits, so
        // a Tuesday delivery with the window left for later is not refused.
        $this->catalog['service']->update(['duration_min' => 24, 'duration_max' => 24]);
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'pickup_date' => $this->day(0),
            'pickup_slot_id' => $this->morning->id,
            'delivery_date' => $this->day(1),
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();
    }

    #[Test]
    public function a_driver_holding_a_return_leg_is_released_when_the_delivery_moves(): void
    {
        $order = $this->postponedPickup();
        $driver = $this->driverUser('+201066660002');
        $back = OrderTask::where('order_id', $order->id)->where('type', TaskType::DeliverToCustomer->value)->firstOrFail();
        $back->forceFill(['status' => TaskStatus::Assigned, 'driver_id' => $driver->id])->save();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/orders/{$order->id}/reschedule", [
            'slot_id' => $this->evening->id,
            'date' => $this->day(1),
        ], $this->apiHeaders())->assertOk();

        // Planned for Wednesday; the delivery is now Thursday. Handed back, to
        // be offered again for the new day — not left with a driver who was
        // never told.
        $this->assertNotSame($driver->id, $back->fresh()->driver_id);
    }

    #[Test]
    public function the_partner_leg_is_due_by_the_new_window_too(): void
    {
        $order = $this->postponedPickup();
        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/reschedule", [
            'slot_id' => $this->evening->id,
            'date' => $this->day(1),
        ], $this->apiHeaders())->assertOk();

        // On the business's clock, as the windows are.
        $due = fn (TaskType $type) => OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail()
            ->due_at->timezone(displayTimezone())->format('Y-m-d H:i');

        // The pickup at the end of its new window, the hand-over two hours on.
        $this->assertSame($this->day(1).' 21:00', $due(TaskType::PickupFromCustomer));
        $this->assertSame($this->day(1).' 23:00', $due(TaskType::DeliverToLaundry));
    }

    #[Test]
    public function with_no_turnaround_a_delivery_still_cannot_come_before_the_pickup(): void
    {
        $this->catalog['service']->update(['duration_min' => null, 'duration_max' => null]);
        $this->place($this->catalog['service'], $this->day(1), $this->morning, $this->day(1), $this->evening)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        OrderTask::where('order_id', $order->id)->where('type', TaskType::DeliverToCustomer->value)->firstOrFail()
            ->forceFill(['status' => TaskStatus::Failed, 'failure_reason' => TaskFailureReason::CustomerPostponed])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delivery_too_early');

        // Rebooked for the day before it is collected.
        app(RescheduleService::class)->reschedule($order->fresh(), $this->customer, [
            'slot_id' => $this->morning->id,
            'date' => $this->day(0),
        ]);
    }

    #[Test]
    public function a_delivery_rebooked_too_soon_after_the_pickup_is_refused(): void
    {
        $this->place($this->catalog['service'], $this->day(0), $this->morning, $this->day(2), $this->morning)->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        // The customer postponed the delivery leg.
        OrderTask::where('order_id', $order->id)->where('type', TaskType::DeliverToCustomer->value)->firstOrFail()
            ->forceFill(['status' => TaskStatus::Failed, 'failure_reason' => TaskFailureReason::CustomerPostponed])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delivery_too_early');

        app(RescheduleService::class)->reschedule($order->fresh(), $this->customer, [
            'slot_id' => $this->evening->id,
            'date' => $this->day(1),
        ]);
    }
}
