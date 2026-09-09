<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dispatch board.
 *
 * Until it existed, a leg could only be given a driver from inside its own
 * order's page: filter the order list to «has a journey with no driver», open an
 * order, read its Transport table, assign, go back, open the next. The home page
 * counted fifteen waiting journeys and reaching them meant walking four orders.
 *
 * The rows were already being built and discarded —
 * `OperationsReport::queuedTasks()` assembles order code, leg, waiting hours and
 * attempts for up to fifty waiting legs, and the operations report renders the
 * count.
 *
 * **What belongs on it:** a leg with no driver that is not finished. One filter
 * for both things the home queue counts apart — a pending leg nobody took, and a
 * failed one, since `TaskService` nulls `driver_id` on failure.
 */
class DispatchBoardTest extends TestCase
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

    private function order(?int $laundryId = null): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $order->forceFill([
            'laundry_id' => $laundryId ?? $this->tenant['laundry']->id,
            'status' => OrderStatus::AwaitingPickup->value,
        ])->save();

        // Placement dispatches each leg as it creates it, so an order placed
        // while an eligible driver exists arrives fully assigned. The board is
        // about legs nobody has, so they go back in the queue.
        $order->tasks()->update([
            'driver_id' => null,
            'assigned_at' => null,
            'status' => 'pending',
        ]);

        return $order->fresh(['tasks']);
    }

    // ----------------------------------------------------------- the screen

    #[Test]
    public function it_lists_every_leg_that_needs_a_person(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk();

        // All four legs of the one order, each on its own row — which is the
        // point: they are legs, not orders.
        $response->assertSee('#'.$order->code, false);
        $response->assertSee(__('Pick up from customer'), false);
        $response->assertSee(__('Deliver to customer'), false);

        $this->assertSame(4, $response->viewData('counts')['waiting']);
        $this->assertSame(1, $response->viewData('counts')['orders']);
    }

    #[Test]
    public function a_leg_with_a_driver_is_not_on_the_board(): void
    {
        // The board is a worklist. A leg somebody already has is not work.
        $driver = $this->driverUser('+201033330001', zoneIds: [$this->geo['zones'][0]]);

        $order = $this->order();
        $order->tasks()->limit(1)->update(['driver_id' => $driver->id, 'status' => 'assigned']);

        $counts = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->viewData('counts');

        $this->assertSame(3, $counts['waiting']);
    }

    #[Test]
    public function a_completed_leg_is_not_on_the_board_either(): void
    {
        $order = $this->order();
        $order->tasks()->limit(1)->update(['status' => 'completed']);

        $counts = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->viewData('counts');

        $this->assertSame(3, $counts['waiting']);
    }

    #[Test]
    public function a_failed_leg_is_counted_apart_from_one_nobody_took(): void
    {
        // Two different problems: the first needs a driver, the second needs
        // somebody to find out what happened. Both are waiting, and lumping
        // them together hides the one that needs a phone call.
        $order = $this->order();
        $order->tasks()->limit(1)->update(['status' => 'failed', 'attempts' => 2]);

        $counts = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->viewData('counts');

        $this->assertSame(4, $counts['waiting']);
        $this->assertSame(3, $counts['never_taken']);
        $this->assertSame(1, $counts['failed']);
    }

    #[Test]
    public function the_longest_wait_comes_first(): void
    {
        // The dispatcher's own priority: a journey sitting for six hours is the
        // one somebody has to explain.
        $old = $this->order();
        $old->tasks()->update(['created_at' => now()->subDays(3)]);

        $fresh = $this->order();
        $fresh->tasks()->update(['created_at' => now()]);

        $legs = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->viewData('legs');

        $this->assertSame($old->id, $legs->items()[0]->order_id);
    }

    #[Test]
    public function the_row_carries_the_area_that_decides_eligibility(): void
    {
        // Without it the operator reads "no eligible driver" and has to open the
        // order, then the address, to find out which area to staff.
        $this->order();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertSee(getLocalizedValueDashboard($this->geo['zones'][0], 'name'), false);
    }

    // -------------------------------------------------------- acting on it

    #[Test]
    public function a_leg_can_be_assigned_from_the_board_and_leaves_it(): void
    {
        $driver = $this->driverUser('+201033330002', zoneIds: [$this->geo['zones'][0]]);
        $order = $this->order();
        $leg = $order->tasks->firstWhere('sequence', 1);

        // The board reuses the order screen's own assign route, so there is one
        // code path for giving a leg to a driver rather than two that could
        // disagree about the eligibility rules.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.assign', $leg->id), ['driver_id' => $driver->id])
            ->assertRedirect();

        $counts = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->viewData('counts');

        $this->assertSame(3, $counts['waiting']);
    }

    #[Test]
    public function the_whole_order_can_be_cleared_from_one_row(): void
    {
        $driver = $this->driverUser('+201033330003', zoneIds: [$this->geo['zones'][0]]);
        $order = $this->order();
        $leg = $order->tasks->firstWhere('sequence', 1);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.tasks.assign', $leg->id), [
                'driver_id' => $driver->id,
                'rest_of_order' => '1',
            ])
            ->assertRedirect();

        $counts = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->viewData('counts');

        $this->assertSame(0, $counts['waiting']);
    }

    #[Test]
    public function the_board_wide_retry_runs_the_same_sweep_the_cron_does(): void
    {
        $this->order();
        $this->order();

        // The fix an operator makes, then the button rather than ten minutes.
        $driver = $this->driverUser('+201033330004');
        $driver->zones()->sync([$this->geo['zones'][0]]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.dispatch.redispatch'))
            ->assertRedirect();

        $counts = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->viewData('counts');

        $this->assertSame(0, $counts['waiting']);
    }

    #[Test]
    public function the_retry_says_so_rather_than_claiming_success_when_nothing_changed(): void
    {
        $this->order();

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.dispatch.redispatch'))
            ->assertRedirect();

        $this->assertNotNull($response->getSession()->get('error'));
    }

    #[Test]
    public function the_row_says_why_nobody_is_eligible(): void
    {
        // The board's whole advantage over a filtered order list: the reason is
        // on the row, so the operator knows whether to add a zone, flip a
        // switch, raise a cap or fix the address.
        $this->order();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertSee(__('There are no drivers on the system yet'), false);
    }

    // ------------------------------------------------------ search + filter

    #[Test]
    public function it_can_be_searched_by_order_code(): void
    {
        $wanted = $this->order();
        $other = $this->order();

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.search', ['query' => $wanted->code]), [
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk();

        $response->assertSee('#'.$wanted->code, false);
        $response->assertDontSee('#'.$other->code, false);
    }

    #[Test]
    public function it_can_be_filtered_to_one_leg_of_the_journey(): void
    {
        $this->order();

        $legs = $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index', ['leg' => 'deliver_to_customer']))
            ->assertOk()
            ->viewData('legs');

        $this->assertCount(1, $legs->items());
        $this->assertSame('deliver_to_customer', $legs->items()[0]->type->value);
    }

    #[Test]
    public function an_empty_board_and_an_empty_search_do_not_say_the_same_thing(): void
    {
        // Claiming every journey has a driver because a search missed is a
        // statement the operator would act on.
        $this->order();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index', ['query' => 'nothing-matches-this']))
            ->assertOk()
            ->assertSee(__('Nothing here matches that.'), false)
            ->assertDontSee(__('Every journey has somebody on it.'), false);
    }

    // ------------------------------------------------------------ who sees it

    #[Test]
    public function a_laundry_owner_sees_only_its_own_legs(): void
    {
        $mine = $this->order();

        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');
        $theirs = $this->order($other['laundry']->id);

        $response = $this->actingAs($this->tenant['owner'])
            ->get(route('admin.dispatch.index'))
            ->assertOk();

        $response->assertSee('#'.$mine->code, false);
        $response->assertDontSee('#'.$theirs->code, false);
        $this->assertSame(4, $response->viewData('counts')['waiting']);
    }

    #[Test]
    public function the_board_is_gated_on_its_own_permission(): void
    {
        // `order_task.view`, so a dispatcher can be given the board without full
        // access to orders — and so it appears in the sidebar at all, which
        // MenuBuilder derives from `{key}.view`.
        // `grant()` syncs a role's permissions, so the gate is exercised by
        // taking the permission away from a role that has it and putting it
        // back — the laundry owner, who is granted it by RoleSeeder.
        $this->grant('laundry_owner', ['laundry.view', 'order.view']);

        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.dispatch.index'))
            ->assertForbidden();

        $this->grant('laundry_owner', ['laundry.view', 'order.view', 'order_task.view']);

        // `fresh()`, because the owner instance from setUp already has
        // `role.permissions` loaded: `CheckPermission` would read the set as it
        // was before the grant and the second half of this test would fail on a
        // stale relation rather than on the gate.
        $this->actingAs($this->tenant['owner']->fresh())
            ->get(route('admin.dispatch.index'))
            ->assertOk();
    }

    #[Test]
    public function it_appears_in_the_sidebar_for_somebody_who_can_see_it(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertSee(route('admin.dispatch.index'), false);
    }
}
