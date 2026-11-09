<?php

namespace Tests\Feature\Dashboard;

use App\Models\ActivityLog;
use App\Models\Language;
use App\Models\Role;
use App\Modules\Driver\Models\Driver;
use App\Modules\Notification\Services\PieceCountNotifier;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\PieceCheckStep;
use App\Modules\Order\Enums\PieceCountSource;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\PieceDiscrepancy;
use App\Modules\Order\Models\TaskHold;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\PieceCheck;
use App\Modules\Order\Services\TaskService;
use App\Modules\Report\Services\DashboardSummary;
use App\Modules\User\Models\User;
use App\Notifications\AdminNotification;
use App\Services\MenuBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «عدد القطع المستلمة», checked.
 *
 * Since 2026-11-08 (the client's sheet, items 9 and 12) the laundry's count is
 * the one that stands: the driver counts only when taking the pieces back out
 * of the laundry, a count sent at the doorstep or at the laundry's door is
 * ignored, and a collection that disagrees with the laundry is held for the
 * platform rather than completed — approved, its difference is recorded
 * already closed by whoever approved it.
 *
 * Open differences still exist — orders counted under the old rule carry them
 * — so the surfaces stay: the bell (platform and the order's laundry, in the
 * panel's language), the order screen, the badge, the queues, the filter, and
 * «تمت المراجعة», which only the platform may press, with a note and the real
 * count.
 */
class PieceCheckTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private array $tenant;

    private User $customer;

    private $address;

    private Driver $driver;

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
        // What RoleSeeder gives an owner: its own orders, to view and to work.
        $this->grant('laundry_owner', ['order.view', 'order.update']);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
        $this->driver = $this->driverUser('+201044440001', zoneIds: [$this->geo['zones'][0]->id]);
        // There before any leg is walked, so there is a platform to tell.
        $this->superAdmin();
    }

    // ------------------------------------------------------------ the rule

    #[Test]
    public function a_count_sent_at_the_pickup_or_the_handover_is_ignored_and_raises_nothing(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 1);

        // The owner's screenshot — one ordered, two counted at the door — and
        // three at the laundry's door, from an app that still asks. Neither is
        // the driver's to count any more, so neither is stored or compared.
        $this->walk($order, TaskType::PickupFromCustomer, 2, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 3);

        foreach ([TaskType::PickupFromCustomer, TaskType::DeliverToLaundry] as $type) {
            $leg = $this->leg($order, $type);
            $this->assertSame(TaskStatus::Completed, $leg->status);
            $this->assertNull($leg->piece_count);
            $this->assertNull($leg->expected_piece_count);
            $this->assertNull($leg->expected_piece_source);
        }

        $this->assertSame(0, PieceDiscrepancy::count());
        $this->assertSame(0, TaskHold::count());
        $this->assertSame(0, $this->pieceNotices());

        // Nor do they reach what the collection will be held to: with no
        // review yet, that is the customer's own list, not a driver's number.
        $collection = $this->leg($order->fresh(), TaskType::CollectFromLaundry);
        $this->assertSame([1, PieceCountSource::CustomerOrder], app(PieceCheck::class)->expectedFor($collection));
    }

    #[Test]
    public function the_laundrys_review_has_no_handover_count_to_be_held_to_and_raises_nothing(): void
    {
        Notification::fake();
        $order = $this->atTheLaundry(ordered: 5);

        // Five ordered, four on the counting table: the laundry's count is the
        // one that stands, and there is no driver's count to set against it.
        $this->review($order, 4);

        $this->assertSame(0, PieceDiscrepancy::where('order_id', $order->id)->count());
        $this->assertSame(0, Order::withOpenPieceCheck()->count());
        $this->assertSame(0, $this->pieceNotices());
    }

    #[Test]
    public function the_collection_from_the_laundry_is_measured_against_its_review(): void
    {
        $order = $this->atTheLaundry(ordered: 2);

        // The laundry found three against the two ordered, and the customer agreed.
        $this->review($order, 3);
        $order = $this->agreed($order);

        // Three collected: the review's number, so it completes at once.
        $this->walk($order, TaskType::CollectFromLaundry, 3);

        $collection = $this->leg($order, TaskType::CollectFromLaundry);
        $this->assertSame(3, $collection->expected_piece_count);
        $this->assertSame(PieceCountSource::LaundryReview, $collection->expected_piece_source);
        $this->assertNull($collection->discrepancy);
        $this->assertSame(0, TaskHold::count());
    }

    #[Test]
    public function a_collection_that_disagrees_with_the_laundry_is_held_and_once_approved_leaves_one_closed_difference(): void
    {
        Notification::fake();
        $order = $this->atTheLaundry(ordered: 2);
        $this->review($order, 2);
        $order = $this->agreed($order);
        $collection = $this->startedLeg($order, TaskType::CollectFromLaundry);

        // Three taken out against the laundry's two: held, not completed — and
        // nothing recorded yet, because nobody has looked at it.
        $this->confirmLeg($collection, ['piece_count' => 3])
            ->assertStatus(409)
            ->assertJsonPath('key', 'awaiting_approval');

        $this->assertSame(TaskStatus::Started, $collection->fresh()->status);
        $this->assertSame(0, PieceDiscrepancy::count());

        $operator = $this->platformOperator();
        app(TaskService::class)->approveHold(TaskHold::sole(), $operator, 'a sock was inside a shirt at the review');

        $collection->refresh();
        $this->assertSame(TaskStatus::Completed, $collection->status);
        $this->assertSame(3, $collection->piece_count);
        $this->assertSame(2, $collection->expected_piece_count);

        // Recorded and closed in the same step, by whoever approved it.
        $discrepancy = PieceDiscrepancy::where('order_id', $order->id)->sole();
        $this->assertSame($collection->id, $discrepancy->order_task_id);
        $this->assertSame(PieceCheckStep::CollectFromLaundry, $discrepancy->step);
        $this->assertSame([3, 2], [$discrepancy->counted, $discrepancy->expected]);
        $this->assertFalse($discrepancy->open);
        $this->assertSame(3, $discrepancy->confirmed_count);
        $this->assertSame($operator->id, $discrepancy->resolved_by);
        $this->assertSame('a sock was inside a shirt at the review', $discrepancy->note);

        // Already looked at: no bell asking somebody to look, nothing in the
        // badge or the filter, and the order screen lists it as reviewed.
        $this->assertSame(0, $this->pieceNotices());
        $this->assertSame(0, Order::withOpenPieceCheck()->count());

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertDontSee(__('The piece count does not match'))
            ->assertSee(__('Piece count differences reviewed'))
            ->assertSee('a sock was inside a shirt at the review');

        // Approved twice is refused rather than recording it twice.
        try {
            app(TaskService::class)->approveHold(TaskHold::sole(), $operator);
            $this->fail('A hold was approved twice.');
        } catch (\RuntimeException $e) {
            $this->assertSame('nothing_to_decide', $e->getMessage());
        }

        $this->assertSame(1, PieceDiscrepancy::where('order_id', $order->id)->count());
    }

    #[Test]
    public function the_collection_is_held_to_what_the_platform_found_at_the_review(): void
    {
        Notification::fake();
        $order = $this->atTheLaundry(ordered: 5);
        $this->review($order, 4);

        // A difference at the review, as orders counted under the old rule
        // carry, and the platform looked: the laundry missed one, there are five.
        $discrepancy = PieceDiscrepancy::create([
            'order_id' => $order->id,
            'order_task_id' => null,
            'step' => PieceCheckStep::LaundryReview,
            'counted' => 4,
            'expected' => 5,
            'expected_source' => PieceCountSource::PreviousLeg,
            'counted_by' => $this->tenant['owner']->id,
        ]);
        app(PieceCheck::class)->resolve($discrepancy, $this->superAdmin(), 'found the fifth on the rack', 5);

        $order = $this->agreed($order);

        // Five collected is right — not held against the review's four.
        $this->walk($order, TaskType::CollectFromLaundry, 5);

        $collection = $this->leg($order, TaskType::CollectFromLaundry);
        $this->assertSame(5, $collection->expected_piece_count);
        $this->assertSame(PieceCountSource::Settled, $collection->expected_piece_source);
        $this->assertNull($collection->discrepancy);
        $this->assertSame(0, TaskHold::count());
        $this->assertSame(0, $this->pieceNotices());
    }

    // ------------------------------------------------------------ who hears

    #[Test]
    public function platform_staff_who_work_orders_hear_about_it_and_can_close_it(): void
    {
        Notification::fake();
        $this->grant('admin', ['order.view', 'order.update']);
        $moderator = $this->platformUser('+201000000071');
        // At the platform, but not somebody who works orders.
        Role::create(['name' => 'Support', 'slug' => 'support', 'type' => 'dashboard']);
        $this->grant('support', ['order.view']);
        $support = $this->platformUser('+201000000072', 'support');
        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        $order = $this->mismatched();
        $this->announce($order);

        $this->assertSame(1, $this->pieceNotices($moderator, (string) $order->code));
        $this->assertSame(0, $this->pieceNotices($support));
        // The order's laundry hears — they are its pieces — and no other.
        $this->assertSame(1, $this->pieceNotices($this->tenant['owner'], (string) $order->code));
        $this->assertSame(0, $this->pieceNotices($other['owner']));
        // Nor the driver who counted, or the customer.
        $this->assertSame(0, $this->pieceNotices($this->driver));
        $this->assertSame(0, $this->pieceNotices($this->customer));

        $discrepancy = $this->openDiscrepancy($order);
        $this->actingAs($moderator)
            ->post(route('admin.order.piece_check.resolve', $discrepancy->id), ['piece_check_note' => 'checked with the customer', 'piece_check_count' => 2])
            ->assertSessionHas('success');

        $this->assertSame($moderator->id, $discrepancy->fresh()->resolved_by);
    }

    #[Test]
    public function the_platform_is_told_in_the_panels_language_not_the_drivers(): void
    {
        Notification::fake();
        // The panel runs in Arabic; the request that raised it asked in English,
        // as a driver's phone does.
        Language::query()->update(['default' => 'false']);
        Language::where('code', 'ar')->update(['default' => 'true']);
        Cache::flush();

        $order = $this->mismatched();
        app()->setLocale('en');
        $this->announce($order);

        $titles = Notification::sent($this->superAdmin(), AdminNotification::class)
            ->map(fn (AdminNotification $n) => (string) ($n->toArray($this->superAdmin())['title'] ?? ''))
            ->filter(fn (string $title) => str_contains($title, (string) $order->code));

        $this->assertContains('اختلاف في عدد القطع — طلب #'.$order->code, $titles->all());
    }

    // ------------------------------------------------------------ the screens

    #[Test]
    public function the_order_says_so_at_the_top_and_offers_the_platform_the_review(): void
    {
        $order = $this->mismatched();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee(__('The piece count does not match'))
            ->assertSee(route('admin.order.piece_check.resolve', $this->openDiscrepancy($order)->id), false)
            ->assertSee(__('expected :count', ['count' => 1]));
    }

    #[Test]
    public function the_laundry_sees_it_but_cannot_close_it(): void
    {
        $order = $this->mismatched();
        $discrepancy = $this->openDiscrepancy($order);

        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee(__('The piece count does not match'))
            ->assertSee(__('The platform is looking into it.'))
            ->assertDontSee(route('admin.order.piece_check.resolve', $discrepancy->id), false);

        // Holding `order.update` by design is not enough: the pieces may have
        // gone missing at the laundry, and it is not the one to close that.
        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.order.piece_check.resolve', $discrepancy->id), ['piece_check_note' => 'all fine', 'piece_check_count' => 2])
            ->assertSessionHas('error');

        $this->assertTrue($discrepancy->fresh()->open);
    }

    #[Test]
    public function another_laundry_cannot_reach_it_at_all(): void
    {
        $order = $this->mismatched();
        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        $this->actingAs($other['owner'])
            ->post(route('admin.order.piece_check.resolve', $this->openDiscrepancy($order)->id), ['piece_check_note' => 'mine now', 'piece_check_count' => 2])
            ->assertNotFound();
    }

    #[Test]
    public function the_platform_closes_it_with_a_note_and_the_real_count(): void
    {
        $order = $this->mismatched();
        $discrepancy = $this->openDiscrepancy($order);
        $admin = $this->superAdmin();

        // No note, no review: closed without one, the next person learns only
        // that somebody pressed a button.
        $this->actingAs($admin)
            ->post(route('admin.order.piece_check.resolve', $discrepancy->id), ['piece_check_note' => '', 'piece_check_count' => 2])
            ->assertSessionHasErrors('piece_check_note');
        $this->assertTrue($discrepancy->fresh()->open);

        $this->actingAs($admin)
            ->post(route('admin.order.piece_check.resolve', $discrepancy->id), ['piece_check_note' => 'كلمت العميل — القطعتين طقم واحد', 'piece_check_count' => 2])
            ->assertSessionHas('success');

        $discrepancy->refresh();
        $this->assertFalse($discrepancy->open);
        $this->assertSame($admin->id, $discrepancy->resolved_by);
        $this->assertSame('كلمت العميل — القطعتين طقم واحد', $discrepancy->note);
        $this->assertSame(2, $discrepancy->confirmed_count);

        $this->actingAs($admin)
            ->get(route('admin.order.show', $order->id))
            ->assertDontSee(route('admin.order.piece_check.resolve', $discrepancy->id), false)
            ->assertSee(__('Piece count differences reviewed'))
            ->assertSee('كلمت العميل — القطعتين طقم واحد');

        // Twice is refused rather than rewriting who closed it.
        $this->actingAs($admin)
            ->post(route('admin.order.piece_check.resolve', $discrepancy->id), ['piece_check_note' => 'again', 'piece_check_count' => 2])
            ->assertSessionHas('error');

        // And it is in the activity log like any other change.
        $this->assertTrue(ActivityLog::where('subject_type', $discrepancy->getMorphClass())
            ->where('subject_id', $discrepancy->id)->where('event', 'updated')
            ->get()->contains(fn ($log) => array_key_exists('note', (array) $log->diff)));
    }

    #[Test]
    public function a_laundry_account_cannot_be_recorded_as_the_reviewer_even_with_nobody_signed_in(): void
    {
        $discrepancy = $this->openDiscrepancy($this->mismatched());

        // From a job or the console: nobody is signed in, so the tenant
        // context says nothing — the reviewer being recorded still decides.
        $this->app['auth']->forgetGuards();

        try {
            app(PieceCheck::class)->resolve($discrepancy, $this->tenant['owner'], 'fine', 2);
            $this->fail('A laundry account closed a piece count difference.');
        } catch (\RuntimeException $e) {
            $this->assertSame('platform_only', $e->getMessage());
        }

        $this->assertTrue($discrepancy->fresh()->open);
    }

    #[Test]
    public function the_sidebar_the_home_page_and_the_orders_filter_all_count_it_until_it_is_closed(): void
    {
        $order = $this->mismatched();
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $before = MenuBadges::for('order');
        $this->assertGreaterThanOrEqual(1, (int) $before);

        $queue = collect(app(DashboardSummary::class)->needsAPerson())->keyBy('key');
        $this->assertSame(1, $queue['piece_mismatch']['count']);
        $this->assertSame(OrderRepository::PIECE_MISMATCH, $queue['piece_mismatch']['params']['status']);

        $listed = app(OrderRepository::class)->search(null, OrderRepository::PIECE_MISMATCH);
        $this->assertSame([$order->id], collect($listed->items())->pluck('id')->all());
        $this->assertTrue((bool) $listed->items()[0]->piece_check_open);

        app(PieceCheck::class)->resolve($this->openDiscrepancy($order), $admin, 'checked', 2);

        $this->assertSame(((int) $before) - 1, (int) MenuBadges::for('order'));
        $this->assertArrayNotHasKey('piece_mismatch', collect(app(DashboardSummary::class)->needsAPerson())->keyBy('key')->all());
        $this->assertSame(0, app(OrderRepository::class)->search(null, OrderRepository::PIECE_MISMATCH)->total());
    }

    #[Test]
    public function an_order_with_two_open_disagreements_is_one_order_to_look_at(): void
    {
        // Two counted at the doorstep against one ordered, and the laundry
        // finding three against those two.
        $order = $this->mismatched();
        PieceDiscrepancy::create([
            'order_id' => $order->id,
            'order_task_id' => null,
            'step' => PieceCheckStep::LaundryReview,
            'counted' => 3,
            'expected' => 2,
            'expected_source' => PieceCountSource::PreviousLeg,
            'counted_by' => $this->tenant['owner']->id,
        ]);
        $this->assertSame(2, PieceDiscrepancy::open()->where('order_id', $order->id)->count());

        $this->actingAs($this->superAdmin());

        // The badge, the queue and the filter all speak in orders — a 1 that
        // opens a list of one.
        $this->assertSame(1, Order::withOpenPieceCheck()->count());
        $this->assertSame(1, collect(app(DashboardSummary::class)->needsAPerson())->keyBy('key')['piece_mismatch']['count']);
        $this->assertSame(1, app(OrderRepository::class)->search(null, OrderRepository::PIECE_MISMATCH)->total());
    }

    #[Test]
    public function a_laundry_counts_only_its_own(): void
    {
        $this->mismatched();
        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        // The owner of A sees it on their own home page and in the filter…
        $this->actingAs($this->tenant['owner']);
        $this->assertSame(1, collect(app(DashboardSummary::class)->laundryQueue())->keyBy('key')['piece_mismatch']['count']);
        $this->assertSame(1, app(OrderRepository::class)->search(null, OrderRepository::PIECE_MISMATCH)->total());

        // …the owner of B, through the same tenant scope, does not.
        $this->app['auth']->forgetGuards();
        $this->actingAs($other['owner']);
        $this->assertArrayNotHasKey('piece_mismatch', collect(app(DashboardSummary::class)->laundryQueue())->keyBy('key')->all());
        $this->assertSame(0, app(OrderRepository::class)->search(null, OrderRepository::PIECE_MISMATCH)->total());
        $this->assertNull(MenuBadges::for('order'));
    }

    #[Test]
    public function the_orders_list_marks_the_row(): void
    {
        $order = $this->mismatched();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.index'))
            ->assertOk()
            ->assertSee(__('Piece count differs'));

        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.index', ['status' => OrderRepository::PIECE_MISMATCH]))
            ->assertOk()
            ->assertSee('#'.$order->code);
    }

    // ------------------------------------------------------------ the driver app

    #[Test]
    public function the_app_is_asked_for_a_count_only_at_the_collection_from_the_laundry(): void
    {
        // Walked to «خلص الغسيل» (item 18, 2026-11-08) and the collection given
        // to the driver: before it, that leg is nobody's to open.
        $order = $this->atTheLaundry(ordered: 1);
        $this->review($order, 1);
        $order = $this->agreed($order);
        app(DriverDispatcher::class)->assign($this->leg($order, TaskType::CollectFromLaundry), $this->driver);

        foreach ([
            TaskType::PickupFromCustomer->value => false,
            TaskType::DeliverToLaundry->value => false,
            TaskType::CollectFromLaundry->value => true,
            TaskType::DeliverToCustomer->value => false,
        ] as $type => $asked) {
            $this->taskScreen($this->leg($order, TaskType::from($type)))
                ->assertJsonPath('data.requires_piece_count', $asked);
        }
    }

    #[Test]
    public function the_driver_is_not_shown_the_expected_number_until_they_have_counted(): void
    {
        $order = $this->atTheLaundry(ordered: 2);
        $this->review($order, 2);
        $order = $this->agreed($order);
        $collection = $this->startedLeg($order, TaskType::CollectFromLaundry);

        // Shown the laundry's number first, a driver copies it instead of counting.
        $this->taskScreen($collection)->assertJsonPath('data.expected_pieces', null);

        $this->confirmLeg($collection, ['piece_count' => 3])->assertStatus(409);
        app(TaskService::class)->approveHold(TaskHold::sole(), $this->platformOperator());

        // Once it is in, the app can say what it was held to.
        $this->taskScreen($collection)
            ->assertJsonPath('data.expected_pieces', 2)
            ->assertJsonPath('data.piece_count', 3);
    }

    #[Test]
    public function the_delivery_leg_does_not_give_the_count_away_before_the_laundry_hands_it_back(): void
    {
        // One driver holds all four legs; the delivery's count before then is
        // exactly what the collection is held to.
        $order = $this->placedOrder(qty: 1);
        $delivery = $this->leg($order, TaskType::DeliverToCustomer);

        $this->taskScreen($delivery)->assertJsonPath('data.expected_pieces', null);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Piece-count notices sent — to one person, or to anybody. Other panel
     * notices go out along the way (an order placed, a leg assigned, a hold),
     * so these are picked out by their title.
     */
    private function pieceNotices(?object $to = null, ?string $code = null): int
    {
        $recipients = $to ? [$to] : User::all()->all();
        $count = 0;

        foreach ($recipients as $user) {
            $count += Notification::sent($user, AdminNotification::class, function (AdminNotification $notice) use ($user, $code) {
                $title = (string) ($notice->toArray($user)['title'] ?? '');

                return str_starts_with($title, 'Piece count does not match')
                    && ($code === null || str_contains($title, $code));
            })->count();
        }

        return $count;
    }

    /**
     * An open difference of the kind orders counted before 2026-11-08 carry —
     * one ordered, two counted at the doorstep (the owner's screenshot). Made
     * directly: no count raises an open one any more, since the collection's
     * is held and closed by whoever approves it.
     */
    private function mismatched(): Order
    {
        $order = $this->placedOrder(qty: 1);
        $pickup = $this->leg($order, TaskType::PickupFromCustomer);
        $pickup->forceFill(['piece_count' => 2])->save();

        PieceDiscrepancy::create([
            'order_id' => $order->id,
            'order_task_id' => $pickup->id,
            'step' => PieceCheckStep::PickupFromCustomer,
            'counted' => 2,
            'expected' => 1,
            'expected_source' => PieceCountSource::CustomerOrder,
            'counted_by' => $this->driver->id,
        ]);

        return $order->fresh();
    }

    /**
     * The bell, rung for the order's open difference the way the review rings
     * it after its transaction commits.
     */
    private function announce(Order $order): void
    {
        app(PieceCountNotifier::class)->mismatch($this->openDiscrepancy($order));
    }

    private function openDiscrepancy(Order $order): PieceDiscrepancy
    {
        return PieceDiscrepancy::open()->where('order_id', $order->id)->firstOrFail();
    }

    private function platformUser(string $phone, string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Staff '.$phone, 'email' => ltrim($phone, '+').'@test.local', 'phone' => $phone,
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', $role)->value('id'),
        ]);
    }

    private function placedOrder(int $qty): Order
    {
        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => $qty]],
            'accepts_review_terms' => true,
        ]);
    }

    /**
     * Collected and handed to the laundry, as the app does it now — no count.
     */
    private function atTheLaundry(int $ordered): Order
    {
        $order = $this->placedOrder(qty: $ordered);
        $this->walk($order, TaskType::PickupFromCustomer, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry);

        return $order->fresh();
    }

    /**
     * The laundry's count sent on by the platform and agreed by the customer —
     * with the pieces already at the laundry, straight to cleaning — and the
     * laundry's «خلص الغسيل» (item 18, 2026-11-08), which is when the
     * collection may start.
     */
    private function agreed(Order $order): Order
    {
        $this->sendReview($order);
        app(OrderReviewService::class)->confirm($order->fresh(), $this->customer);
        $this->assertSame(OrderStatus::Cleaning, $order->fresh()->status);

        return $this->finishWashing($order);
    }

    private function review(Order $order, int $qty): void
    {
        app(OrderReviewService::class)->review(
            $order->fresh(),
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => $qty]],
            null,
            $this->tenant['owner'],
        );
    }

    private function leg(Order $order, TaskType $type): OrderTask
    {
        return OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();
    }

    private function startedLeg(Order $order, TaskType $type): OrderTask
    {
        $task = $this->leg($order, $type);

        if ($task->driver_id === null) {
            app(DriverDispatcher::class)->assign($task, $this->driver);
            $task->refresh();
        }

        return app(TaskService::class)->start($task, $this->driver);
    }

    /**
     * Start a leg and confirm it through the driver API, expecting it done.
     */
    private function walk(Order $order, TaskType $type, ?int $pieces = null, bool $signed = false): void
    {
        $task = $this->startedLeg($order, $type);

        $data = $pieces === null ? [] : ['piece_count' => $pieces];

        if ($signed) {
            $data['signature'] = UploadedFile::fake()->image('sig.png');
        }

        $this->confirmLeg($task, $data)->assertOk();
    }

    /**
     * «تأكيد», as the driver app sends it.
     *
     * @param  array<string, mixed>  $data
     */
    private function confirmLeg(OrderTask $task, array $data): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->driver);

        $response = $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", $data, $this->apiHeaders());

        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function taskScreen(OrderTask $task): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->driver);

        return $this->getJson("/api/v1/driver/tasks/{$task->id}", $this->apiHeaders())->assertOk();
    }
}
