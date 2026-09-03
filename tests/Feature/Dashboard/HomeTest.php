<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Complaint\Models\Complaint;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Services\OrderService;
use App\Modules\Report\Services\DashboardSummary;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard home page.
 *
 * It replaced a wall of catalogue counts — total customers, total banners, total
 * countries — none of which changed during a working day or led anywhere.
 *
 * The rule the page is built on, and the thing these tests mostly check: **every
 * number is either something happening right now or something waiting for a
 * person.** Two consequences get their own tests, because both are easy to get
 * wrong and neither shows up as an error:
 *
 *   - A zero never appears in the queue. A list of noughts trains people to stop
 *     reading it, and then the one that is not a nought goes unnoticed.
 *   - "Nobody has rated this" is null, not zero. Zero is a verdict.
 */
class HomeTest extends TestCase
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
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->customer = $this->customer('+201055550001');
    }

    private function orderAt(OrderStatus $status, ?int $laundryId = null): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        // Forced rather than transitioned: the lifecycle is covered elsewhere and
        // what matters here is a set of orders resting in known states.
        $order->forceFill([
            'laundry_id' => $laundryId ?? $this->tenant['laundry']->id,
            'status' => $status->value,
        ])->save();

        return $order->fresh();
    }

    private function summary(): DashboardSummary
    {
        return app(DashboardSummary::class);
    }

    // ---------------------------------------------------------------- the page

    #[Test]
    public function both_roles_can_open_it(): void
    {
        $this->actingAs($this->superAdmin())->get('/admin/home')->assertOk();
        $this->actingAs($this->tenant['owner'])->get('/admin/home')->assertOk();
    }

    #[Test]
    public function an_operator_gets_the_dispatch_half_and_a_laundry_does_not(): void
    {
        $this->orderAt(OrderStatus::Cleaning);

        $operator = $this->actingAs($this->superAdmin())->get('/admin/home')->assertOk();
        $this->assertFalse($operator->viewData('isLaundry'));
        $this->assertNotNull($operator->viewData('drivers'));
        $this->assertNotNull($operator->viewData('attention'));

        $laundry = $this->actingAs($this->tenant['owner'])->get('/admin/home')->assertOk();
        $this->assertTrue($laundry->viewData('isLaundry'));

        // Tasks carry no laundry_id, so the tenant scope would not have filtered
        // driver figures. They are withheld rather than filtered.
        $this->assertArrayNotHasKey('drivers', $laundry->original->getData());
        $this->assertArrayNotHasKey('attention', $laundry->original->getData());
    }

    // ------------------------------------------------------------- in flight

    #[Test]
    public function in_flight_counts_where_each_live_order_physically_is(): void
    {
        $this->orderAt(OrderStatus::AwaitingPickup);
        $this->orderAt(OrderStatus::DriverOnWay);
        $this->orderAt(OrderStatus::PickedUp);
        $this->orderAt(OrderStatus::Cleaning);
        $this->orderAt(OrderStatus::Confirmed);
        $this->orderAt(OrderStatus::ReadyForDelivery);

        $this->actingAs($this->superAdmin());

        $inFlight = $this->summary()->inFlight();

        $this->assertSame(1, $inFlight['awaiting_pickup']);
        // On the way to collect, and carrying. Both are "with a driver".
        $this->assertSame(2, $inFlight['with_driver']);
        // Being counted, priced or washed.
        $this->assertSame(2, $inFlight['at_laundry']);
        $this->assertSame(1, $inFlight['ready_to_go']);
    }

    #[Test]
    public function a_finished_order_is_not_in_flight(): void
    {
        $this->orderAt(OrderStatus::Completed);
        $this->orderAt(OrderStatus::Cancelled);

        $this->actingAs($this->superAdmin());

        $this->assertSame(0, array_sum($this->summary()->inFlight()));
    }

    #[Test]
    public function delivered_and_unpaid_is_counted_separately(): void
    {
        $unpaid = $this->orderAt(OrderStatus::Delivered);

        $paid = $this->orderAt(OrderStatus::Delivered);
        $paid->forceFill(['paid_at' => now()])->save();

        $this->actingAs($this->superAdmin());

        // Handed over with money still outstanding — the figure somebody chases.
        $this->assertSame(1, $this->summary()->inFlight()['delivered_unpaid']);
        $this->assertNotNull($unpaid->fresh()->id);
    }

    // ----------------------------------------------------------------- queue

    #[Test]
    public function the_queue_is_empty_when_there_is_genuinely_nothing_to_do(): void
    {
        // No orders, no complaints, no refunds. The page says so rather than
        // showing a column of noughts.
        $this->actingAs($this->superAdmin());

        $this->assertSame([], $this->summary()->needsAPerson());
    }

    #[Test]
    public function a_freshly_placed_order_legitimately_shows_journeys_with_no_driver(): void
    {
        // Placement creates all four legs at once, unassigned, and dispatch fills
        // them afterwards. Until it does, they really are journeys with no driver —
        // this is the queue working, not a false positive, and it is worth pinning
        // down because it is the most common thing the page will ever show.
        $this->orderAt(OrderStatus::AwaitingPickup);

        $this->actingAs($this->superAdmin());

        $queue = collect($this->summary()->needsAPerson())->keyBy('key');

        $this->assertArrayHasKey('tasks_queued', $queue->all());
        $this->assertSame(4, $queue['tasks_queued']['count']);
        $this->assertSame('critical', $queue['tasks_queued']['severity']);
    }

    #[Test]
    public function a_zero_never_appears_in_the_queue(): void
    {
        // One real problem, and nothing else. A row of noughts around it trains
        // people to stop reading the list.
        Complaint::create([
            'reference' => 'CMP-'.strtoupper(Str::random(8)),
            'user_id' => $this->customer->id,
            'category' => 'other',
            'body' => 'a complaint about something',
            'status' => 'new',
        ]);

        $this->actingAs($this->superAdmin());

        $queue = $this->summary()->needsAPerson();

        // No orders placed, so the complaint is the only thing waiting.
        $this->assertCount(1, $queue);
        $this->assertSame('complaints', $queue[0]['key']);
        $this->assertSame(1, $queue[0]['count']);

        foreach ($queue as $item) {
            $this->assertGreaterThan(0, $item['count']);
        }
    }

    #[Test]
    public function an_unassigned_order_is_the_most_serious_thing_on_the_page(): void
    {
        $order = $this->orderAt(OrderStatus::AwaitingPickup);
        $order->forceFill(['laundry_id' => null])->save();

        $this->actingAs($this->superAdmin());

        $queue = collect($this->summary()->needsAPerson())->keyBy('key');

        $this->assertSame(1, $queue['unassigned']['count']);
        // Nothing can happen to it at all, which is the worst kind of waiting.
        $this->assertSame('critical', $queue['unassigned']['severity']);
    }

    #[Test]
    public function a_complaint_open_over_a_day_raises_its_own_severity(): void
    {
        $complaint = Complaint::create([
            'reference' => 'CMP-'.strtoupper(Str::random(8)),
            'user_id' => $this->customer->id,
            'category' => 'other',
            'body' => 'a complaint about something',
            'status' => 'new',
        ]);

        $this->actingAs($this->superAdmin());

        $fresh = collect($this->summary()->needsAPerson())->keyBy('key');
        $this->assertSame('warning', $fresh['complaints']['severity']);

        $complaint->forceFill(['created_at' => now()->subDays(2)])->save();

        $stale = collect($this->summary()->needsAPerson())->keyBy('key');
        // Same count, different urgency. A total cannot express that.
        $this->assertSame('critical', $stale['complaints']['severity']);
    }

    #[Test]
    public function every_queue_row_carries_somewhere_to_go(): void
    {
        $order = $this->orderAt(OrderStatus::AwaitingPickup);
        $order->forceFill(['laundry_id' => null])->save();

        Complaint::create([
            'reference' => 'CMP-'.strtoupper(Str::random(8)),
            'user_id' => $this->customer->id,
            'category' => 'other',
            'body' => 'a complaint about something',
            'status' => 'new',
        ]);

        $this->actingAs($this->superAdmin());

        foreach ($this->summary()->needsAPerson() as $item) {
            // A number nobody can click is a number they have to go and find.
            $this->assertNotNull($item['route'], $item['key'].' has no route');
            $this->assertTrue(Route::has($item['route']));
            $this->assertNotSame('', trim(__($item['hint'])));
        }
    }

    // ------------------------------------------------------------ the laundry

    #[Test]
    public function the_laundry_queue_is_its_working_day_in_order(): void
    {
        $this->orderAt(OrderStatus::PickedUp);
        $this->orderAt(OrderStatus::Confirmed);
        $this->orderAt(OrderStatus::Confirmed);
        $this->orderAt(OrderStatus::Cleaning);

        $this->actingAs($this->tenant['owner']);

        $queue = collect($this->summary()->laundryQueue())->keyBy('key');

        $this->assertSame(1, $queue['to_count']['count']);
        $this->assertSame(2, $queue['to_start']['count']);
        $this->assertSame(1, $queue['to_finish']['count']);

        // Counting comes first because nothing else can happen until it is done.
        $this->assertSame('critical', $queue['to_count']['severity']);
    }

    #[Test]
    public function a_laundry_sees_only_its_own_orders_on_the_home_page(): void
    {
        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        $this->orderAt(OrderStatus::Cleaning);
        $this->orderAt(OrderStatus::Cleaning, $other['laundry']->id);
        $this->orderAt(OrderStatus::Cleaning, $other['laundry']->id);

        $this->actingAs($this->tenant['owner']);
        $this->assertSame(1, $this->summary()->inFlight()['at_laundry']);

        $this->actingAs($other['owner']);
        $this->assertSame(2, $this->summary()->inFlight()['at_laundry']);

        // And the operator sees all three, through the same method.
        $this->actingAs($this->superAdmin());
        $this->assertSame(3, $this->summary()->inFlight()['at_laundry']);
    }

    // ------------------------------------------------------------- the month

    #[Test]
    public function an_unrated_month_reports_null_and_not_zero(): void
    {
        $this->orderAt(OrderStatus::Completed);

        $this->actingAs($this->superAdmin());

        // Zero on a five-point scale is a verdict, and nobody gave it.
        $this->assertNull($this->summary()->thisMonth()['average_rating']);
    }

    #[Test]
    public function the_month_reports_the_rating_and_the_unhappy_separately(): void
    {
        foreach ([5, 4, 1] as $score) {
            $order = $this->orderAt(OrderStatus::Completed);

            OrderRating::withoutGlobalScopes()->create([
                'order_id' => $order->id,
                'user_id' => $this->customer->id,
                'laundry_id' => $this->tenant['laundry']->id,
                'overall' => $score,
            ]);
        }

        $this->actingAs($this->superAdmin());

        $month = $this->summary()->thisMonth();

        $this->assertSame(3.3, $month['average_rating']);
        // An average of 3.3 hides that somebody gave one star.
        $this->assertSame(1, $month['unhappy']);
    }

    #[Test]
    public function the_cancellation_figure_is_a_rate_not_a_count(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->orderAt(OrderStatus::Completed);
        }
        $this->orderAt(OrderStatus::Cancelled);

        $this->actingAs($this->superAdmin());

        // Five cancellations out of six is a crisis; five out of five hundred is
        // a Tuesday. The count alone cannot tell them apart.
        $this->assertSame(20.0, $this->summary()->thisMonth()['cancellation_rate']);
    }

    #[Test]
    public function money_taken_today_is_dated_by_payment_not_by_order(): void
    {
        $old = $this->orderAt(OrderStatus::Completed);
        $old->forceFill(['created_at' => now()->subMonths(2), 'paid_at' => now()])->save();

        $this->actingAs($this->superAdmin());

        // Paid today for an order placed two months ago still counts today — the
        // same rule the revenue report uses, so the two cannot disagree.
        $this->assertGreaterThan(0, $this->summary()->today()['money_taken']);
    }

    #[Test]
    public function todays_figures_ignore_yesterday(): void
    {
        $yesterday = $this->orderAt(OrderStatus::AwaitingPickup);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();

        $this->orderAt(OrderStatus::AwaitingPickup);

        $this->actingAs($this->superAdmin());

        $this->assertSame(1, $this->summary()->today()['orders_placed']);
    }

    // ------------------------------------------------------------- attention

    #[Test]
    public function the_attention_list_puts_the_oldest_problem_first(): void
    {
        $recent = $this->orderAt(OrderStatus::Reviewed);

        $old = $this->orderAt(OrderStatus::Reviewed);
        $old->forceFill(['created_at' => now()->subDays(5)])->save();

        $this->actingAs($this->superAdmin());

        $attention = $this->summary()->attentionOrders();

        // Newest-first would bury the order stuck since Tuesday behind one placed
        // a minute ago.
        $this->assertSame($old->id, $attention->first()->id);
        $this->assertSame($recent->id, $attention->last()->id);
    }

    #[Test]
    public function a_healthy_order_is_not_on_the_attention_list(): void
    {
        $this->orderAt(OrderStatus::Cleaning);
        $this->orderAt(OrderStatus::ReadyForDelivery);

        $this->actingAs($this->superAdmin());

        $this->assertCount(0, $this->summary()->attentionOrders());
    }

    // --------------------------------------------------------------- drivers

    #[Test]
    public function a_driver_carrying_an_order_is_not_counted_as_free(): void
    {
        $driver = $this->driverUser('+201066660001');
        $order = $this->orderAt(OrderStatus::PickedUp);

        // The four legs already exist — placement creates them, and
        // (order_id, type) is unique — so this assigns one rather than inserting.
        $order->tasks()->orderBy('sequence')->first()
            ->forceFill(['status' => 'started', 'driver_id' => $driver->id])->save();

        $this->actingAs($this->superAdmin());

        $drivers = $this->summary()->drivers();

        $this->assertSame(1, $drivers['total']);
        $this->assertSame(1, $drivers['busy']);
        $this->assertSame(0, $drivers['idle']);
        // All four legs of this order are still open, one of them assigned.
        $this->assertSame(4, $drivers['open_journeys']);
    }

    #[Test]
    public function a_driver_holding_two_orders_is_counted_once(): void
    {
        $driver = $this->driverUser('+201066660001');

        foreach ([1, 2] as $ignored) {
            $order = $this->orderAt(OrderStatus::PickedUp);

            $order->tasks()->orderBy('sequence')->first()
                ->forceFill(['status' => 'started', 'driver_id' => $driver->id])->save();
        }

        $this->actingAs($this->superAdmin());

        $drivers = $this->summary()->drivers();

        // Busy counts people, not journeys. A driver can carry more than one, and
        // if this ever counted rows instead, capacity planning would be nonsense.
        $this->assertSame(1, $drivers['busy']);
        $this->assertSame(8, $drivers['open_journeys']);
    }
}
