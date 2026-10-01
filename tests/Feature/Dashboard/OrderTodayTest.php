<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\OrderTodayService;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use App\Services\MenuBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «طلبات اليوم» — a day's work: which orders, how many pieces in each, and how
 * many of each piece across all of them, nearest first.
 */
class OrderTodayTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

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

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer('+201099880011');
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $pieces  [item index, qty]
     */
    private function place(array $pieces, array $extra = []): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => array_map(fn ($p) => ['item_id' => $this->catalog['items'][$p[0]]->id, 'qty' => $p[1]], $pieces),
            'accepts_review_terms' => true,
        ]);

        if ($extra !== []) {
            $order->forceFill($extra)->save();
        }

        return $order->fresh();
    }

    /** The pieces reached the laundry: the inbound leg is complete. */
    private function atLaundry(Order $order): Order
    {
        OrderTask::where('order_id', $order->id)->where('type', TaskType::PickupFromCustomer->value)
            ->update(['status' => TaskStatus::Completed->value]);
        OrderTask::where('order_id', $order->id)->where('type', TaskType::DeliverToLaundry->value)
            ->update(['status' => TaskStatus::Completed->value]);

        return $order->fresh();
    }

    private function board(array $input = [], ?User $as = null): array
    {
        $this->actingAs($as ?? $this->superAdmin());

        return app(OrderTodayService::class)->shredData($input);
    }

    #[Test]
    public function in_the_laundry_means_the_pieces_are_there(): void
    {
        $there = $this->atLaundry($this->place([[0, 2]]));
        $coming = $this->place([[0, 1]]);
        $gone = $this->atLaundry($this->place([[1, 1]]));
        OrderTask::where('order_id', $gone->id)->where('type', TaskType::CollectFromLaundry->value)
            ->update(['status' => TaskStatus::Completed->value]);

        $ids = $this->board()['rows']->pluck('order.id')->all();

        $this->assertSame([$there->id], $ids);
        $this->assertNotContains($coming->id, $ids);
        $this->assertNotContains($gone->id, $ids);
    }

    #[Test]
    public function the_search_finds_anything_the_row_shows(): void
    {
        $shirts = $this->atLaundry($this->place([[0, 2]]));
        $trousers = $this->atLaundry($this->place([[1, 1]]));

        $found = fn (string $term) => $this->board(['query' => $term])['rows']->pluck('order.id')->sort()->values()->all();

        // A piece, by name and in any case — the column is json on MariaDB.
        $this->assertSame([$trousers->id], $found('trous'));
        $this->assertSame([$shirts->id], $found('SHIRT'));
        // The service and the laundry shown in the row.
        $this->assertSame([$shirts->id, $trousers->id], $found(strtolower($this->catalog['service']->name->en)));
        $this->assertSame([$shirts->id, $trousers->id], $found($this->tenant['laundry']->name->en));
        // And the code, as before.
        $this->assertSame([$shirts->id], $found($shirts->code));
    }

    #[Test]
    public function the_totals_add_up_per_order_and_across_them(): void
    {
        // 2 shirts + 3 trousers, and 1 shirt: 6 pieces, 3 of each.
        $this->atLaundry($this->place([[0, 2], [1, 3]]));
        $this->atLaundry($this->place([[0, 1]]));

        $board = $this->board();

        $this->assertSame([5, 1], $board['rows']->pluck('total')->sort()->reverse()->values()->all());
        $this->assertSame(2, $board['summary']['orders']);
        $this->assertSame(6, $board['summary']['pieces']);

        $byItem = collect($board['summary']['by_item'])->pluck('qty', 'item')->sortKeys()->all();
        $this->assertSame(['Shirt' => 3, 'Trousers' => 3], $byItem);

        // By service too: one service here, holding all six.
        $this->assertCount(1, $board['summary']['by_service']);
        $this->assertSame(6, $board['summary']['by_service'][0]['total']);

        // And as the table the screen draws: a row per item, a total per row,
        // and the service totals in the same order as the service columns.
        $table = collect($board['summary']['items_table'])->pluck('total', 'item')->sortKeys()->all();
        $this->assertSame(['Shirt' => 3, 'Trousers' => 3], $table);
        $this->assertSame(array_keys($board['summary']['service_columns']), array_keys($board['summary']['service_totals']));
    }

    #[Test]
    public function a_counted_order_shows_the_count_not_the_estimate(): void
    {
        $order = $this->place([[0, 2]]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');
        $this->atLaundry($order);

        // The customer said 2; the laundry counted 5.
        app(OrderReviewService::class)->review(
            $order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 5]], null, $this->tenant['owner']
        );

        $row = $this->board()['rows']->first();

        $this->assertTrue($row['counted']);
        $this->assertSame(5, $row['total']);
        $this->assertSame(0, $this->board()['summary']['estimated']);
    }

    #[Test]
    public function due_for_delivery_on_a_day_comes_nearest_first(): void
    {
        $morning = TimeSlot::create(['start_time' => '09:00:00', 'end_time' => '12:00:00', 'applies_to' => 'both', 'status' => 'active']);
        $evening = TimeSlot::create(['start_time' => '18:00:00', 'end_time' => '21:00:00', 'applies_to' => 'both', 'status' => 'active']);

        $today = Carbon::now(displayTimezone())->toDateString();
        $tomorrow = Carbon::now(displayTimezone())->addDay()->toDateString();

        $late = $this->place([[0, 1]], ['delivery_date' => $today, 'delivery_slot_id' => $evening->id]);
        $early = $this->place([[0, 1]], ['delivery_date' => $today, 'delivery_slot_id' => $morning->id]);
        $notToday = $this->place([[0, 1]], ['delivery_date' => $tomorrow, 'delivery_slot_id' => $morning->id]);

        $ids = $this->board(['scope' => 'delivery_today'])['rows']->pluck('order.id')->all();
        $this->assertSame([$early->id, $late->id], $ids);

        // And the date is a filter, not a fixture of the screen.
        $ids = $this->board(['scope' => 'delivery_today', 'date' => $tomorrow])['rows']->pluck('order.id')->all();
        $this->assertSame([$notToday->id], $ids);
    }

    /**
     * «التواريخ مش مظبوطة» (the owner, 2026-10-01): an order booked for the
     * 15th, collected on the 21st and still at the laundry on the 1st said
     * «Delivery 2026-09-15» on today's board as if nothing were wrong. The
     * booking stays as booked; the row says how late it is.
     */
    #[Test]
    public function a_date_gone_by_says_how_late_it_is(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 11:00:00', 'UTC'));

        $overdue = $this->atLaundry($this->place([[0, 1]], ['delivery_date' => '2026-09-15']));
        $yesterday = $this->atLaundry($this->place([[0, 1]], ['delivery_date' => '2026-09-30']));
        $today = $this->atLaundry($this->place([[0, 1]], ['delivery_date' => '2026-10-01']));
        $undated = $this->atLaundry($this->place([[0, 1]]));

        $late = $this->board()['rows']->pluck('late_days', 'order.id');

        $this->assertSame(16, $late[$overdue->id]);
        $this->assertSame(1, $late[$yesterday->id]);
        $this->assertNull($late[$today->id]);
        $this->assertNull($late[$undated->id]);

        $this->actingAs($this->superAdmin())->get(route('admin.order_today.index'))
            ->assertOk()
            ->assertSee(__('Late by :days days', ['days' => 16]))
            ->assertSee(__('Late by a day'));
    }

    #[Test]
    public function a_half_that_is_done_or_stopped_is_not_late(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 11:00:00', 'UTC'));
        $past = ['pickup_date' => '2026-09-28'];
        $board = fn (array $extra = []) => $this->board(['scope' => 'pickup_today', 'date' => '2026-09-28'] + $extra);

        $waiting = $this->place([[0, 1]], $past);
        $collected = $this->place([[0, 1]], $past);
        OrderTask::where('order_id', $collected->id)->where('type', TaskType::PickupFromCustomer->value)
            ->update(['status' => TaskStatus::Completed->value, 'completed_at' => '2026-09-30 09:30:00']);
        $cancelled = $this->place([[0, 1]], $past);
        app(OrderStateMachine::class)->transition($cancelled, OrderStatus::Cancelled, 'customer');

        $rows = $board()['rows']->keyBy('order.id');

        // Nobody collected it: three days late.
        $this->assertSame(3, $rows[$waiting->id]['late_days']);
        // Collected, two days after the booking: not late, and it says when.
        $this->assertNull($rows[$collected->id]['late_days']);
        $this->assertSame('2026-09-30', $rows[$collected->id]['collected_at']->toDateString());
        $this->assertNull($rows[$waiting->id]['collected_at']);
        // A cancelled order is not late; it is not happening.
        $this->assertNull($board(['status' => 'cancelled'])['rows']->first()['late_days']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order_today.index', ['scope' => 'pickup_today', 'date' => '2026-09-28']))
            ->assertOk()
            ->assertSee(__('Collected on :date', ['date' => '2026-09-30']));
    }

    #[Test]
    public function a_cancelled_order_is_not_work_unless_asked_for(): void
    {
        $today = Carbon::now(displayTimezone())->toDateString();
        $order = $this->place([[0, 1]], ['pickup_date' => $today]);
        app(OrderStateMachine::class)->transition($order, OrderStatus::Cancelled, 'customer');

        $this->assertCount(0, $this->board(['scope' => 'pickup_today'])['rows']);
        $this->assertCount(1, $this->board(['scope' => 'pickup_today', 'status' => 'cancelled'])['rows']);
    }

    #[Test]
    public function a_laundry_sees_its_own_day_and_nobody_elses(): void
    {
        $this->grant('laundry_owner', ['order.view']);

        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        $mine = $this->atLaundry($this->place([[0, 2]]));
        $theirs = $this->atLaundry($this->place([[1, 4]], ['laundry_id' => $other['laundry']->id]));

        $board = $this->board([], $this->tenant['owner']);

        $this->assertSame([$mine->id], $board['rows']->pluck('order.id')->all());
        $this->assertSame(2, $board['summary']['pieces']);
        // No laundry filter for somebody inside one — and a forged one is dropped.
        $this->assertTrue($board['laundries']->isEmpty());
        $this->assertNull($this->board(['laundry_id' => $other['laundry']->id], $this->tenant['owner'])['filters']['laundry_id']);

        // The super admin sees both, and can narrow to one.
        $this->assertCount(2, $this->board()['rows']);
        $this->assertSame([$theirs->id], $this->board(['laundry_id' => $other['laundry']->id])['rows']->pluck('order.id')->all());
    }

    #[Test]
    public function it_sits_in_the_orders_menu_group_and_answers_to_its_permission(): void
    {
        $this->atLaundry($this->place([[0, 2]]));

        // Without `order.view`: neither the page nor the menu group.
        $this->grant('laundry_owner', ['laundry.view']);
        $this->actingAs($this->tenant['owner'])->get(route('admin.order_today.index'))->assertForbidden();
        $this->assertNull($this->ordersGroup());

        $this->grant('laundry_owner', ['order.view']);
        // A fresh instance: the one above carries the permissions it loaded
        // before this grant.
        $owner = $this->tenant['owner']->fresh();

        $this->actingAs($owner)
            ->get(route('admin.order_today.index'))
            ->assertOk()
            ->assertSee(__('Pieces by item'), false)
            ->assertSee('order-today-details-', false);

        // One «Orders» dropdown holding both, like Marketing holds its screens.
        $group = $this->ordersGroup();
        $this->assertNotNull($group);
        $this->assertSame(
            ['admin.order.index', 'admin.order_today.index'],
            collect($group['items'])->pluck('route')->all()
        );

        // The AJAX half redraws the rows and the totals together.
        $this->actingAs($owner)
            ->getJson(route('admin.order_today.search', ['scope' => 'in_laundry']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonStructure(['table', 'summary', 'pagination']);
    }

    /**
     * The «Orders» dropdown as the signed-in user's sidebar builds it, if any.
     *
     * @return array<string, mixed>|null
     */
    private function ordersGroup(): ?array
    {
        return collect(MenuBuilder::build())
            ->first(fn (array $entry) => ($entry['type'] ?? null) === 'group' && $entry['title'] === 'Orders');
    }
}
