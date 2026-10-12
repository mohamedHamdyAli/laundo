<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «خريطة السواقين» — every driver, where they are, and what they carry.
 *
 * What is pinned here: who may open it (never a laundry, whatever it is
 * granted), what each driver's status and signal mean, that the waiting pins are
 * the dispatch board's rows and only for whoever may see the board, that «who
 * can take it» offers exactly the drivers the assignment would accept — nearest
 * first — and that asking every few seconds costs the same number of queries
 * however many drivers there are.
 */
class DriverMapTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('+201055550001');
        config(['tracking.fresh_for_seconds' => 120]);
    }

    // ------------------------------------------------------------ fixtures

    private function order(float $lat = 30.0500, float $lng = 31.2100): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0], $lat, $lng);

        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    /**
     * @param  array<int, int>  $zones
     */
    private function driver(string $name, bool $available = true, bool $active = true, array $zones = []): Driver
    {
        $driver = $this->driverUser('+2010444'.str_pad((string) ++$this->phone, 5, '0', STR_PAD_LEFT), $available, $active, $zones);
        $driver->forceFill(['name' => $name])->save();

        return $driver->fresh(['profile', 'zones']);
    }

    private function at(Driver $driver, float $lat, float $lng, int $secondsAgo = 5): void
    {
        $driver->profile->forceFill([
            'last_lat' => $lat,
            'last_lng' => $lng,
            'located_at' => now()->subSeconds($secondsAgo),
        ])->save();
    }

    private function give(Order $order, TaskType $type, Driver $driver, TaskStatus $status = TaskStatus::Started): void
    {
        $order->tasks()->where('type', $type->value)->firstOrFail()->forceFill([
            'driver_id' => $driver->id,
            'status' => $status->value,
            'assigned_at' => now()->subMinutes(10),
            'started_at' => $status === TaskStatus::Started ? now()->subMinutes(5) : null,
        ])->save();
    }

    /**
     * Placement dispatches each leg as it creates it; a waiting pin is about
     * legs nobody has, so they go back in the queue.
     */
    private function waitingOrder(float $lat = 30.0500, float $lng = 31.2100): Order
    {
        $order = $this->order($lat, $lng);
        $order->tasks()->update(['driver_id' => null, 'assigned_at' => null, 'status' => 'pending']);

        return $order->fresh();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function supervisor(array $permissions): User
    {
        $role = Role::firstOrCreate(
            ['slug' => 'driver_supervisor'],
            ['name' => 'Driver Supervisor', 'type' => 'dashboard']
        );
        $this->grant('driver_supervisor', $permissions);

        return User::firstOrCreate(['phone' => '+201077778888'], [
            'name' => 'Supervisor', 'email' => 'supervisor@test.local', 'password' => 'password',
            'status' => 'active', 'role_id' => $role->id,
        ])->fresh();
    }

    /** @return array<string, mixed> */
    private function snapshot(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->superAdmin())
            ->getJson(route('admin.driver_map.data'))
            ->assertOk()
            ->json();
    }

    /** @return array<string, mixed> */
    private function row(array $snapshot, Driver $driver): array
    {
        $row = collect($snapshot['drivers'])->firstWhere('id', $driver->id);
        $this->assertNotNull($row, "{$driver->name} is not on the map");

        return $row;
    }

    // ---------------------------------------------------------------- gate

    #[Test]
    public function the_map_needs_driver_view(): void
    {
        $nobody = $this->supervisor(['order.view']);

        $this->actingAs($nobody)->get(route('admin.driver_map.index'))->assertForbidden();
        $this->actingAs($nobody)->getJson(route('admin.driver_map.data'))->assertForbidden();
    }

    #[Test]
    public function the_supervisor_opens_it_and_the_sidebar_offers_it(): void
    {
        $supervisor = $this->supervisor(['driver.view']);

        $this->actingAs($supervisor)->get(route('admin.driver_map.index'))
            ->assertOk()
            ->assertSee(route('admin.driver_map.index'), false)
            ->assertSee(__('Drivers map'));

        $this->actingAs($supervisor)->getJson(route('admin.driver_map.data'))->assertOk();
    }

    #[Test]
    public function a_laundry_is_refused_even_when_granted_the_permission(): void
    {
        // Where every driver is standing is the platform's, not a tenant's —
        // and a permission can be granted by mistake. The service says no on
        // its own.
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->grant('laundry_owner', ['driver.view', 'order_task.view', 'order_task.update']);
        $owner = $tenant['owner']->fresh();
        $order = $this->waitingOrder();
        $task = $order->tasks()->orderBy('sequence')->first();

        $this->actingAs($owner)->get(route('admin.driver_map.index'))->assertForbidden();
        $this->actingAs($owner)->getJson(route('admin.driver_map.data'))->assertForbidden();
        $this->actingAs($owner)->getJson(route('admin.driver_map.candidates', $task->id))->assertForbidden();
    }

    // -------------------------------------------------------- the drivers

    #[Test]
    public function every_driver_is_on_it_with_their_status(): void
    {
        $order = $this->order();
        $busy = $this->driver('Busy');
        $this->give($order, TaskType::PickupFromCustomer, $busy);
        $free = $this->driver('Free');
        $off = $this->driver('Off', available: false);
        $suspended = $this->driver('Suspended', active: false);

        $map = $this->snapshot();

        $this->assertSame('on_job', $this->row($map, $busy)['status']);
        $this->assertSame('available', $this->row($map, $free)['status']);
        $this->assertSame('unavailable', $this->row($map, $off)['status']);
        $this->assertSame('inactive', $this->row($map, $suspended)['status']);

        $this->assertSame(4, $map['counts']['all']);
        $this->assertSame(1, $map['counts']['on_job']);
        $this->assertSame(1, $map['counts']['available']);
        $this->assertSame(1, $map['counts']['unavailable']);
        $this->assertSame(1, $map['counts']['inactive']);
    }

    #[Test]
    public function the_signal_says_how_old_the_reading_is_and_a_quiet_driver_on_a_job_is_flagged(): void
    {
        $order = $this->order();
        $other = $this->order();

        $live = $this->driver('Live');
        $this->at($live, 30.06, 31.22, secondsAgo: 10);

        $quietOnJob = $this->driver('Quiet on a job');
        $this->at($quietOnJob, 30.07, 31.23, secondsAgo: 600);
        $this->give($order, TaskType::PickupFromCustomer, $quietOnJob);

        $neverOnJob = $this->driver('Never reported on a job');
        $this->give($other, TaskType::PickupFromCustomer, $neverOnJob);

        $quietIdle = $this->driver('Quiet and free');
        $this->at($quietIdle, 30.08, 31.24, secondsAgo: 600);

        $map = $this->snapshot();

        $row = $this->row($map, $live);
        $this->assertSame('live', $row['signal']);
        $this->assertFalse($row['signal_lost']);
        $this->assertSame(30.06, $row['location']['lat']);

        $this->assertSame('stale', $this->row($map, $quietOnJob)['signal']);
        $this->assertTrue($this->row($map, $quietOnJob)['signal_lost']);

        $this->assertSame('none', $this->row($map, $neverOnJob)['signal']);
        $this->assertNull($this->row($map, $neverOnJob)['location']);
        $this->assertTrue($this->row($map, $neverOnJob)['signal_lost']);

        // A free driver who went quiet is not an alarm: nobody is waiting on
        // them.
        $this->assertSame('stale', $this->row($map, $quietIdle)['signal']);
        $this->assertFalse($this->row($map, $quietIdle)['signal_lost']);

        $this->assertSame(2, $map['counts']['signal_lost']);
    }

    #[Test]
    public function a_leg_handed_out_for_later_is_no_alarm_but_one_due_now_is(): void
    {
        // Legs are given out ahead — both return legs the moment the laundry
        // marks the order ready, a pickup for tomorrow's window. A driver holding
        // one with the app closed overnight has not lost signal.
        $later = $this->driver('Tomorrow');
        $tomorrow = $this->order();
        $this->give($tomorrow, TaskType::PickupFromCustomer, $later, TaskStatus::Assigned);
        $tomorrow->tasks()->where('type', TaskType::PickupFromCustomer->value)->update(['due_at' => now()->addDay()]);
        $this->at($later, 30.05, 31.21, secondsAgo: 3600);

        $soon = $this->driver('Due soon');
        $now = $this->order();
        $this->give($now, TaskType::PickupFromCustomer, $soon, TaskStatus::Assigned);
        $now->tasks()->where('type', TaskType::PickupFromCustomer->value)->update(['due_at' => now()->addMinutes(20)]);
        $this->at($soon, 30.05, 31.21, secondsAgo: 3600);

        $map = $this->snapshot();

        $this->assertSame('on_job', $this->row($map, $later)['status']);
        $this->assertFalse($this->row($map, $later)['signal_lost']);
        $this->assertTrue($this->row($map, $soon)['signal_lost']);
    }

    #[Test]
    public function a_zone_in_a_city_switched_off_is_drawn_as_not_taking_orders(): void
    {
        // `Zone::isServing()` is the one definition: the zone's switch and its
        // city's. The zone alone still says active here.
        Zone::query()->whereKey($this->geo['zones'][0]->id)->update([
            'boundary' => json_encode([[30.0, 31.0], [30.1, 31.0], [30.1, 31.1]]),
            'min_lat' => 30.0, 'max_lat' => 30.1, 'min_lng' => 31.0, 'max_lng' => 31.1,
        ]);
        $this->geo['city']->update(['status' => 'inactive']);

        $html = $this->actingAs($this->superAdmin())->get(route('admin.driver_map.index'))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="driver-map-config">(.*?)</script>#s', $html, $m);
        $config = json_decode($m[1] ?? 'null', true);

        $this->assertIsArray($config);
        $this->assertCount(1, $config['zones']);
        $this->assertFalse($config['zones'][0]['serving']);
    }

    #[Test]
    public function the_card_carries_the_order_and_where_the_leg_is_going(): void
    {
        $order = $this->order(30.0411, 31.2222);
        $driver = $this->driver('Carrier');
        $this->give($order, TaskType::PickupFromCustomer, $driver);
        $order->tasks()->where('type', TaskType::PickupFromCustomer->value)->update(['due_at' => now()->subMinutes(30)]);

        $row = $this->row($this->snapshot(), $driver);
        $leg = $row['legs'][0];

        $this->assertSame($order->code, $leg['order_code']);
        $this->assertSame('pickup_from_customer', $leg['type']);
        $this->assertSame('Customer', $leg['customer']);
        $this->assertSame('Test Street', $leg['destination']);
        $this->assertSame(['lat' => 30.0411, 'lng' => 31.2222], $leg['destination_point']);
        $this->assertTrue($leg['is_late']);
        $this->assertTrue($row['is_late']);
        $this->assertSame(1, $row['orders_in_hand']);
        $this->assertSame(route('admin.order.show', $order->id), $leg['order_url']);
    }

    #[Test]
    public function the_order_link_comes_only_with_order_view(): void
    {
        $order = $this->order();
        $driver = $this->driver('Carrier');
        $this->give($order, TaskType::PickupFromCustomer, $driver);

        $row = $this->row($this->snapshot($this->supervisor(['driver.view'])), $driver);

        $this->assertSame($order->code, $row['legs'][0]['order_code']);
        $this->assertNull($row['legs'][0]['order_url']);
    }

    #[Test]
    public function the_customer_and_their_door_come_only_with_the_dispatch_board(): void
    {
        // A role given the drivers alone sees what each one is doing — not
        // whose name is on the order or where they live. That is the dispatch
        // board's (`order_task.view`), as the waiting pins are.
        $order = $this->order(30.0411, 31.2222);
        $driver = $this->driver('Carrier');
        $this->give($order, TaskType::PickupFromCustomer, $driver);

        $driversOnly = $this->row($this->snapshot($this->supervisor(['driver.view'])), $driver)['legs'][0];
        $this->assertNull($driversOnly['customer']);
        $this->assertNull($driversOnly['destination']);
        $this->assertNull($driversOnly['destination_point']);

        $withBoard = $this->row($this->snapshot($this->supervisor(['driver.view', 'order_task.view'])), $driver)['legs'][0];
        $this->assertSame('Customer', $withBoard['customer']);
        $this->assertSame(['lat' => 30.0411, 'lng' => 31.2222], $withBoard['destination_point']);
    }

    #[Test]
    public function a_finished_leg_is_not_carried(): void
    {
        $order = $this->order();
        $driver = $this->driver('Done');
        $this->give($order, TaskType::PickupFromCustomer, $driver, TaskStatus::Completed);

        $row = $this->row($this->snapshot(), $driver);

        $this->assertSame([], $row['legs']);
        $this->assertSame('available', $row['status']);
    }

    // ------------------------------------------------------- waiting pins

    #[Test]
    public function an_order_nobody_holds_is_one_pin_where_its_first_leg_begins(): void
    {
        $order = $this->waitingOrder(30.0333, 31.2444);

        $waiting = $this->snapshot()['waiting'];

        $this->assertCount(1, $waiting);
        $this->assertSame($order->code, $waiting[0]['order_code']);
        $this->assertSame(4, $waiting[0]['legs_waiting']);
        $this->assertSame(['lat' => 30.0333, 'lng' => 31.2444], $waiting[0]['point']);
        $this->assertSame($this->geo['zones'][0]->id, $waiting[0]['zone_id']);
    }

    #[Test]
    public function a_waiting_delivery_is_filed_under_the_zone_its_drivers_come_from(): void
    {
        // Dispatch matches the delivery leg on the *delivery* address's zone, so
        // that is the area the pin belongs to — filtered to it, the pin and the
        // drivers «who can take it» offers are on the map together.
        $pickup = $this->addressFor($this->customer, $this->geo['zones'][0]);
        $delivery = $this->addressFor($this->customer, $this->geo['zones'][1], 30.0100, 31.2700, 'Work');
        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $pickup->id,
            'delivery_address_id' => $delivery->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
        $order->tasks()->where('type', '!=', TaskType::DeliverToCustomer->value)
            ->update(['status' => 'completed', 'completed_at' => now()]);
        $order->tasks()->where('type', TaskType::DeliverToCustomer->value)
            ->update(['driver_id' => null, 'assigned_at' => null, 'status' => 'pending']);

        $waiting = $this->snapshot()['waiting'];

        $this->assertCount(1, $waiting);
        $this->assertSame($this->geo['zones'][1]->id, $waiting[0]['zone_id']);
    }

    #[Test]
    public function a_failed_leg_is_not_called_late(): void
    {
        // The dispatch board's own rule (`OrderTask::isLate()`): late means
        // still open and past its window. A failed leg waits for a person, not
        // for the clock.
        $order = $this->waitingOrder();
        $order->tasks()->where('type', TaskType::PickupFromCustomer->value)
            ->update(['status' => 'failed', 'due_at' => now()->subHour()]);

        $this->assertFalse($this->snapshot()['waiting'][0]['is_late']);
    }

    #[Test]
    public function the_waiting_pins_are_the_dispatch_boards_and_need_its_permission(): void
    {
        $order = $this->waitingOrder();
        $task = $order->tasks()->orderBy('sequence')->first();
        $supervisor = $this->supervisor(['driver.view']);

        $this->assertSame([], $this->snapshot($supervisor)['waiting']);
        $this->actingAs($supervisor)
            ->getJson(route('admin.driver_map.candidates', $task->id))
            ->assertForbidden();
    }

    #[Test]
    public function who_can_take_it_is_the_eligible_drivers_nearest_first(): void
    {
        $zone = $this->geo['zones'][0];
        $order = $this->waitingOrder(30.0500, 31.2100);
        $task = $order->tasks()->orderBy('sequence')->first();

        $far = $this->driver('Far', zones: [$zone->id]);
        $this->at($far, 30.1500, 31.2100);           // ~11 km
        $near = $this->driver('Near', zones: [$zone->id]);
        $this->at($near, 30.0600, 31.2100);          // ~1 km
        $quiet = $this->driver('Quiet', zones: [$zone->id]);
        $this->at($quiet, 30.0501, 31.2101, 900);    // closest, but not live
        $elsewhere = $this->driver('Elsewhere', zones: [$this->geo['zones'][1]->id]);
        $this->at($elsewhere, 30.0500, 31.2100);     // on the spot, wrong zone
        $off = $this->driver('Off', available: false, zones: [$zone->id]);
        $this->at($off, 30.0500, 31.2100);           // on the spot, switched off

        $answer = $this->actingAs($this->superAdmin())
            ->getJson(route('admin.driver_map.candidates', $task->id))
            ->assertOk()
            ->json();

        $this->assertSame(['Near', 'Far', 'Quiet'], array_column($answer['candidates'], 'name'));
        $this->assertEqualsWithDelta(1.1, $answer['candidates'][0]['distance_km'], 0.2);
        $this->assertNull($answer['candidates'][2]['distance_km']);
        $this->assertSame(route('admin.order.tasks.assign', $task->id), $answer['assign_url']);
        $this->assertNull($answer['nobody']);
    }

    #[Test]
    public function nobody_eligible_says_why(): void
    {
        $order = $this->waitingOrder();
        $task = $order->tasks()->orderBy('sequence')->first();

        $answer = $this->actingAs($this->superAdmin())
            ->getJson(route('admin.driver_map.candidates', $task->id))
            ->assertOk()
            ->json();

        $this->assertSame([], $answer['candidates']);
        $this->assertSame(__('There are no drivers on the system yet'), $answer['nobody']);
    }

    #[Test]
    public function a_leg_somebody_took_meanwhile_is_not_offered(): void
    {
        $order = $this->waitingOrder();
        $task = $order->tasks()->orderBy('sequence')->first();
        $this->give($order, TaskType::PickupFromCustomer, $this->driver('Quick'), TaskStatus::Assigned);

        $this->actingAs($this->superAdmin())
            ->getJson(route('admin.driver_map.candidates', $task->id))
            ->assertNotFound();
    }

    // ------------------------------------------------------------- the cost

    #[Test]
    public function asking_again_costs_the_same_however_many_drivers_there_are(): void
    {
        $admin = $this->superAdmin();
        $count = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson(route('admin.driver_map.data'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $order = $this->order();
        $first = $this->driver('One', zones: [$this->geo['zones'][0]->id]);
        // Located, like the five below: the first reading formatted in a
        // request looks the locales up once, and that one-off is not what is
        // being measured.
        $this->at($first, 30.05, 31.2);
        $this->give($order, TaskType::PickupFromCustomer, $first);
        $this->waitingOrder();
        $count(); // warm whatever is cached per request

        $few = $count();

        foreach (range(1, 5) as $i) {
            $driver = $this->driver("More {$i}", zones: [$this->geo['zones'][1]->id]);
            $this->at($driver, 30.0 + $i / 100, 31.2);
            $this->give($this->order(), TaskType::PickupFromCustomer, $driver);
        }

        $this->assertSame($few, $count());
    }

    // ---------------------------------------------------------- the page

    #[Test]
    public function names_reach_the_page_as_data_never_as_markup(): void
    {
        Zone::query()->whereKey($this->geo['zones'][0]->id)->update([
            'name' => json_encode(['en' => '</script><script>alert(1)</script>'], JSON_UNESCAPED_UNICODE),
            'boundary' => json_encode([[30.0, 31.0], [30.1, 31.0], [30.1, 31.1]]),
            'min_lat' => 30.0, 'max_lat' => 30.1, 'min_lng' => 31.0, 'max_lng' => 31.1,
        ]);

        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.driver_map.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('id="driver-map-config"', $html);
    }
}
