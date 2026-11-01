<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\TaskService;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The back half of an order's life, which was never wired up.
 *
 * `allowedNext()` runs `confirmed → cleaning → ready_for_delivery → delivered →
 * completed`, and four of those edges had nothing driving them. So
 * `DeliverToCustomer` — which completes into `Delivered` — asked for
 * `confirmed → delivered`, the table refused it (correctly), and the order was
 * stranded at `confirmed` for ever with a `confirmed → confirmed` note in the
 * log written at the exact second the driver handed the clothes over.
 *
 * On the live install that meant **19 orders, none past `confirmed`, one
 * settlement and not a single driver earning ever released**: `settleMoney()`
 * fires at `Completed`, which nothing could reach.
 *
 * Nothing new is endpointed here. Every missing edge already had an event that
 * means it — the clothes reaching the laundry, the laundry handing them back,
 * and the money arriving. The last is the distinction `RatingService` already
 * stated in prose and nothing acted on: **delivered is the clothes, completed is
 * the payment.**
 */
class OrderLifecycleCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $buyer;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->driver = $this->driverUser();
        $this->buyer = $this->customer();

        // The platform's own wallet is the **oldest super admin**
        // (`Support\PlatformAccount`). Without one, `settleFor()` finds no payee
        // and leaves the settlement `pending` with a log line rather than
        // failing — so a test that omits it quietly proves nothing about the
        // money moving.
        $this->superAdmin();
    }

    private function order(string $status = 'confirmed', float $total = 100): Order
    {
        $laundry = $this->laundryWithOwner('A', '+201011110001', '+201011110002')['laundry'];

        return Order::withoutGlobalScopes()->create([
            'code' => Order::generateCode(),
            'user_id' => $this->buyer->id,
            'laundry_id' => $laundry->id,
            'service_id' => $this->catalog['service']->id,
            'status' => $status,
            'pickup_address_id' => $this->addressFor($this->buyer, $this->geo['zones'][0])->id,
            'delivery_address_id' => $this->addressFor($this->buyer, $this->geo['zones'][0])->id,
            'delivery_fee' => 0,
            'estimated_total' => $total,
            'final_total' => $total,
            'payment_method' => 'cash',
            'qr_token' => Order::generateQrToken(),
        ]);
    }

    /**
     * The leg under test, with the ones before it already done.
     *
     * `TaskService::complete()` refuses a leg whose predecessor is unfinished —
     * nothing can be delivered that was never collected — so a test that creates
     * only leg four is testing the guard, not the transition.
     */
    private function leg(Order $order, TaskType $type, int $sequence): OrderTask
    {
        for ($i = 1; $i < $sequence; $i++) {
            if ($order->tasks()->where('sequence', $i)->exists()) {
                continue;
            }

            OrderTask::create([
                'order_id' => $order->id,
                'type' => TaskType::cases()[$i - 1]->value,
                'sequence' => $i,
                'status' => TaskStatus::Completed->value,
                'driver_id' => $this->driver->id,
                'completed_at' => now()->subMinutes(10 - $i),
            ])->forceFill(['signature_path' => 'signatures/test.png'])->save();
        }

        $task = OrderTask::create([
            'order_id' => $order->id,
            'type' => $type->value,
            'sequence' => $sequence,
            'status' => TaskStatus::Started->value,
            'driver_id' => $this->driver->id,
        ]);

        // A handover to a person is proved by that person, so the two
        // customer-facing legs refuse to complete without one. Set as a path
        // rather than uploaded: `complete()` accepts an existing
        // `signature_path`, and a fake file here would be testing the uploader.
        if ($type->requiresSignature()) {
            $task->forceFill(['signature_path' => 'signatures/test.png'])->save();
        }

        return $task;
    }

    /**
     * Completes a leg with whatever that leg insists on.
     *
     * Three of the four count pieces — everything except the delivery, where the
     * count was settled at the laundry — and passing it per call would put the
     * same line in every test.
     */
    private function finish(OrderTask $task, array $data = []): void
    {
        if ($task->type->countsPieces() && ! array_key_exists('piece_count', $data)) {
            $data['piece_count'] = 3;
        }

        app(TaskService::class)->complete($task, $this->driver, $data);
    }

    // ------------------------------------------------- the edges that were missing

    #[Test]
    public function the_clothes_reaching_the_laundry_starts_the_cleaning(): void
    {
        $order = $this->order('confirmed');

        $this->finish($this->leg($order, TaskType::DeliverToLaundry, 2));

        $this->assertSame(OrderStatus::Cleaning, $order->fresh()->status);
    }

    #[Test]
    public function the_laundry_handing_them_back_makes_the_order_ready(): void
    {
        $order = $this->order('cleaning');

        $this->finish($this->leg($order, TaskType::CollectFromLaundry, 3));

        $this->assertSame(OrderStatus::ReadyForDelivery, $order->fresh()->status);
    }

    // --------------------------------------------- delivered is not completed

    #[Test]
    public function a_delivery_with_the_cash_in_hand_completes_the_order(): void
    {
        $order = $this->order('ready_for_delivery');

        $this->finish($this->leg($order, TaskType::DeliverToCustomer, 4), [
            'collected_amount' => $order->payableTotal(),
        ]);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    #[Test]
    public function a_delivery_with_no_money_stops_at_delivered(): void
    {
        // Deliberately not tidied away. An unpaid delivered order is a thing
        // somebody should chase, and forcing it to `completed` would settle the
        // laundry's share out of money nobody has collected.
        $order = $this->order('ready_for_delivery');

        // Nothing changed hands, and the driver says so.
        $this->finish($this->leg($order, TaskType::DeliverToCustomer, 4), ['collected_amount' => 0]);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    #[Test]
    public function a_short_collection_does_not_complete_the_order(): void
    {
        $order = $this->order('ready_for_delivery', 100);

        $this->finish($this->leg($order, TaskType::DeliverToCustomer, 4), [
            'collected_amount' => 60,
        ]);

        $fresh = $order->fresh();

        $this->assertSame(OrderStatus::Delivered, $fresh->status);
        $this->assertNotSame('paid', $fresh->payment_status);
    }

    #[Test]
    public function money_arriving_after_the_handover_closes_it(): void
    {
        // The other ordering: the clothes are already with the customer and the
        // payment lands later. Either event may be last, so both ask.
        $order = $this->order('ready_for_delivery');

        $this->finish($this->leg($order, TaskType::DeliverToCustomer, 4), ['collected_amount' => 0]);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        $order->fresh()->update(['payment_status' => 'paid', 'paid_at' => now()]);
        app(OrderStateMachine::class)->completeIfPaid($order->fresh());

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    #[Test]
    public function asking_twice_is_not_an_error(): void
    {
        // A replayed webhook, or a second leg completion.
        $order = $this->order('ready_for_delivery');

        $this->finish($this->leg($order, TaskType::DeliverToCustomer, 4), [
            'collected_amount' => $order->payableTotal(),
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->completeIfPaid($order->fresh());
        $machine->completeIfPaid($order->fresh());

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(1, $order->statusLogs()->where('to_status', 'completed')->count());
    }

    // ------------------------------------------------------------ the money

    #[Test]
    public function the_whole_walk_ends_with_the_laundry_settled(): void
    {
        /*
         * The point of all of it. Before this the order stopped at `confirmed`
         * and `settleMoney()` — which only fires at `Completed` — never ran, so
         * the laundry's share and the driver's bonus sat unpaid for ever.
         */
        $order = $this->order('confirmed');

        $this->finish($this->leg($order, TaskType::DeliverToLaundry, 2));
        $this->assertSame(OrderStatus::Cleaning, $order->fresh()->status);

        $this->finish($this->leg($order, TaskType::CollectFromLaundry, 3));
        $this->assertSame(OrderStatus::ReadyForDelivery, $order->fresh()->status);

        $this->finish($this->leg($order, TaskType::DeliverToCustomer, 4), [
            'collected_amount' => $order->payableTotal(),
        ]);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);

        $settlement = OrderSettlement::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->first();

        $this->assertNotNull($settlement, 'the order completed without a settlement');
        $this->assertSame(OrderSettlement::SETTLED, $settlement->status);
    }

    #[Test]
    public function the_log_carries_every_step_the_tracking_screen_draws(): void
    {
        // The customer's timeline is built from the log, not from the current
        // status. A status that jumped would leave the screen with dark lamps
        // between lit ones.
        $order = $this->order('confirmed');

        $this->finish($this->leg($order, TaskType::DeliverToLaundry, 2));
        $this->finish($this->leg($order, TaskType::CollectFromLaundry, 3));
        $this->finish($this->leg($order, TaskType::DeliverToCustomer, 4), [
            'collected_amount' => $order->payableTotal(),
        ]);

        $logged = $order->statusLogs()->pluck('to_status')->all();

        foreach (['cleaning', 'ready_for_delivery', 'delivered', 'completed'] as $status) {
            $this->assertContains($status, $logged, "«{$status}» never reached the log");
        }
    }

    #[Test]
    public function nothing_leaves_the_laundry_before_its_price_is_agreed(): void
    {
        // The refusal path used to let this leg complete with a note, which is
        // how live orders still `picked_up` were collected and delivered with
        // no price and nothing to collect (2026-10-01). The pieces stay put.
        $order = $this->order('picked_up');
        $leg = $this->leg($order, TaskType::CollectFromLaundry, 3);

        try {
            $this->finish($leg);
            $this->fail('A collection was completed before the price was agreed.');
        } catch (RuntimeException $e) {
            $this->assertSame('order_not_ready', $e->getMessage());
        }

        $this->assertNotSame(TaskStatus::Completed, $leg->fresh()->status);
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }

    /**
     * Order 85 on live (2026-11-01): placed with no laundry, a driver assigned
     * by hand, and the pieces taken through count, cleaning and delivery to
     * `completed` with nobody to take them to or pay. Its settlement sat
     * pending with no laundry to divide with.
     */
    #[Test]
    public function a_pickup_does_not_start_while_the_order_has_no_laundry(): void
    {
        $order = $this->order('awaiting_pickup');
        $order->forceFill(['laundry_id' => null])->save();

        $pickup = $this->openLeg($order, TaskType::PickupFromCustomer, 1, TaskStatus::Assigned);

        try {
            app(TaskService::class)->start($pickup, $this->driver);
            $this->fail('A pickup started on an order with no laundry.');
        } catch (RuntimeException $e) {
            $this->assertSame('order_has_no_laundry', $e->getMessage());
        }

        $this->assertSame(TaskStatus::Assigned, $pickup->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPickup, $order->fresh()->status);
    }

    #[Test]
    public function a_driver_already_holding_the_pieces_can_always_hand_them_over(): void
    {
        // Held at the start, never at the finish: a pickup under way when the
        // rule arrived must not leave somebody's clothes in a van.
        $order = $this->order('driver_on_way');
        $order->forceFill(['laundry_id' => null])->save();

        $pickup = $this->openLeg($order, TaskType::PickupFromCustomer, 1, TaskStatus::Started);
        $pickup->forceFill(['signature_path' => 'signatures/test.png'])->save();

        $this->finish($pickup->fresh());

        $this->assertSame(TaskStatus::Completed, $pickup->fresh()->status);
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }

    #[Test]
    public function a_collected_order_with_no_laundry_waits_for_one_and_then_moves(): void
    {
        $order = $this->order('picked_up');
        $order->forceFill(['laundry_id' => null])->save();
        $laundryId = Order::withoutGlobalScopes()->whereNotNull('laundry_id')->value('laundry_id')
            ?? $this->laundryWithOwner('B', '+201011110003', '+201011110004')['laundry']->id;

        $this->leg($order, TaskType::DeliverToLaundry, 2);
        $toLaundry = $order->tasks()->where('sequence', 2)->first();
        $toLaundry->forceFill(['status' => TaskStatus::Assigned->value])->save();

        try {
            app(TaskService::class)->start($toLaundry->fresh(), $this->driver);
            $this->fail('A trip to the laundry started with no laundry set.');
        } catch (RuntimeException $e) {
            $this->assertSame('order_has_no_laundry', $e->getMessage());
        }

        // Operations set one — allowed in custody when there was none — and
        // the agreed figures stay as they were.
        $before = $order->fresh()->only(['delivery_fee', 'estimated_total']);
        app(\App\Modules\Order\Services\OrderService::class)->assignLaundry($order->fresh(), $laundryId);

        $this->assertSame($laundryId, $order->fresh()->laundry_id);
        $this->assertEquals($before, $order->fresh()->only(['delivery_fee', 'estimated_total']));

        app(TaskService::class)->start($toLaundry->fresh(), $this->driver);
        $this->assertSame(TaskStatus::Started, $toLaundry->fresh()->status);
    }

    #[Test]
    public function a_collected_order_keeps_the_laundry_it_has(): void
    {
        $order = $this->order('picked_up');
        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004')['laundry'];

        try {
            app(\App\Modules\Order\Services\OrderService::class)->assignLaundry($order, $other->id);
            $this->fail('A collected order was moved to another laundry.');
        } catch (RuntimeException $e) {
            $this->assertSame('already_in_custody', $e->getMessage());
        }
    }

    #[Test]
    public function the_panel_will_not_give_a_driver_a_leg_with_nowhere_to_go(): void
    {
        $order = $this->order('awaiting_pickup');
        $order->forceFill(['laundry_id' => null])->save();

        $pickup = $this->openLeg($order, TaskType::PickupFromCustomer, 1, TaskStatus::Pending);
        $pickup->forceFill(['driver_id' => null])->save();

        try {
            app(\App\Modules\Order\Services\DriverDispatcher::class)->assign($pickup->fresh(), $this->driver);
            $this->fail('A driver was given a pickup with no laundry set.');
        } catch (RuntimeException $e) {
            $this->assertSame('order_has_no_laundry', $e->getMessage());
        }

        $this->assertNull($pickup->fresh()->driver_id);
    }

    private function openLeg(Order $order, TaskType $type, int $sequence, TaskStatus $status): OrderTask
    {
        return OrderTask::create([
            'order_id' => $order->id,
            'type' => $type->value,
            'sequence' => $sequence,
            'status' => $status->value,
            'driver_id' => $this->driver->id,
        ])->fresh();
    }

    #[Test]
    public function the_driver_app_is_told_the_order_waits_for_a_laundry(): void
    {
        $order = $this->order('awaiting_pickup');
        $order->forceFill(['laundry_id' => null])->save();

        $pickup = $this->openLeg($order, TaskType::PickupFromCustomer, 1, TaskStatus::Assigned);

        $this->actingAs($this->driver, 'sanctum')
            ->getJson("/api/v1/driver/tasks/{$pickup->id}", $this->apiHeaders('en'))
            ->assertOk()
            ->assertJsonPath('data.can_start', false)
            ->assertJsonPath('data.blocked_reason', __('This order has no laundry yet. Operations will assign one before it can move.'));

        $this->actingAs($this->driver, 'sanctum')
            ->postJson("/api/v1/driver/tasks/{$pickup->id}/start", [], $this->apiHeaders('en'))
            ->assertStatus(400)
            ->assertJsonPath('msg', __('This order has no laundry yet. Operations will assign one before it can move.'));
    }

    #[Test]
    public function an_order_with_no_laundry_cannot_be_counted(): void
    {
        $order = $this->order('picked_up');
        $order->forceFill(['laundry_id' => null])->save();

        try {
            app(OrderReviewService::class)->review($order->fresh(), [
                ['item_id' => $this->catalog['items'][0]->id, 'qty' => 2],
            ]);
            $this->fail('An order with no laundry was counted.');
        } catch (RuntimeException $e) {
            $this->assertSame('order_has_no_laundry', $e->getMessage());
        }

        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }
}
