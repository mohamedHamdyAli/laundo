<?php

namespace Tests\Feature\Api;

use App\Modules\Complaint\Enums\ComplaintStatus;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Complaint\Services\ComplaintService;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The owner's decisions, built.
 *
 * Three of them here — the complaint acknowledgement, the 24-hour nudge on a
 * silent price confirmation, and the per-subject notification cap. Each was an
 * assumption I chose and the owner then decided differently or confirmed.
 *
 * The cap gets the most attention, because a rate limit that silences the wrong
 * message is worse than no rate limit: a customer whose third notification of the
 * hour is «السعر النهائي جاهز» must still receive it, or their order stops and
 * nobody knows why.
 */
class DecisionsTest extends TestCase
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

    private function order(?OrderStatus $status = null): Order
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
        ] + ($status ? ['status' => $status->value] : []))->save();

        return $order->fresh();
    }

    private function complaint(): Complaint
    {
        return app(ComplaintService::class)->submit($this->customer, [
            'category' => 'other',
            'body' => 'something went wrong here',
        ]);
    }

    // ============================================ 1. the complaint acknowledgement

    #[Test]
    public function resolving_a_complaint_tells_the_complainant(): void
    {
        $admin = $this->superAdmin();
        $complaint = $this->complaint();

        app(ComplaintService::class)->transition($complaint, ComplaintStatus::Resolved, $admin);

        $log = NotificationLog::where('event', NotificationEvent::ComplaintClosed->value)
            ->where('user_id', $this->customer->id)
            ->first();

        $this->assertNotNull($log, 'the complainant was never told');
        // The reference is what they quote if they call back.
        $this->assertStringContainsString($complaint->reference, (string) $log->body);
    }

    #[Test]
    public function resolved_and_closed_are_worded_differently(): void
    {
        $admin = $this->superAdmin();

        $resolved = $this->complaint();
        app(ComplaintService::class)->transition($resolved, ComplaintStatus::Resolved, $admin);

        $closed = $this->complaint();
        app(ComplaintService::class)->transition($closed, ComplaintStatus::Closed, $admin);

        $titles = NotificationLog::where('event', NotificationEvent::ComplaintClosed->value)
            ->pluck('title')
            ->unique();

        // "We sorted it out" and "we looked and decided not to act" are not the
        // same message, and sending the first for the second invites a second
        // complaint.
        $this->assertCount(2, $titles);
    }

    #[Test]
    public function moving_a_complaint_to_in_progress_tells_nobody(): void
    {
        $admin = $this->superAdmin();
        $complaint = $this->complaint();

        app(ComplaintService::class)->transition($complaint, ComplaintStatus::InProgress, $admin);

        // Picking a complaint up is not news to the complainant. Only finishing is.
        $this->assertSame(
            0,
            NotificationLog::where('event', NotificationEvent::ComplaintClosed->value)->count()
        );
    }

    #[Test]
    public function a_failing_acknowledgement_does_not_undo_the_decision(): void
    {
        $admin = $this->superAdmin();
        $complaint = $this->complaint();

        // A dispatcher that throws — a Firebase outage, a database hiccup mid-send.
        // The decision has already been recorded and must stand; the message is a
        // courtesy, and losing the decision to save it would be the wrong trade.
        $this->app->bind(NotificationDispatcher::class, function () {
            return new class extends NotificationDispatcher
            {
                public function __construct() {}

                public function send(User $user, NotificationMessage $message): array
                {
                    throw new \RuntimeException('the notification vendor is down');
                }
            };
        });

        app(ComplaintService::class)->transition($complaint, ComplaintStatus::Resolved, $admin);

        $this->assertSame(ComplaintStatus::Resolved->value, $complaint->fresh()->status);
    }

    // ================================================ 2. the 24-hour silence nudge

    #[Test]
    public function an_order_waiting_a_day_for_a_price_confirmation_nudges_both_sides(): void
    {
        $this->superAdmin();

        $order = $this->order(OrderStatus::Reviewed);
        $order->forceFill(['updated_at' => now()->subDays(2)])->save();

        $this->artisan('orders:alert-silent-confirmations')->assertSuccessful();

        // The customer, because they are the only one who can end the wait.
        $this->assertSame(
            1,
            NotificationLog::where('event', NotificationEvent::FinalPriceReady->value)
                ->where('user_id', $this->customer->id)
                ->where('channel', 'database')
                ->count()
        );

        // And operations, so somebody can call.
        $this->assertSame(
            1,
            NotificationLog::where('event', NotificationEvent::PriceConfirmationSilent->value)
                ->where('channel', 'database')
                ->count()
        );
    }

    #[Test]
    public function an_order_that_has_only_just_been_priced_is_left_alone(): void
    {
        $this->superAdmin();

        $this->order(OrderStatus::Reviewed);

        $this->artisan('orders:alert-silent-confirmations')->assertSuccessful();

        $this->assertSame(
            0,
            NotificationLog::where('event', NotificationEvent::PriceConfirmationSilent->value)->count()
        );
    }

    #[Test]
    public function the_nudge_is_sent_once_per_order_ever(): void
    {
        $this->superAdmin();

        $order = $this->order(OrderStatus::Reviewed);
        $order->forceFill(['updated_at' => now()->subDays(2)])->save();

        // Hourly schedule, so this runs again and again while the order waits. An
        // alert that repeats teaches people to ignore it, and then the one that
        // mattered is ignored too.
        $this->artisan('orders:alert-silent-confirmations')->assertSuccessful();
        $this->artisan('orders:alert-silent-confirmations')->assertSuccessful();
        $this->artisan('orders:alert-silent-confirmations')->assertSuccessful();

        $this->assertSame(
            1,
            NotificationLog::where('event', NotificationEvent::PriceConfirmationSilent->value)
                ->where('channel', 'database')
                ->count()
        );
    }

    #[Test]
    public function nothing_is_confirmed_or_cancelled_automatically(): void
    {
        $this->superAdmin();

        $order = $this->order(OrderStatus::Reviewed);
        $order->forceFill(['updated_at' => now()->subDays(5)])->save();

        $this->artisan('orders:alert-silent-confirmations')->assertSuccessful();

        // The decision was to nudge and take no automatic action. Agreeing to a
        // price on somebody's behalf is a dispute waiting to happen.
        $this->assertSame(OrderStatus::Reviewed, $order->fresh()->status);
    }

    #[Test]
    public function an_order_in_any_other_state_is_not_a_candidate(): void
    {
        $this->superAdmin();

        foreach ([OrderStatus::Cleaning, OrderStatus::Confirmed, OrderStatus::Delivered] as $status) {
            $order = $this->order($status);
            $order->forceFill(['updated_at' => now()->subDays(5)])->save();
        }

        $this->artisan('orders:alert-silent-confirmations')->assertSuccessful();

        // `reviewed` is the only status that waits on a customer.
        $this->assertSame(
            0,
            NotificationLog::where('event', NotificationEvent::PriceConfirmationSilent->value)->count()
        );
    }

    // ==================================================== 3. the notification cap

    /**
     * Clear the log so a test measures only what it sends itself.
     *
     * Placing an order announces itself, and that announcement counts toward the
     * cap — correctly, but it is not what these tests are about.
     */
    private function forgetEarlierNotifications(): void
    {
        NotificationLog::query()->delete();
    }

    private function nudge(User $user, Order $order, NotificationEvent $event): void
    {
        app(NotificationDispatcher::class)->send($user, new NotificationMessage(
            event: $event,
            title: 'Something happened',
            body: 'A stage changed',
            subject: $order,
        ));
    }

    #[Test]
    public function a_fourth_message_about_the_same_order_within_an_hour_is_held(): void
    {
        $order = $this->order();
        $this->forgetEarlierNotifications();

        foreach ([
            NotificationEvent::OrderPlaced,
            NotificationEvent::DriverOnWay,
            NotificationEvent::PriceConfirmed,
        ] as $event) {
            $this->nudge($this->customer, $order, $event);
        }

        $this->nudge($this->customer, $order, NotificationEvent::OrderReadyForDelivery);

        $sent = NotificationLog::where('user_id', $this->customer->id)
            ->where('channel', 'database')
            ->where('status', NotificationLog::SENT)
            ->count();

        // Three through, the fourth held. An order that moves three stages in a
        // minute used to send three notifications with nothing limiting anything.
        $this->assertSame(3, $sent);

        $held = NotificationLog::where('status', NotificationLog::SKIPPED)
            ->where('failure_reason', 'like', '%rate limited%')
            ->count();

        // Logged as a skip, not dropped silently — a held notification has to be
        // findable when somebody asks why it never arrived.
        $this->assertGreaterThan(0, $held);
    }

    #[Test]
    public function a_transactional_message_is_never_held(): void
    {
        $order = $this->order();
        $this->forgetEarlierNotifications();

        foreach ([
            NotificationEvent::OrderPlaced,
            NotificationEvent::DriverOnWay,
            NotificationEvent::PriceConfirmed,
        ] as $event) {
            $this->nudge($this->customer, $order, $event);
        }

        // The one that stops the order dead when it is missed.
        $this->nudge($this->customer, $order, NotificationEvent::FinalPriceReady);

        $this->assertSame(
            1,
            NotificationLog::where('event', NotificationEvent::FinalPriceReady->value)
                ->where('status', NotificationLog::SENT)
                ->where('channel', 'database')
                ->count()
        );
    }

    #[Test]
    public function the_cap_is_per_order_and_not_per_person(): void
    {
        $first = $this->order();
        $second = $this->order();
        $this->forgetEarlierNotifications();

        foreach ([
            NotificationEvent::OrderPlaced,
            NotificationEvent::DriverOnWay,
            NotificationEvent::PriceConfirmed,
        ] as $event) {
            $this->nudge($this->customer, $first, $event);
        }

        // A different order is a different conversation. Silencing it because the
        // first one is busy would be worse than the noise.
        $this->nudge($this->customer, $second, NotificationEvent::OrderPlaced);

        $this->assertSame(
            1,
            NotificationLog::where('subject_id', $second->id)
                ->where('channel', 'database')
                ->where('status', NotificationLog::SENT)
                ->count()
        );
    }

    #[Test]
    public function a_message_with_no_subject_is_never_held(): void
    {
        $order = $this->order();
        $this->forgetEarlierNotifications();

        foreach ([
            NotificationEvent::OrderPlaced,
            NotificationEvent::DriverOnWay,
            NotificationEvent::PriceConfirmed,
        ] as $event) {
            $this->nudge($this->customer, $order, $event);
        }

        // Nothing to group it by, and guessing would silence unrelated things
        // together.
        app(NotificationDispatcher::class)->send($this->customer, new NotificationMessage(
            event: NotificationEvent::OrderReadyForDelivery,
            title: 'No subject at all',
            body: 'Ungrouped',
        ));

        $this->assertSame(
            1,
            NotificationLog::whereNull('subject_id')
                ->where('channel', 'database')
                ->where('status', NotificationLog::SENT)
                ->count()
        );
    }

    #[Test]
    public function an_hour_later_the_cap_has_moved_on(): void
    {
        $order = $this->order();
        $this->forgetEarlierNotifications();

        foreach ([
            NotificationEvent::OrderPlaced,
            NotificationEvent::DriverOnWay,
            NotificationEvent::PriceConfirmed,
        ] as $event) {
            $this->nudge($this->customer, $order, $event);
        }

        // Age the window out rather than sleeping.
        NotificationLog::query()->update(['created_at' => now()->subHours(2)]);

        $this->nudge($this->customer, $order, NotificationEvent::OrderReadyForDelivery);

        $this->assertSame(
            1,
            NotificationLog::where('event', NotificationEvent::OrderReadyForDelivery->value)
                ->where('status', NotificationLog::SENT)
                ->where('channel', 'database')
                ->count()
        );
    }

    #[Test]
    public function the_cap_can_be_turned_off(): void
    {
        config()->set('push.rate_limit_per_hour', 0);

        $order = $this->order();
        $this->forgetEarlierNotifications();

        foreach ([
            NotificationEvent::OrderPlaced,
            NotificationEvent::DriverOnWay,
            NotificationEvent::PriceConfirmed,
            NotificationEvent::OrderReadyForDelivery,
        ] as $event) {
            $this->nudge($this->customer, $order, $event);
        }

        $this->assertSame(
            4,
            NotificationLog::where('channel', 'database')
                ->where('status', NotificationLog::SENT)
                ->count()
        );
    }
}
