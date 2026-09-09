<?php

namespace Tests\Feature\Dashboard;

use App\Modules\City\Models\City;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Why a leg has no driver — said on the screen instead of guessed at.
 *
 * The order page used to print «No eligible driver» and stop. That one sentence
 * covers five unrelated situations, and each has a different remedy:
 *
 *   - nobody is assigned to the area                → give a driver the zone
 *   - they are all inactive                         → reactivate an account
 *   - none is switched on as available              → the driver's own switch
 *   - they are set to another city                  → fix the profile
 *   - they are all at their concurrent-order cap    → raise it, or wait
 *
 * plus a sixth that is a data problem rather than a staffing one: the address
 * never got an area, so no rule can even be evaluated.
 *
 * This came in as a question — "the drivers appear based on what?" — after an
 * operator watched an empty dropdown and could not tell which of the five it
 * was. On that install it was the cap: one driver, holding three orders, limit
 * three. Nothing in the panel said so.
 *
 * `isEligible()` remains the authority on eligibility. Nothing here decides who
 * qualifies; it reports where the candidates went, in the order the rules apply.
 */
class DispatchReasonTest extends TestCase
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

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $order->forceFill([
            'laundry_id' => $this->tenant['laundry']->id,
            'status' => OrderStatus::AwaitingPickup->value,
        ])->save();

        return $order->fresh(['tasks']);
    }

    private function firstLeg(?Order $order = null): OrderTask
    {
        return ($order ?? $this->order())->tasks->firstWhere('sequence', 1);
    }

    /**
     * An order whose legs are all in the queue.
     *
     * `TaskGenerator::generate()` calls `dispatch()` on each leg the moment it
     * creates them, so placing an order while an eligible driver exists hands
     * him the whole chain immediately. That is correct behaviour — and it means
     * a test that places an order and then assigns a leg is asserting against a
     * chain that already has a driver on it. Five of these tests failed on
     * exactly that before this helper existed.
     *
     * Returned to the queue in bulk, which is what `release()` does per leg.
     */
    private function queuedOrder(): Order
    {
        $order = $this->order();

        $order->tasks()->update([
            'driver_id' => null,
            'assigned_at' => null,
            'started_at' => null,
            'status' => 'pending',
        ]);

        return $order->fresh(['tasks']);
    }

    private function why(OrderTask $task): ?array
    {
        return app(DriverDispatcher::class)->whyNobodyEligible($task);
    }

    // -----------------------------------------------------------------

    #[Test]
    public function an_eligible_driver_means_there_is_nothing_to_explain(): void
    {
        // The guard on everything below: a reason must never appear beside a
        // dropdown that has somebody in it.
        $this->driverUser('+201033330001', zoneIds: [$this->geo['zones'][0]]);

        $task = $this->firstLeg();

        $this->assertNotEmpty(app(DriverDispatcher::class)->candidates($task));
        $this->assertNull($this->why($task));
    }

    #[Test]
    public function no_drivers_at_all_says_so(): void
    {
        $this->assertSame(0, Driver::count());

        $this->assertSame(
            'There are no drivers on the system yet',
            $this->why($this->firstLeg())['reason']
        );
    }

    #[Test]
    public function a_driver_who_does_not_cover_the_area_is_reported_as_coverage(): void
    {
        // Assigned to a different zone entirely.
        $this->driverUser('+201033330002', zoneIds: [$this->geo['zones'][1]]);

        $why = $this->why($this->firstLeg());

        $this->assertStringContainsString('No driver covers this area', $why['reason']);
        // And it names how many drivers exist, so "hire somebody" and "give this
        // one the zone" are distinguishable.
        $this->assertSame(['total' => 1], $why['params']);
    }

    #[Test]
    public function covering_but_inactive_is_reported_as_the_account(): void
    {
        $this->driverUser('+201033330003', active: false, zoneIds: [$this->geo['zones'][0]]);

        $why = $this->why($this->firstLeg());

        $this->assertStringContainsString('none of their accounts is active', $why['reason']);
        $this->assertSame(['covering' => 1], $why['params']);
    }

    #[Test]
    public function covering_but_switched_off_is_reported_as_availability(): void
    {
        // The driver's own switch, not something the office set — which is why
        // this has to read differently from an inactive account.
        $this->driverUser('+201033330004', available: false, zoneIds: [$this->geo['zones'][0]]);

        $why = $this->why($this->firstLeg());

        $this->assertStringContainsString('none is switched on as available', $why['reason']);
    }

    #[Test]
    public function covering_but_in_another_city_is_reported_as_the_city(): void
    {
        $driver = $this->driverUser('+201033330005', zoneIds: [$this->geo['zones'][0]]);

        // A real second city, not an invented id: `city_id` is a foreign key, so
        // 999999 fails on the constraint rather than on the rule under test.
        // Null would *not* disqualify — deliberate in the dispatcher — so this
        // has to be a genuine other city.
        $elsewhere = City::create([
            'name' => json_encode(['en' => 'Alexandria', 'ar' => 'الإسكندرية'], JSON_UNESCAPED_UNICODE),
            'country_id' => $this->geo['country']->id,
            'status' => 'active',
        ]);

        $driver->profile->forceFill(['city_id' => $elsewhere->id])->save();

        $why = $this->why($this->firstLeg());

        $this->assertStringContainsString('set to a different city', $why['reason']);
    }

    #[Test]
    public function one_driver_at_their_cap_names_the_numbers(): void
    {
        // The case from the live install, and the one an operator can act on in
        // ten seconds — so it says 3 of 3 rather than "at capacity".
        $driver = $this->driverUser('+201033330006', zoneIds: [$this->geo['zones'][0]]);
        $driver->profile->forceFill(['max_concurrent_orders' => 2])->save();

        // Two other orders already in his hands. Legs, not orders, are what
        // carry the driver — and the cap counts distinct orders.
        $held = [$this->order(), $this->order()];

        foreach ($held as $other) {
            $other->tasks()->update(['driver_id' => $driver->id, 'status' => 'assigned']);
        }

        $why = $this->why($this->firstLeg());

        $this->assertStringContainsString('already holding :held of :cap orders', $why['reason']);
        $this->assertSame(['held' => 2, 'cap' => 2], $why['params']);
    }

    #[Test]
    public function several_drivers_at_their_cap_reads_as_a_group(): void
    {
        $drivers = [
            $this->driverUser('+201033330007', zoneIds: [$this->geo['zones'][0]]),
            $this->driverUser('+201033330008', zoneIds: [$this->geo['zones'][0]]),
        ];

        foreach ($drivers as $driver) {
            $driver->profile->forceFill(['max_concurrent_orders' => 1])->save();
            $this->order()->tasks()->update(['driver_id' => $driver->id, 'status' => 'assigned']);
        }

        $why = $this->why($this->firstLeg());

        $this->assertStringContainsString('every one of them is at their order limit', $why['reason']);
        $this->assertSame(['covering' => 2], $why['params']);
    }

    #[Test]
    public function an_address_with_no_area_is_reported_as_the_address(): void
    {
        // Checked before anything about drivers, because without a zone none of
        // the other four rules can even be evaluated — and the fix is the
        // address, not the rota.
        $this->driverUser('+201033330009', zoneIds: [$this->geo['zones'][0]]);

        $order = $this->order();
        $order->pickupAddress->forceFill(['zone_id' => null])->save();

        $why = $this->why($this->firstLeg($order->fresh(['tasks', 'pickupAddress'])));

        $this->assertStringContainsString('This address has no area set', $why['reason']);
    }

    // ------------------------------------------------- and on the screen

    #[Test]
    public function the_order_screen_prints_the_reason(): void
    {
        $driver = $this->driverUser('+201033330010', zoneIds: [$this->geo['zones'][0]]);
        $driver->profile->forceFill(['max_concurrent_orders' => 1])->save();
        $this->order()->tasks()->update(['driver_id' => $driver->id, 'status' => 'assigned']);

        $order = $this->order();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee('No eligible driver')
            ->assertSee('already holding 1 of 1 orders');
    }

    #[Test]
    public function the_screen_shows_a_dropdown_and_no_reason_when_somebody_can_take_it(): void
    {
        $this->driverUser('+201033330011', zoneIds: [$this->geo['zones'][0]]);

        $order = $this->order();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee('Choose a driver')
            ->assertDontSee('No eligible driver');
    }

    #[Test]
    public function every_reason_is_translated_into_arabic(): void
    {
        // The panel is Arabic-first, and this message is the whole point of the
        // change: an English fallback here is the same dead end in a new coat.
        $arabic = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/resources/lang/ar.json'),
            true
        );

        $reasons = [
            'This address has no area set, so nobody can be matched to it',
            'There are no drivers on the system yet',
            'No driver covers this area — :total drivers, none assigned to it',
            ':covering cover this area, but none of their accounts is active',
            ':covering cover this area, but none is switched on as available',
            ':covering cover this area, but are set to a different city',
            'One driver covers this area and is already holding :held of :cap orders',
            ':covering cover this area and every one of them is at their order limit',
        ];

        foreach ($reasons as $reason) {
            $this->assertArrayHasKey($reason, $arabic, "no Arabic for: {$reason}");

            // Every placeholder in the English has to survive into the Arabic,
            // or the sentence loses the number that made it useful.
            preg_match_all('/:([a-z]+)/', $reason, $matches);

            foreach ($matches[1] as $placeholder) {
                $this->assertStringContainsString(
                    ':'.$placeholder,
                    $arabic[$reason],
                    "the Arabic for '{$reason}' dropped :{$placeholder}"
                );
            }
        }
    }

    // ------------------------------------ giving one driver the whole chain

    #[Test]
    public function the_picker_says_what_each_driver_is_already_carrying(): void
    {
        // The candidates arrive sorted least-loaded-first, which is a decision
        // the dispatcher makes and the operator could not see: two names looked
        // interchangeable when one was a single order off their limit.
        $driver = $this->driverUser('+201033330012', zoneIds: [$this->geo['zones'][0]]);
        $driver->profile->forceFill(['max_concurrent_orders' => 4])->save();

        // One *other* order in his hands, and the order under test left in the
        // queue — otherwise placement gives him that one too and the figure
        // under test moves.
        $this->order()->tasks()->update(['driver_id' => $driver->id, 'status' => 'assigned']);

        $order = $this->queuedOrder();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee('1 of 4');
    }

    #[Test]
    public function a_driver_with_no_cap_is_shown_as_having_none(): void
    {
        // An empty cap means "no limit" by design -- profilePayload() maps the
        // blank field back to null on purpose -- so the picker must not print
        // "1 of" and then nothing.
        $this->driverUser('+201033330013', zoneIds: [$this->geo['zones'][0]]);

        $order = $this->queuedOrder();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee('0, no limit');
    }

    #[Test]
    public function one_submission_can_take_the_whole_chain(): void
    {
        // The normal case, and it used to be four submissions of four forms.
        // The cap counts distinct *orders*, so the remaining legs cost the
        // driver nothing further -- which is why one action is the right shape.
        $driver = $this->driverUser('+201033330014', zoneIds: [$this->geo['zones'][0]]);

        $order = $this->queuedOrder();
        $first = $order->tasks->firstWhere('sequence', 1);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.assign', $first->id), [
                'driver_id' => $driver->id,
                'rest_of_order' => '1',
            ])
            ->assertRedirect();

        $fresh = $order->fresh(['tasks']);

        $this->assertSame(4, $fresh->tasks->count());
        $this->assertSame(
            [$driver->id, $driver->id, $driver->id, $driver->id],
            $fresh->tasks->pluck('driver_id')->all()
        );
    }

    #[Test]
    public function without_the_box_ticked_only_the_one_leg_is_assigned(): void
    {
        // The default has to stay one leg: an operator who wanted one leg and
        // silently got four would have no way to tell until the driver called.
        $driver = $this->driverUser('+201033330015', zoneIds: [$this->geo['zones'][0]]);

        $order = $this->queuedOrder();
        $first = $order->tasks->firstWhere('sequence', 1);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.assign', $first->id), ['driver_id' => $driver->id])
            ->assertRedirect();

        $this->assertSame(1, $order->fresh(['tasks'])->tasks->whereNotNull('driver_id')->count());
    }

    #[Test]
    public function a_leg_the_driver_cannot_take_is_named_rather_than_skipped(): void
    {
        /*
         * The delivery leg is matched against the *delivery* address's zone, so
         * a driver can legitimately be right for three legs and wrong for the
         * fourth. Doing three of four quietly is worse than doing one: the
         * operator would leave believing the order was covered.
         */
        $driver = $this->driverUser('+201033330016', zoneIds: [$this->geo['zones'][0]]);

        $order = $this->queuedOrder();

        // A delivery address in a zone this driver does not serve.
        $elsewhere = $this->addressFor($this->customer, $this->geo['zones'][1]);
        $order->forceFill(['delivery_address_id' => $elsewhere->id])->save();

        $first = $order->fresh(['tasks'])->tasks->firstWhere('sequence', 1);

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.assign', $first->id), [
                'driver_id' => $driver->id,
                'rest_of_order' => '1',
            ])
            ->assertRedirect();

        $fresh = $order->fresh(['tasks']);

        // Three taken, the delivery leg left in the queue and named.
        $this->assertSame(3, $fresh->tasks->whereNotNull('driver_id')->count());
        $this->assertNull($fresh->tasks->firstWhere('type', 'deliver_to_customer')->driver_id);

        $message = $response->getSession()->get('success');
        $this->assertStringContainsString('Still without a driver', $message);
        $this->assertStringContainsString('Deliver to customer', $message);
    }

    // ------------------------------------------------- trying the queue again

    #[Test]
    public function the_queue_can_be_retried_once_the_blocker_is_fixed(): void
    {
        // The whole point: the operator has just given the driver the zone, and
        // the scheduled sweep is up to ten minutes away.
        $order = $this->order();

        $driver = $this->driverUser('+201033330017');
        $this->assertSame(0, $order->tasks->whereNotNull('driver_id')->count());

        // The fix an operator would make.
        $driver->zones()->sync([$this->geo['zones'][0]]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.dispatch', $order->id))
            ->assertRedirect();

        $this->assertSame(4, $order->fresh(['tasks'])->tasks->whereNotNull('driver_id')->count());
    }

    #[Test]
    public function retrying_with_nothing_fixed_says_so_rather_than_claiming_success(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.dispatch', $order->id))
            ->assertRedirect();

        $this->assertNotNull($response->getSession()->get('error'));
        $this->assertSame(0, $order->fresh(['tasks'])->tasks->whereNotNull('driver_id')->count());
    }

    #[Test]
    public function the_retry_button_only_shows_while_something_is_waiting(): void
    {
        $driver = $this->driverUser('+201033330018', zoneIds: [$this->geo['zones'][0]]);

        $waiting = $this->queuedOrder();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $waiting->id))
            ->assertOk()
            ->assertSee('Try the queue again');

        // Now give every leg a driver: there is nothing left to retry.
        $waiting->tasks()->update(['driver_id' => $driver->id, 'status' => 'assigned']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $waiting->id))
            ->assertOk()
            ->assertDontSee('Try the queue again');
    }
}
