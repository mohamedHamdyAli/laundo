<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\AutoAssign;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «التعيين التلقائي» — the two switches that stop the platform handing out work
 * by itself, and the bell that says the work is waiting.
 */
class AutoAssignTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $tenant;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        foreach ($this->geo['zones'] as $zone) {
            $zone->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
        }

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
        $this->customer = $this->customer();
    }

    private function switchOff(string $key): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => '0']);
        Cache::flush();
    }

    /** @return array<string, mixed> */
    private function basket(): array
    {
        return [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ];
    }

    private function place(): Order
    {
        return app(OrderService::class)->place($this->customer, $this->basket());
    }

    private function eligibleDriver(): Driver
    {
        return $this->driverUser('+201044440001', zoneIds: [$this->geo['zones'][0]->id]);
    }

    /** @return array<int, array<string, mixed>> */
    private function bell(User $user): array
    {
        return $user->fresh()->notifications->pluck('data')->all();
    }

    #[Test]
    public function both_switches_are_on_until_somebody_turns_them_off(): void
    {
        $switches = app(AutoAssign::class);

        $this->assertTrue($switches->laundries());
        $this->assertTrue($switches->drivers());

        $driver = $this->eligibleDriver();
        $order = $this->place();

        // Exactly as the platform has always run.
        $this->assertSame($this->tenant['laundry']->id, $order->laundry_id);
        $this->assertSame(4, OrderTask::where('order_id', $order->id)->where('driver_id', $driver->id)->count());
    }

    #[Test]
    public function with_laundries_off_an_order_waits_for_a_person_and_the_price_is_still_real(): void
    {
        $priced = app(OrderService::class)->quote($this->customer, $this->basket());

        $this->switchOff(AutoAssign::LAUNDRY);
        $admin = $this->superAdmin();

        $quote = app(OrderService::class)->quote($this->customer, $this->basket());
        $order = $this->place();

        // No laundry promised, none given…
        $this->assertNull($quote['laundry']);
        $this->assertNull($order->laundry_id);
        // …and the fee is the one the nearest laundry would have meant.
        $this->assertSame($priced['delivery_fee'], $quote['delivery_fee']);

        // The people who choose are told, with a link to the order.
        $notice = collect($this->bell($admin))->firstWhere('url', "/admin/order/show/{$order->id}");
        $this->assertNotNull($notice);
        $this->assertSame(__('An order is waiting for a laundry'), $notice['title']);

        // The laundry's owner is not — it cannot assign its own work.
        $this->assertSame([], $this->bell($this->tenant['owner']));
    }

    #[Test]
    public function choosing_the_laundry_by_hand_still_works_with_the_switch_off(): void
    {
        $this->switchOff(AutoAssign::LAUNDRY);
        $order = $this->place();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.order.assign', $order->id), ['laundry_id' => $this->tenant['laundry']->id])
            ->assertRedirect();

        $this->assertSame($this->tenant['laundry']->id, $order->fresh()->laundry_id);
    }

    #[Test]
    public function no_driver_is_sent_for_the_pieces_until_there_is_a_laundry_to_take_them_to(): void
    {
        // Laundries by hand, drivers automatic, an eligible driver waiting.
        $this->switchOff(AutoAssign::LAUNDRY);
        $driver = $this->eligibleDriver();

        $order = $this->place();

        // Nobody is sent to collect a bag with nowhere to go…
        $this->assertSame(4, OrderTask::where('order_id', $order->id)->whereNull('driver_id')->count());
        $this->artisan('tasks:dispatch')->assertSuccessful();
        $this->assertSame(4, OrderTask::where('order_id', $order->id)->whereNull('driver_id')->count());

        // …and the moment somebody chooses the laundry, the legs are offered.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.order.assign', $order->id), ['laundry_id' => $this->tenant['laundry']->id])
            ->assertRedirect();

        $this->assertSame(4, OrderTask::where('order_id', $order->id)->where('driver_id', $driver->id)->count());
    }

    #[Test]
    public function with_both_off_choosing_the_laundry_by_hand_still_sends_no_driver(): void
    {
        // Both switches off, an eligible driver waiting.
        $this->switchOff(AutoAssign::LAUNDRY);
        $this->switchOff(AutoAssign::DRIVER);
        $driver = $this->eligibleDriver();

        $order = $this->place();

        // The admin chooses the laundry…
        $this->actingAs($this->superAdmin())
            ->put(route('admin.order.assign', $order->id), ['laundry_id' => $this->tenant['laundry']->id])
            ->assertRedirect();

        $this->assertSame($this->tenant['laundry']->id, $order->fresh()->laundry_id);

        // …and still nobody is given the legs by the platform, not now and not
        // by the ten-minute sweep: a person assigns the driver too.
        $this->assertSame(4, OrderTask::where('order_id', $order->id)->whereNull('driver_id')->count());
        $this->artisan('tasks:dispatch')->assertSuccessful();
        $this->assertSame(0, OrderTask::where('driver_id', $driver->id)->count());

        // The admin's own «وزّع» is a person deciding, so that one works.
        $this->post(route('admin.order.tasks.dispatch', $order->id))->assertRedirect();
        $this->assertSame(4, OrderTask::where('order_id', $order->id)->where('driver_id', $driver->id)->count());
    }

    #[Test]
    public function trips_are_announced_when_they_become_assignable_not_before(): void
    {
        $this->switchOff(AutoAssign::LAUNDRY);
        $this->switchOff(AutoAssign::DRIVER);
        $admin = $this->superAdmin();

        $order = $this->place();

        // No laundry yet: the legs cannot be sent anywhere, so no «trips waiting».
        $this->assertCount(0, collect($this->bell($admin))->where('url', '/admin/dispatch'));

        // The laundry is chosen: now they are somebody's to assign.
        $this->actingAs($admin)
            ->put(route('admin.order.assign', $order->id), ['laundry_id' => $this->tenant['laundry']->id])
            ->assertRedirect();

        $this->assertCount(1, collect($this->bell($admin))->where('url', '/admin/dispatch'));
    }

    #[Test]
    public function nobody_is_told_to_choose_a_laundry_when_none_covers_the_area(): void
    {
        $this->switchOff(AutoAssign::LAUNDRY);
        $admin = $this->superAdmin();

        // An address in a zone no laundry has claimed.
        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][1])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);

        $this->assertNull($order->laundry_id);
        $this->assertNull(collect($this->bell($admin))->firstWhere('url', "/admin/order/show/{$order->id}"));
    }

    #[Test]
    public function with_drivers_off_the_legs_wait_and_one_notice_says_so(): void
    {
        $this->switchOff(AutoAssign::DRIVER);
        $admin = $this->superAdmin();
        $this->eligibleDriver();

        $order = $this->place();

        // An eligible driver, and still nobody picked for any of the four legs.
        $this->assertSame(4, OrderTask::where('order_id', $order->id)->whereNull('driver_id')->count());

        // One announcement for the order, not four.
        $notices = collect($this->bell($admin))->where('url', '/admin/dispatch');
        $this->assertCount(1, $notices);
        $this->assertStringContainsString($order->code, $notices->first()['message']);
    }

    #[Test]
    public function with_drivers_off_the_sweep_stands_still_but_the_operator_button_works(): void
    {
        $this->switchOff(AutoAssign::DRIVER);
        $order = $this->place();
        $driver = $this->eligibleDriver();

        $this->artisan('tasks:dispatch')->assertSuccessful();
        $this->assertSame(4, OrderTask::where('order_id', $order->id)->whereNull('driver_id')->count());

        // A person pressing «وزّع» is a person deciding.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.dispatch', $order->id))
            ->assertRedirect();

        $this->assertSame(4, OrderTask::where('order_id', $order->id)->where('driver_id', $driver->id)->count());
    }

    #[Test]
    public function a_leg_returned_by_a_failed_attempt_is_not_handed_on_with_drivers_off(): void
    {
        $this->switchOff(AutoAssign::DRIVER);
        $order = $this->place();
        $task = OrderTask::where('order_id', $order->id)->orderBy('sequence')->firstOrFail();

        $this->eligibleDriver();

        $this->assertNull(app(DriverDispatcher::class)->automatically($task));
        $this->assertNull($task->fresh()->driver_id);
    }

    #[Test]
    public function the_switches_are_saved_from_the_settings_screen(): void
    {
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('admin.generalSetting.viewGeneralSetting'))->assertOk()->getContent();
        $this->assertStringContainsString('name="Auto_Assign_Laundry"', $html);
        $this->assertStringContainsString('name="Auto_Assign_Driver"', $html);

        // Unticked: only the hidden zero is posted.
        $this->actingAs($admin)->put(route('admin.generalSetting.updateGeneralSetting'), [
            'Auto_Assign_Laundry' => '0',
            'Auto_Assign_Driver' => '1',
        ])->assertRedirect();

        Cache::flush();
        $this->assertFalse(app(AutoAssign::class)->laundries());
        $this->assertTrue(app(AutoAssign::class)->drivers());

        $this->actingAs($admin)->put(route('admin.generalSetting.updateGeneralSetting'), [
            'Auto_Assign_Laundry' => 'maybe',
        ])->assertSessionHasErrors('Auto_Assign_Laundry');
    }
}
