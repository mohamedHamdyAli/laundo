<?php

namespace Tests\Feature\Dashboard;

use App\Models\ActivityLog;
use App\Models\Language;
use App\Models\Role;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\PieceCheckStep;
use App\Modules\Order\Enums\PieceCountSource;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\PieceDiscrepancy;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
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
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «عدد القطع المستلمة», checked against the count before it.
 *
 * The count was stored and compared with nothing — a customer ordering one
 * piece and a driver counting two raised no flag anywhere. The owner's rules:
 * each count is measured against the last number somebody stood behind (the
 * customer's order, the handover before, what was handed to the laundry, the
 * laundry's review); the platform and the order's laundry are both told; it
 * stays open until somebody at the platform reviews it with a note and the
 * real count; the handover is never refused; and the driver is not shown the
 * expected number until they have counted.
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
    public function counts_that_agree_raise_nothing_and_record_what_they_were_held_to(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 2);

        $this->walk($order, TaskType::PickupFromCustomer, 2, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 2);

        $pickup = $this->leg($order, TaskType::PickupFromCustomer);
        $handover = $this->leg($order, TaskType::DeliverToLaundry);

        $this->assertSame(2, $pickup->expected_piece_count);
        $this->assertSame(PieceCountSource::CustomerOrder, $pickup->expected_piece_source);
        $this->assertSame(2, $handover->expected_piece_count);
        $this->assertSame(PieceCountSource::PreviousLeg, $handover->expected_piece_source);

        $this->assertSame(0, PieceDiscrepancy::count());
        $this->assertSame(0, $this->pieceNotices());
    }

    #[Test]
    public function the_screenshot_case_one_ordered_two_counted_is_raised_with_the_platform_and_the_laundry(): void
    {
        Notification::fake();
        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');
        $order = $this->placedOrder(qty: 1);

        $this->walk($order, TaskType::PickupFromCustomer, 2, signed: true);

        $discrepancy = $this->leg($order, TaskType::PickupFromCustomer)->discrepancy;
        $this->assertNotNull($discrepancy);
        $this->assertTrue($discrepancy->open);
        $this->assertSame(PieceCheckStep::PickupFromCustomer, $discrepancy->step);
        $this->assertSame([2, 1], [$discrepancy->counted, $discrepancy->expected]);
        $this->assertSame($this->driver->id, $discrepancy->counted_by);

        $this->assertSame(1, $this->pieceNotices($this->superAdmin(), (string) $order->code));
        $this->assertSame(1, $this->pieceNotices($this->tenant['owner'], (string) $order->code));
        // Another laundry never hears about this one's pieces.
        $this->assertSame(0, $this->pieceNotices($other['owner']));
        // Nor does the driver who counted, or the customer.
        $this->assertSame(0, $this->pieceNotices($this->driver));
        $this->assertSame(0, $this->pieceNotices($this->customer));
    }

    #[Test]
    public function the_handover_to_the_laundry_is_measured_against_the_pickup_not_the_order(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 1);

        // Collected three against one ordered: raised once, at the pickup.
        $this->walk($order, TaskType::PickupFromCustomer, 3, signed: true);
        $this->assertSame(2, $this->pieceNotices()); // super admin + owner

        // Three handed over: the same pieces, nothing new to say.
        $this->walk($order, TaskType::DeliverToLaundry, 3);
        $this->assertSame(2, $this->pieceNotices());

        $handover = $this->leg($order, TaskType::DeliverToLaundry);
        $this->assertSame(3, $handover->expected_piece_count);
        $this->assertNull($handover->discrepancy);
    }

    #[Test]
    public function a_piece_lost_between_the_doorstep_and_the_laundry_is_named_at_that_step(): void
    {
        $order = $this->placedOrder(qty: 3);

        $this->walk($order, TaskType::PickupFromCustomer, 3, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 2);

        $discrepancy = $this->leg($order, TaskType::DeliverToLaundry)->discrepancy;
        $this->assertSame(PieceCheckStep::DeliverToLaundry, $discrepancy->step);
        $this->assertSame([2, 3], [$discrepancy->counted, $discrepancy->expected]);
        $this->assertSame(PieceCountSource::PreviousLeg, $discrepancy->expected_source);
        $this->assertNull($this->leg($order, TaskType::PickupFromCustomer)->discrepancy);
    }

    #[Test]
    public function a_count_the_review_corrected_is_what_the_next_handover_is_held_to(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 2);

        // A typo at the doorstep: three typed, two collected.
        $this->walk($order, TaskType::PickupFromCustomer, 3, signed: true);
        $discrepancy = $this->leg($order, TaskType::PickupFromCustomer)->discrepancy;
        app(PieceCheck::class)->resolve($discrepancy, $this->superAdmin(), 'called the customer — two pieces, the driver mistyped', 2);
        $this->assertSame(2, $this->pieceNotices());

        // Two handed over is right, and says nothing new.
        $this->walk($order, TaskType::DeliverToLaundry, 2);

        $handover = $this->leg($order, TaskType::DeliverToLaundry);
        $this->assertSame(2, $handover->expected_piece_count);
        $this->assertNull($handover->discrepancy);
        $this->assertSame(2, $this->pieceNotices());
    }

    #[Test]
    public function the_laundry_counting_fewer_than_it_was_handed_is_raised_at_the_review(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 5);
        $this->walk($order, TaskType::PickupFromCustomer, 5, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 5);

        // Five handed over, four found on the counting table.
        $this->review($order, 4);

        $discrepancy = PieceDiscrepancy::where('order_id', $order->id)->sole();
        $this->assertSame(PieceCheckStep::LaundryReview, $discrepancy->step);
        $this->assertNull($discrepancy->order_task_id);
        $this->assertSame([4, 5], [$discrepancy->counted, $discrepancy->expected]);
        $this->assertSame($this->tenant['owner']->id, $discrepancy->counted_by);
        $this->assertTrue($discrepancy->open);

        $this->assertSame(1, $this->pieceNotices($this->superAdmin(), (string) $order->code));
        $this->assertSame(1, $this->pieceNotices($this->tenant['owner'], (string) $order->code));
    }

    #[Test]
    public function a_review_that_agrees_with_the_handover_raises_nothing(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 2);
        $this->walk($order, TaskType::PickupFromCustomer, 3, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 3);

        // The customer said two, the drivers carried three, the laundry counts
        // three: the disagreement was at the doorstep, and only there.
        $this->review($order, 3);

        $this->assertSame(1, PieceDiscrepancy::where('order_id', $order->id)->count());
        $this->assertSame(PieceCheckStep::PickupFromCustomer, PieceDiscrepancy::where('order_id', $order->id)->sole()->step);
    }

    #[Test]
    public function a_second_review_that_finds_the_piece_closes_it(): void
    {
        $order = $this->placedOrder(qty: 5);
        $this->walk($order, TaskType::PickupFromCustomer, 5, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 5);
        $this->review($order, 4);

        // The customer disputed the count, the laundry recounted and found it.
        app(OrderReviewService::class)->dispute($order->fresh(), $this->customer, 'there were five');
        $this->review($order, 5);

        $discrepancy = PieceDiscrepancy::where('order_id', $order->id)->sole();
        $this->assertFalse($discrepancy->open);
        $this->assertSame(5, $discrepancy->confirmed_count);
        $this->assertNull($discrepancy->resolved_by);
        $this->assertSame(0, Order::withOpenPieceCheck()->count());
    }

    #[Test]
    public function the_collection_from_the_laundry_is_measured_against_its_review(): void
    {
        $order = $this->placedOrder(qty: 2);
        $this->walk($order, TaskType::PickupFromCustomer, 2, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 2);

        // The laundry found three — a discrepancy at the review — and the
        // customer agreed.
        $this->review($order, 3);
        app(OrderReviewService::class)->confirm($order->fresh(), $this->customer);
        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');

        // Three collected: the review's number, so nothing new to raise.
        $this->walk($order->fresh(), TaskType::CollectFromLaundry, 3);

        $collection = $this->leg($order, TaskType::CollectFromLaundry);
        $this->assertSame(3, $collection->expected_piece_count);
        $this->assertSame(PieceCountSource::LaundryReview, $collection->expected_piece_source);
        $this->assertNull($collection->discrepancy);
    }

    #[Test]
    public function the_collection_falls_back_to_the_handover_when_the_laundry_has_not_reviewed(): void
    {
        $order = $this->placedOrder(qty: 2);
        $this->walk($order, TaskType::PickupFromCustomer, 2, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 2);

        $collection = $this->leg($order->fresh(), TaskType::CollectFromLaundry);
        $this->assertNull($collection->order->final_items_count);

        $this->assertSame([2, PieceCountSource::PreviousLeg], app(PieceCheck::class)->expectedFor($collection));
    }

    #[Test]
    public function a_service_priced_after_inspection_has_nothing_to_hold_the_first_count_to(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 1);
        // No pieces listed: the pickup count is the first number anybody has.
        $order->forceFill(['estimated_items_count' => 0])->save();

        $this->walk($order, TaskType::PickupFromCustomer, 7, signed: true);

        $pickup = $this->leg($order, TaskType::PickupFromCustomer);
        $this->assertNull($pickup->expected_piece_count);
        $this->assertNull($pickup->discrepancy);
        $this->assertSame(0, $this->pieceNotices());
    }

    #[Test]
    public function a_review_entered_before_the_handover_is_confirmed_is_checked_when_it_is(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 5);
        $this->walk($order, TaskType::PickupFromCustomer, 5, signed: true);

        // The laundry counts the bag while the driver is still on the app:
        // nothing handed over yet to hold the review to.
        $this->review($order, 4);
        $this->assertSame(0, PieceDiscrepancy::where('order_id', $order->id)->count());

        // Five handed over — the handover agrees with the pickup, the review
        // does not agree with the handover.
        $this->walk($order->fresh(), TaskType::DeliverToLaundry, 5);

        $discrepancy = PieceDiscrepancy::where('order_id', $order->id)->sole();
        $this->assertSame(PieceCheckStep::LaundryReview, $discrepancy->step);
        $this->assertSame([4, 5], [$discrepancy->counted, $discrepancy->expected]);
        $this->assertSame(2, $this->pieceNotices());
    }

    #[Test]
    public function the_collection_is_held_to_what_the_platform_found_at_the_review(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 5);
        $this->walk($order, TaskType::PickupFromCustomer, 5, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 5);
        $this->review($order, 4);

        // The platform looked: the laundry missed one, there are five.
        app(PieceCheck::class)->resolve($this->openDiscrepancy($order), $this->superAdmin(), 'found the fifth on the rack', 5);

        app(OrderReviewService::class)->confirm($order->fresh(), $this->customer);
        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');

        // Five collected is right — not a second alert against the review's four.
        $this->walk($order->fresh(), TaskType::CollectFromLaundry, 5);

        $collection = $this->leg($order, TaskType::CollectFromLaundry);
        $this->assertSame(5, $collection->expected_piece_count);
        $this->assertSame(PieceCountSource::Settled, $collection->expected_piece_source);
        $this->assertNull($collection->discrepancy);
        $this->assertSame(2, $this->pieceNotices());
    }

    #[Test]
    public function a_second_review_is_held_to_what_the_platform_settled(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 5);
        $this->walk($order, TaskType::PickupFromCustomer, 5, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 5);
        $this->review($order, 4);

        // The platform: really four, the driver miscounted.
        app(PieceCheck::class)->resolve($this->openDiscrepancy($order), $this->superAdmin(), 'driver miscounted', 4);

        // The customer disputes and the laundry recounts four again: settled,
        // nothing new to raise.
        app(OrderReviewService::class)->dispute($order->fresh(), $this->customer, 'check again');
        $this->review($order, 4);

        $this->assertSame(1, PieceDiscrepancy::where('order_id', $order->id)->count());
        $this->assertSame(0, Order::withOpenPieceCheck()->count());
        $this->assertSame(2, $this->pieceNotices());
    }

    #[Test]
    public function the_same_handover_confirmed_twice_is_completed_once(): void
    {
        Notification::fake();
        $order = $this->placedOrder(qty: 1);
        $this->walk($order, TaskType::PickupFromCustomer, 2, signed: true);

        $pickup = $this->leg($order, TaskType::PickupFromCustomer);
        Sanctum::actingAs($this->driver);

        // A retry of the same «تأكيد» after it went through.
        $this->postJson("/api/v1/driver/tasks/{$pickup->id}/complete", ['piece_count' => 2], $this->apiHeaders())
            ->assertStatus(400);

        $this->assertSame(1, PieceDiscrepancy::where('order_id', $order->id)->count());
        $this->assertSame(2, $this->pieceNotices());
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

        $order = $this->mismatched();

        $this->assertSame(1, $this->pieceNotices($moderator, (string) $order->code));
        $this->assertSame(0, $this->pieceNotices($support));

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
        // The panel runs in Arabic; the driver's phone asks in English.
        Language::query()->update(['default' => 'false']);
        Language::where('code', 'ar')->update(['default' => 'true']);
        Cache::flush();

        $order = $this->mismatched();

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
        $order = $this->placedOrder(qty: 1);
        // Two counted against one ordered, then three against the two collected.
        $this->walk($order, TaskType::PickupFromCustomer, 2, signed: true);
        $this->walk($order, TaskType::DeliverToLaundry, 3);
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
    public function the_driver_is_not_shown_the_expected_number_until_they_have_counted(): void
    {
        $order = $this->placedOrder(qty: 1);
        $pickup = $this->leg($order, TaskType::PickupFromCustomer);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->driver);

        // Shown the number first, a driver copies it instead of counting.
        $this->getJson("/api/v1/driver/tasks/{$pickup->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.expected_pieces', null);

        $this->walk($order, TaskType::PickupFromCustomer, 3, signed: true);

        // Once it is in, the app can say the office has been told.
        Sanctum::actingAs($this->driver);
        $this->getJson("/api/v1/driver/tasks/{$pickup->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.expected_pieces', 1)
            ->assertJsonPath('data.piece_count', 3);

        // The next leg, not yet counted, is held back too.
        $handover = $this->leg($order, TaskType::DeliverToLaundry);
        $this->getJson("/api/v1/driver/tasks/{$handover->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.expected_pieces', null);
    }

    #[Test]
    public function the_delivery_leg_does_not_give_the_count_away_before_the_laundry_hands_it_back(): void
    {
        // One driver holds all four legs; the delivery's count before then is
        // exactly what the pickup is held to.
        $order = $this->placedOrder(qty: 1);
        $delivery = $this->leg($order, TaskType::DeliverToCustomer);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->driver);

        $this->getJson("/api/v1/driver/tasks/{$delivery->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.expected_pieces', null);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Piece-count notices sent — to one person, or to anybody. Other panel
     * notices go out along the way (an order placed, a leg assigned), so these
     * are picked out by their title.
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
     * One ordered, two counted at the doorstep — the owner's screenshot.
     */
    private function mismatched(): Order
    {
        $order = $this->placedOrder(qty: 1);
        $this->walk($order, TaskType::PickupFromCustomer, 2, signed: true);

        return $order->fresh();
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

    private function walk(Order $order, TaskType $type, int $pieces, bool $signed = false): void
    {
        $task = $this->leg($order, $type);

        if ($task->driver_id === null) {
            app(DriverDispatcher::class)->assign($task, $this->driver);
            $task->refresh();
        }

        app(TaskService::class)->start($task, $this->driver);

        $data = ['piece_count' => $pieces];

        if ($signed) {
            $data['signature'] = UploadedFile::fake()->image('sig.png');
        }

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->driver);

        $this->postJson("/api/v1/driver/tasks/{$task->id}/complete", $data, $this->apiHeaders())
            ->assertOk();

        $this->app['auth']->forgetGuards();
    }
}
