<?php

namespace Tests\Feature\Api;

use App\Modules\Driver\Models\Driver;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\DeviceToken;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\RecurrenceService;
use App\Modules\User\Models\User;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telling people things.
 *
 * Three claims these tests exist to protect:
 *
 *  - **A failed notification never breaks what triggered it.** A Firebase outage
 *    must not roll back a delivery a driver has already made.
 *  - **A muted user still gets transactional messages.** The design's «الإشعارات»
 *    toggle silences noise; it cannot silence «السعر النهائي جاهز», or the order
 *    stalls and the customer never learns why.
 *  - **Everything is logged, including the skips**, because "I never got it" is
 *    otherwise unanswerable.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private array $tenant;

    private User $customer;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    // ------------------------------------------------------------ the moments

    #[Test]
    public function placing_an_order_tells_the_customer(): void
    {
        $order = $this->placedOrder();

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->customer->id,
            'event' => NotificationEvent::OrderPlaced->value,
            'channel' => 'database',
            'status' => NotificationLog::SENT,
        ]);

        $this->assertSame(1, $this->customer->notifications()->count());
    }

    #[Test]
    public function the_customer_is_told_the_final_price_is_ready(): void
    {
        $order = $this->reviewedOrder();

        $log = NotificationLog::where('event', NotificationEvent::FinalPriceReady->value)
            ->where('channel', 'database')->firstOrFail();

        $this->assertSame($this->customer->id, $log->user_id);
        // The figure is in the message: a notification that says only «check the
        // app» makes the customer open it to learn a number we already knew.
        $this->assertStringContainsString($order->code, $log->body);
    }

    #[Test]
    public function the_laundry_is_told_when_the_customer_confirms(): void
    {
        $order = $this->reviewedOrder();
        app(OrderReviewService::class)->confirm($order->fresh(), $this->customer);

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->tenant['owner']->id,
            'event' => NotificationEvent::PriceConfirmed->value,
            'status' => NotificationLog::SENT,
        ]);
    }

    #[Test]
    public function each_stage_of_the_journey_is_announced_once(): void
    {
        $order = $this->reviewedOrder();
        app(OrderReviewService::class)->confirm($order->fresh(), $this->customer);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::Cleaning, 'laundry');
        $machine->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'laundry');

        foreach ([
            NotificationEvent::DriverOnWay,
            NotificationEvent::FinalPriceReady,
            NotificationEvent::PriceConfirmed,
            NotificationEvent::OrderReadyForDelivery,
        ] as $event) {
            $this->assertSame(
                1,
                NotificationLog::where('event', $event->value)->where('channel', 'database')->count(),
                $event->value.' should have been announced exactly once'
            );
        }

        // Cleaning is deliberately silent: the customer has just confirmed and
        // does not need a second message a moment later.
        $this->assertSame(0, NotificationLog::where('event', 'cleaning')->count());
    }

    #[Test]
    public function a_repeated_transition_does_not_announce_twice(): void
    {
        $order = $this->placedOrder();
        $machine = app(OrderStateMachine::class);

        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        // Idempotent by design — and it must not speak a second time either.
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');

        $this->assertSame(
            1,
            NotificationLog::where('event', NotificationEvent::DriverOnWay->value)
                ->where('channel', 'database')->count()
        );
    }

    #[Test]
    public function the_recurrence_prompt_is_actually_delivered(): void
    {
        $recurrences = app(RecurrenceService::class);

        $schedule = $recurrences->create($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'frequency' => 'weekly',
            'day_of_week' => 1,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ]);

        $schedule->update(['next_prompt_on' => now()->toDateString()]);

        $this->artisan('orders:prompt-recurring')->assertSuccessful();

        // Before P11 this was a row in a table nobody delivered — which made the
        // whole feature invisible to the customer it was for.
        $this->assertSame(1, RecurrencePrompt::count());
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->customer->id,
            'event' => NotificationEvent::RecurrencePrompt->value,
            'status' => NotificationLog::SENT,
        ]);
    }

    #[Test]
    public function a_driver_is_told_a_task_is_theirs(): void
    {
        $driver = $this->driverUser('01044440001', zoneIds: [$this->geo['zones'][0]->id]);

        $this->placedOrder();

        $this->assertGreaterThan(0, NotificationLog::where('user_id', $driver->id)
            ->where('event', NotificationEvent::TaskAssigned->value)->count());
    }

    // ------------------------------------------------------ the three claims

    #[Test]
    public function a_failing_push_channel_does_not_break_the_business_action(): void
    {
        // A vendor that throws on every send.
        $this->app->bind(PushSender::class, fn () => new class implements PushSender
        {
            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                throw new \RuntimeException('firebase is down');
            }

            public function lastFailureWasPermanent(): bool
            {
                return false;
            }
        });

        $order = $this->placedOrder();
        DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'tok-boom']);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');

        // The order moved regardless.
        $this->assertSame(OrderStatus::DriverOnWay, $order->fresh()->status);

        // And the failure was recorded rather than swallowed.
        $this->assertDatabaseHas('notification_logs', [
            'event' => NotificationEvent::DriverOnWay->value,
            'channel' => 'push',
            'status' => NotificationLog::FAILED,
        ]);
    }

    #[Test]
    public function a_muted_channel_is_skipped_and_the_skip_is_recorded(): void
    {
        NotificationPreference::create([
            'user_id' => $this->customer->id, 'channel' => 'database', 'enabled' => false,
        ]);

        $this->placedOrder();

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->customer->id,
            'event' => NotificationEvent::OrderPlaced->value,
            'channel' => 'database',
            'status' => NotificationLog::SKIPPED,
        ]);

        $this->assertSame(0, $this->customer->notifications()->count());
    }

    #[Test]
    public function a_muted_user_still_hears_what_the_order_depends_on(): void
    {
        NotificationPreference::create([
            'user_id' => $this->customer->id, 'channel' => 'database', 'enabled' => false,
        ]);

        $this->reviewedOrder();

        // «السعر النهائي جاهز» is not negotiable: silence here stops the order and
        // the customer never learns why.
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->customer->id,
            'event' => NotificationEvent::FinalPriceReady->value,
            'channel' => 'database',
            'status' => NotificationLog::SENT,
        ]);

        $this->assertSame(1, $this->customer->notifications()->count());
    }

    #[Test]
    public function a_permanently_rejected_token_is_pruned_and_a_transient_one_is_kept(): void
    {
        $permanent = new class implements PushSender
        {
            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                return false;
            }

            public function lastFailureWasPermanent(): bool
            {
                return true;
            }
        };

        $this->app->bind(PushSender::class, fn () => $permanent);

        DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'tok-dead']);
        $this->placedOrder();

        // A token FCM has invalidated fails forever; keeping it guarantees a
        // failure on every future send.
        $this->assertSame(0, DeviceToken::where('token', 'tok-dead')->count());

        // Now a transient failure.
        $this->app->bind(PushSender::class, fn () => new class implements PushSender
        {
            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                return false;
            }

            public function lastFailureWasPermanent(): bool
            {
                return false;
            }
        });

        DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'tok-busy']);
        $this->placedOrder('01099887799');

        // Deleting this one would silence a working handset.
        $this->assertSame(1, DeviceToken::where('token', 'tok-busy')->count());
    }

    #[Test]
    public function a_user_with_no_device_is_a_skip_not_a_failure(): void
    {
        $this->placedOrder();

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->customer->id,
            'channel' => 'push',
            'status' => NotificationLog::SKIPPED,
        ]);
    }

    // ------------------------------------------------------------------ the API

    #[Test]
    public function the_customer_reads_and_clears_their_list(): void
    {
        $this->placedOrder();

        Sanctum::actingAs($this->customer);

        $list = $this->getJson('/api/v1/notifications', $this->apiHeaders());
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertFalse($list->json('data.0.read'));
        $this->assertSame(NotificationEvent::OrderPlaced->value, $list->json('data.0.event'));

        $this->getJson('/api/v1/notifications/unread-count', $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.unread', 1);

        $id = DatabaseNotification::firstOrFail()->id;

        $this->postJson("/api/v1/notifications/{$id}/read", [], $this->apiHeaders())->assertOk();
        $this->getJson('/api/v1/notifications/unread-count', $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.unread', 0);
    }

    #[Test]
    public function a_customer_cannot_read_another_customers_notifications(): void
    {
        $this->placedOrder();
        $id = DatabaseNotification::firstOrFail()->id;

        Sanctum::actingAs($this->customer('01088776655'));

        $this->getJson('/api/v1/notifications', $this->apiHeaders())
            ->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/notifications/{$id}/read", [], $this->apiHeaders())
            ->assertNotFound();
    }

    #[Test]
    public function a_device_follows_the_handset_not_the_account(): void
    {
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/devices',
            ['token' => 'shared-handset', 'platform' => 'android', 'app' => 'customer'],
            $this->apiHeaders())->assertOk();

        $this->assertSame($this->customer->id, DeviceToken::firstOrFail()->user_id);

        // FCM reissues a token to whoever installs the app next on that handset.
        // Two users both believing they own it is how somebody receives another
        // person's order updates.
        $second = $this->customer('01077665544');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($second);

        $this->postJson('/api/v1/devices', ['token' => 'shared-handset'], $this->apiHeaders())
            ->assertOk();

        $this->assertSame(1, DeviceToken::count());
        $this->assertSame($second->id, DeviceToken::firstOrFail()->user_id);
    }

    #[Test]
    public function a_device_can_be_forgotten_on_logout(): void
    {
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/devices', ['token' => 'tok-1'], $this->apiHeaders())->assertOk();
        $this->deleteJson('/api/v1/devices', ['token' => 'tok-1'], $this->apiHeaders())->assertOk();

        $this->assertSame(0, DeviceToken::count());
    }

    #[Test]
    public function preferences_default_to_on_and_can_be_turned_off(): void
    {
        Sanctum::actingAs($this->customer);

        // Absent means enabled — only exceptions are stored.
        $this->getJson('/api/v1/notification-preferences', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.enabled', true)
            ->assertJsonPath('data.1.enabled', true);

        $this->assertSame(0, NotificationPreference::count());

        $this->putJson('/api/v1/notification-preferences',
            ['channel' => 'push', 'enabled' => false], $this->apiHeaders())->assertOk();

        $prefs = collect($this->getJson('/api/v1/notification-preferences', $this->apiHeaders())->json('data'));
        $this->assertFalse($prefs->firstWhere('channel', 'push')['enabled']);
        $this->assertTrue($prefs->firstWhere('channel', 'database')['enabled']);
    }

    #[Test]
    public function the_notification_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/notifications', $this->apiHeaders())->assertUnauthorized();
        $this->postJson('/api/v1/devices', ['token' => 'x'], $this->apiHeaders())->assertUnauthorized();
    }

    // ------------------------------------------------------- the ops alert

    #[Test]
    public function operations_is_told_about_a_task_nobody_took(): void
    {
        $admin = $this->superAdmin();

        // Nobody serves the zone, so all four legs queue.
        $order = $this->placedOrder();
        OrderTask::where('order_id', $order->id)
            ->update(['created_at' => now()->subHours(5)]);

        $this->artisan('tasks:alert-stuck')->assertSuccessful();

        $this->assertSame(
            4,
            NotificationLog::where('user_id', $admin->id)
                ->where('event', NotificationEvent::TaskQueuedTooLong->value)
                ->where('channel', 'database')->count()
        );
    }

    #[Test]
    public function the_same_stuck_task_is_never_raised_twice(): void
    {
        $this->superAdmin();
        $order = $this->placedOrder();
        OrderTask::where('order_id', $order->id)
            ->update(['created_at' => now()->subHours(5)]);

        // A queue that re-announces itself every hour teaches operations to
        // ignore the alert, which is worse than sending none.
        $this->artisan('tasks:alert-stuck')->assertSuccessful();
        $this->artisan('tasks:alert-stuck')->assertSuccessful();
        $this->artisan('tasks:alert-stuck')->assertSuccessful();

        $this->assertSame(4, NotificationLog::where('event', NotificationEvent::TaskQueuedTooLong->value)
            ->where('channel', 'database')->count());
    }

    #[Test]
    public function a_task_that_has_not_waited_long_enough_is_left_alone(): void
    {
        $this->superAdmin();
        $this->placedOrder();

        $this->artisan('tasks:alert-stuck')->assertSuccessful();

        $this->assertSame(0, NotificationLog::where('event', NotificationEvent::TaskQueuedTooLong->value)->count());
    }

    // ------------------------------------------------------------------ helpers

    private function placedOrder(string $phone = '01099887766'): Order
    {
        $customer = $phone === '01099887766' ? $this->customer : $this->customer($phone);
        $address = $phone === '01099887766'
            ? $this->address
            : $this->addressFor($customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    private function reviewedOrder(): Order
    {
        $order = $this->placedOrder();
        $machine = app(OrderStateMachine::class);

        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        app(OrderReviewService::class)->review(
            $order,
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            null,
            $this->tenant['owner']
        );

        return $order->fresh();
    }
}
