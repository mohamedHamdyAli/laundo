<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «كام طلب النافذة دي تشيل؟»
 *
 * `time_slots.capacity` was editable in the dashboard, returned by the API, and
 * enforced nowhere: fifty customers could all pick «3–6 مساءً» and nothing said
 * no. A field that operations can set and the system ignores is worse than no
 * field, because somebody sets it and believes it.
 *
 * The rules worth pinning down are the ones that decide what counts: a pickup and
 * a delivery in the same window are two journeys to two doors, a cancelled order
 * gives its place back, and an uncapped window is not the same thing as a full one.
 */
class SlotCapacityTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    private TimeSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('+201055550001');

        $this->slot = TimeSlot::create([
            'start_time' => '15:00:00', 'end_time' => '18:00:00',
            'applies_to' => 'both', 'capacity' => 2, 'sort_order' => 1, 'status' => 'active',
        ]);
    }

    private function tomorrow(): string
    {
        return now()->addDay()->toDateString();
    }

    /** @param array<string, mixed> $extra */
    private function place(array $extra = [], ?User $who = null): Order
    {
        $who ??= $this->customer;
        $address = $this->addressFor($who, $this->geo['zones'][0]);

        return app(OrderService::class)->place($who, array_merge([
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
            'pickup_slot_id' => $this->slot->id,
            'pickup_date' => $this->tomorrow(),
        ], $extra));
    }

    /** @param array<string, mixed> $extra */
    private function placeViaApi(array $extra = [])
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return $this->actingAs($this->customer)->postJson('/api/v1/orders', array_merge([
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
            'pickup_slot_id' => $this->slot->id,
            'pickup_date' => $this->tomorrow(),
        ], $extra));
    }

    // ------------------------------------------------------------ the counting

    #[Test]
    public function a_window_fills_up(): void
    {
        $this->place();
        $this->place();

        $this->placeViaApi()->assertStatus(422)->assertJsonPath('errors.pickup_slot_id.0',
            'This window is fully booked. Please choose another one.');

        $this->assertSame(2, Order::whereDate('pickup_date', $this->tomorrow())->count());
    }

    #[Test]
    public function the_same_window_on_another_day_is_untouched(): void
    {
        $this->place();
        $this->place();

        // Capacity is per window per day. A busy Tuesday says nothing about
        // Wednesday.
        $this->placeViaApi(['pickup_date' => now()->addDays(2)->toDateString()])->assertSuccessful();
    }

    #[Test]
    public function a_delivery_takes_a_place_from_the_same_window(): void
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        // A pickup and a delivery in one window are two journeys to two doors.
        // Counting orders instead of visits would let the day carry twice the
        // traffic it was configured for.
        $this->place();
        $this->place([
            'pickup_slot_id' => null, 'pickup_date' => null,
            'delivery_slot_id' => $this->slot->id, 'delivery_date' => $this->tomorrow(),
            'delivery_address_id' => $address->id,
        ]);

        $this->placeViaApi()->assertStatus(422);
    }

    #[Test]
    public function a_cancelled_order_gives_its_place_back(): void
    {
        $first = $this->place();
        $this->place();

        $first->update(['status' => OrderStatus::Cancelled->value]);

        // One abandoned booking must not block a window for the rest of the day.
        $this->placeViaApi()->assertSuccessful();
    }

    #[Test]
    public function an_uncapped_window_never_fills(): void
    {
        $this->slot->update(['capacity' => null]);

        for ($i = 0; $i < 5; $i++) {
            $this->place();
        }

        $this->placeViaApi()->assertSuccessful();
    }

    #[Test]
    public function an_order_with_no_date_consumes_nothing(): void
    {
        // Scheduling is optional in this API, and an order with no date cannot
        // consume a dated window.
        $this->place(['pickup_slot_id' => null, 'pickup_date' => null]);
        $this->place(['pickup_slot_id' => null, 'pickup_date' => null]);

        $this->placeViaApi()->assertSuccessful();
    }

    // ------------------------------------------------------------- the read side

    #[Test]
    public function the_app_can_see_what_is_left_before_it_submits(): void
    {
        $this->place();

        $slots = $this->getJson('/api/v1/time-slots?date='.$this->tomorrow())
            ->assertOk()->json('data');

        $row = collect($slots)->firstWhere('id', $this->slot->id);

        // Refusing at submit, after the customer filled in the whole wizard, is
        // the wrong place to find out.
        $this->assertSame(1, $row['remaining']);
        $this->assertFalse($row['is_full']);
    }

    #[Test]
    public function a_full_window_says_so(): void
    {
        $this->place();
        $this->place();

        $row = collect($this->getJson('/api/v1/time-slots?date='.$this->tomorrow())->json('data'))
            ->firstWhere('id', $this->slot->id);

        $this->assertSame(0, $row['remaining']);
        $this->assertTrue($row['is_full']);
    }

    #[Test]
    public function an_uncapped_window_reports_null_not_zero(): void
    {
        $this->slot->update(['capacity' => null]);

        $row = collect($this->getJson('/api/v1/time-slots?date='.$this->tomorrow())->json('data'))
            ->firstWhere('id', $this->slot->id);

        // «as many as you like» and «choose another window» are different
        // answers, and the app draws them differently.
        $this->assertNull($row['remaining']);
        $this->assertFalse($row['is_full']);
    }

    #[Test]
    public function without_a_date_the_list_carries_no_counts(): void
    {
        $row = collect($this->getJson('/api/v1/time-slots')->assertOk()->json('data'))
            ->firstWhere('id', $this->slot->id);

        // Capacity is per day; a bare list has nothing to count against, and a
        // zero there would read as "full".
        $this->assertArrayNotHasKey('remaining', $row);
    }

    #[Test]
    public function a_bad_date_is_refused_rather_than_ignored(): void
    {
        $this->getJson('/api/v1/time-slots?date=not-a-date')->assertStatus(422);
    }

    // -------------------------------------------------------------- other doors

    #[Test]
    public function rescheduling_cannot_slip_into_a_full_window(): void
    {
        $order = $this->place(['pickup_slot_id' => null, 'pickup_date' => null]);
        $this->place();
        $this->place();

        // Postpone, then rebook into the full window — the back door.
        $task = $order->tasks()->orderBy('sequence')->firstOrFail();
        $task->forceFill([
            'status' => 'failed',
            'failure_reason' => 'customer_postponed',
        ])->save();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/orders/'.$order->id.'/reschedule', [
                'slot_id' => $this->slot->id,
                'date' => $this->tomorrow(),
            ])
            ->assertStatus(400);
    }

    #[Test]
    public function operations_can_watch_a_window_fill(): void
    {
        $this->place();

        $response = $this->actingAs($this->superAdmin())
            ->get('/admin/time-slot')
            ->assertOk();

        // A capacity nobody can watch being used is a number somebody sets once
        // and never revisits.
        $usage = $response->viewData('usage');

        $this->assertSame(0, $usage[$this->slot->id]['today']);
        $this->assertSame(1, $usage[$this->slot->id]['tomorrow']);
        $response->assertSee('1 / 2');
    }

    #[Test]
    public function the_dashboard_marks_a_full_window(): void
    {
        $this->place();
        $this->place();

        $this->actingAs($this->superAdmin())
            ->get('/admin/time-slot')
            ->assertOk()
            ->assertSee('2 / 2')
            ->assertSee(__('Full'));
    }

    #[Test]
    public function a_confirmed_repeat_is_let_through(): void
    {
        $this->place();
        $this->place();

        // The customer was asked «محتاج تغسل النهاردة؟» and said yes, and that
        // screen has no slot picker to send them back to. Refusing turns away the
        // most loyal customer there is over a number they never saw, so the
        // overbook is the platform's to absorb.
        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
            'pickup_slot_id' => $this->slot->id,
            'pickup_date' => $this->tomorrow(),
        ], enforceSlotCapacity: false);

        $this->assertNotNull($order->id);
    }
}
