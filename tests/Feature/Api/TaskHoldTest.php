<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\PieceDiscrepancy;
use App\Modules\Order\Models\TaskHold;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\TaskService;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\RefundService;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Services\MenuBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «بانتظار موافقة الإدارة» — the client's sheet, items 9 and 10 (2026-11-08).
 *
 * 9: a count at the collection from the laundry that does not match the
 * laundry's own count stops the leg. 10: cash at the door that is less or more
 * than the leg takes stops it too. Both wait for the platform: approve
 * completes the leg with what the driver sent (more → the customer's wallet,
 * less → still owed), refuse sends it back.
 */
class TaskHoldTest extends TestCase
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
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
        $this->driver = $this->driverUser('+201044440001', zoneIds: [$this->geo['zones'][0]->id]);
    }

    // ------------------------------------------------- item 9: the count

    #[Test]
    public function a_count_at_the_laundry_that_does_not_match_its_count_is_held(): void
    {
        // Somebody at the platform to be told — before the hold, not after it.
        $admin = $this->superAdmin();
        $order = $this->atTheLaundry(laundryCount: 3);
        $leg = $this->startedLeg($order, TaskType::CollectFromLaundry);

        $response = $this->complete($leg, ['piece_count' => 2])
            ->assertStatus(409)
            ->assertJsonPath('key', 'awaiting_approval')
            ->assertJsonPath('data.hold.status', 'pending')
            ->assertJsonPath('data.hold.kind', 'piece_count');

        $this->assertEquals(2, $response->json('data.hold.reported'));
        // Never the laundry's count: shown it, a driver could type it back and
        // walk the hold away (`countsRevealed()`'s rule).
        $this->assertNull($response->json('data.hold.expected'));
        $this->assertStringNotContainsString('3', (string) $response->json('data.hold.message'));
        $this->assertEquals(3, TaskHold::firstOrFail()->expected);

        // Not completed: still the driver's, still started.
        $this->assertSame(TaskStatus::Started, $leg->fresh()->status);
        $this->assertSame(OrderStatus::Finished, $order->fresh()->status);

        // The platform is told, in its bell.
        $this->assertTrue(
            $admin->notifications()->get()->contains(
                fn ($n) => str_contains(json_encode($n->data, JSON_UNESCAPED_UNICODE), 'Waiting for your approval')
            ),
        );
    }

    #[Test]
    public function the_matching_count_completes_at_once_and_withdraws_the_question(): void
    {
        $order = $this->atTheLaundry(laundryCount: 3);
        $leg = $this->startedLeg($order, TaskType::CollectFromLaundry);

        $this->complete($leg, ['piece_count' => 2])->assertStatus(409);
        // Recounted, and it was three after all.
        $this->complete($leg, ['piece_count' => 3])->assertOk();

        $this->assertSame(TaskStatus::Completed, $leg->fresh()->status);
        $this->assertSame(TaskHold::WITHDRAWN, TaskHold::firstOrFail()->status);
    }

    #[Test]
    public function a_second_wrong_count_replaces_the_question_rather_than_adding_one(): void
    {
        $order = $this->atTheLaundry(laundryCount: 3);
        $leg = $this->startedLeg($order, TaskType::CollectFromLaundry);

        $this->complete($leg, ['piece_count' => 2])->assertStatus(409);
        $this->complete($leg, ['piece_count' => 4])->assertStatus(409);

        $this->assertSame(1, TaskHold::count());
        $this->assertEquals(4, TaskHold::firstOrFail()->reported);
    }

    #[Test]
    public function the_platform_approves_and_the_leg_completes_with_what_the_driver_counted(): void
    {
        $order = $this->atTheLaundry(laundryCount: 3);
        $leg = $this->startedLeg($order, TaskType::CollectFromLaundry);
        $this->complete($leg, ['piece_count' => 2, 'note' => 'one was missing'])->assertStatus(409);
        $hold = TaskHold::firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.hold.approve', $hold->id), ['hold_note' => 'the laundry confirmed two'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $leg->refresh();
        $this->assertSame(TaskStatus::Completed, $leg->status);
        $this->assertSame(2, $leg->piece_count);
        $this->assertSame(OrderStatus::ReadyForDelivery, $order->fresh()->status);
        $this->assertSame(TaskHold::APPROVED, $hold->fresh()->status);

        // The difference is on the record, and already closed by whoever approved.
        $discrepancy = PieceDiscrepancy::where('order_task_id', $leg->id)->firstOrFail();
        $this->assertFalse((bool) $discrepancy->open);
        $this->assertSame(2, $discrepancy->confirmed_count);

        // The driver is told.
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->driver->id, 'event' => 'task_hold_decided']);
    }

    #[Test]
    public function the_platform_refuses_with_a_reason_and_the_leg_waits_for_a_recount(): void
    {
        $order = $this->atTheLaundry(laundryCount: 3);
        $leg = $this->startedLeg($order, TaskType::CollectFromLaundry);
        $this->complete($leg, ['piece_count' => 2])->assertStatus(409);
        $hold = TaskHold::firstOrFail();

        // A reason is required: it is what the driver is told.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.hold.reject', $hold->id), ['hold_note' => ''])
            ->assertSessionHasErrors('hold_note');

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.hold.reject', $hold->id), ['hold_note' => 'count again, the laundry has three'])
            ->assertRedirect();

        $this->assertSame(TaskHold::REJECTED, $hold->fresh()->status);
        $this->assertSame(TaskStatus::Started, $leg->fresh()->status);

        Sanctum::actingAs($this->driver);
        $this->getJson("/api/v1/driver/tasks/{$leg->id}", $this->apiHeaders())
            ->assertJsonPath('data.hold.status', 'rejected')
            ->assertJsonPath('data.hold.note', 'count again, the laundry has three');
    }

    #[Test]
    public function a_laundry_cannot_approve_its_own_count_even_holding_order_update(): void
    {
        $order = $this->atTheLaundry(laundryCount: 3);
        $leg = $this->startedLeg($order, TaskType::CollectFromLaundry);
        $this->complete($leg, ['piece_count' => 2])->assertStatus(409);
        $hold = TaskHold::firstOrFail();

        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.order.hold.approve', $hold->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(TaskHold::PENDING, $hold->fresh()->status);
        $this->assertSame(TaskStatus::Started, $leg->fresh()->status);
    }

    #[Test]
    public function the_order_screen_shows_the_question_with_its_buttons(): void
    {
        $order = $this->atTheLaundry(laundryCount: 3);
        $leg = $this->startedLeg($order, TaskType::CollectFromLaundry);
        $this->complete($leg, ['piece_count' => 2])->assertStatus(409);
        $hold = TaskHold::firstOrFail();

        $this->actingAs($this->superAdmin())->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee('data-task-hold="'.$hold->id.'"', false)
            ->assertSee(route('admin.order.hold.approve', $hold->id));

        // And it is in the list's «awaiting approval» filter.
        $this->actingAs($this->superAdmin())
            ->getJson(route('admin.order.search', ['query' => '', 'status' => 'awaiting_approval']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('#'.$order->code);
    }

    // ------------------------------------------------ item 10: the cash

    #[Test]
    public function the_exact_amount_completes_the_delivery(): void
    {
        $order = $this->outForDelivery();
        $leg = $this->startedLeg($order, TaskType::DeliverToCustomer);

        $this->complete($leg, ['collected_amount' => $order->stillOwed()] + $this->signed())->assertOk();

        $this->assertSame(TaskStatus::Completed, $leg->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, TaskHold::count());
    }

    #[Test]
    public function less_cash_is_held_and_approved_stays_owed(): void
    {
        $order = $this->outForDelivery();
        $owed = $order->stillOwed();
        $leg = $this->startedLeg($order, TaskType::DeliverToCustomer);

        $this->complete($leg, ['collected_amount' => $owed - 10] + $this->signed())
            ->assertStatus(409)
            ->assertJsonPath('data.hold.kind', 'collected_amount');

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.hold.approve', TaskHold::firstOrFail()->id))
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(TaskStatus::Completed, $leg->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNotSame('paid', $order->payment_status);
        $this->assertEqualsWithDelta(10, $order->stillOwed(), 0.01);
        // The signature sent with the held confirmation came with it.
        $this->assertNotNull($leg->fresh()->signature_path);
    }

    #[Test]
    public function more_cash_is_held_not_refused_and_approved_the_extra_goes_to_the_wallet(): void
    {
        $order = $this->outForDelivery();
        $owed = $order->stillOwed();
        $leg = $this->startedLeg($order, TaskType::DeliverToCustomer);

        // Used to be a flat 422 «more than the customer owes».
        $this->complete($leg, ['collected_amount' => $owed + 15] + $this->signed())->assertStatus(409);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.order.hold.approve', TaskHold::firstOrFail()->id))
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->status);

        $credit = WalletTransaction::where('reason', 'over_collected')->firstOrFail();
        $this->assertEqualsWithDelta(15, (float) $credit->amount, 0.01);
        $this->assertSame($this->customer->id, $credit->wallet->user_id);
    }

    #[Test]
    public function cash_is_decided_only_by_someone_who_handles_payments(): void
    {
        $order = $this->outForDelivery();
        $leg = $this->startedLeg($order, TaskType::DeliverToCustomer);
        $this->complete($leg, ['collected_amount' => 1] + $this->signed())->assertStatus(409);

        $this->grant('admin', ['order.view', 'order.update']);
        $desk = User::create([
            'name' => 'Desk', 'email' => 'desk@test.local', 'phone' => '+201077770001',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'), 'phone_verified_at' => now(),
        ]);

        $this->actingAs($desk)
            ->post(route('admin.order.hold.approve', TaskHold::firstOrFail()->id))
            ->assertForbidden();

        $this->assertSame(TaskHold::PENDING, TaskHold::firstOrFail()->status);
    }

    // -------------------------------------------- code review (2026-11-08)

    #[Test]
    public function a_leg_given_up_takes_its_question_with_it(): void
    {
        $order = $this->outForDelivery();
        $leg = $this->startedLeg($order, TaskType::DeliverToCustomer);
        $this->complete($leg, ['collected_amount' => 1] + $this->signed())->assertStatus(409);
        $hold = TaskHold::firstOrFail();

        // The driver fails the leg; another driver would take it next. The old
        // question must not complete it with this driver's cash.
        app(TaskService::class)->fail($leg->fresh(), $this->driver, TaskFailureReason::WrongAddress);

        $this->assertSame(TaskHold::WITHDRAWN, $hold->fresh()->status);
        $this->expectExceptionMessage('nothing_to_decide');
        app(TaskService::class)->approveHold($hold->fresh(), $this->platformOperator());
    }

    #[Test]
    public function a_cancelled_order_leaves_no_question_open(): void
    {
        $order = $this->placed();
        $leg = $this->startedLeg($order, TaskType::PickupFromCustomer);
        // 30 against a 25 trip fee: held.
        $this->complete($leg, ['collected_amount' => 30])->assertStatus(409);

        app(OrderService::class)->cancel($order->fresh(), $this->customer);

        $this->assertSame(TaskHold::WITHDRAWN, TaskHold::firstOrFail()->status);
        $this->assertSame(0, Order::withPendingHold()->count());
    }

    #[Test]
    public function cash_at_the_pickup_never_marks_the_order_paid_before_it_is_counted(): void
    {
        // Security review (2026-11-08): 100 at a 25-fee pickup covered the
        // estimate, the order went `paid`, and the delivery of a 400 count was
        // told to take 0.
        $order = $this->placed();
        $leg = $this->startedLeg($order, TaskType::PickupFromCustomer);
        $this->complete($leg, ['collected_amount' => $order->payableTotal() + 50])->assertStatus(409);
        app(TaskService::class)->approveHold(TaskHold::firstOrFail(), $this->platformOperator());

        $order->refresh();
        $this->assertNotSame('paid', $order->payment_status);
        $this->assertSame(0, WalletTransaction::where('reason', 'over_collected')->count());

        // The laundry counts far more; the pickup's cash is a payment towards it.
        $order->forceFill(['final_total' => $order->paidSoFar() + 300])->save();
        $delivery = $this->leg($order, TaskType::DeliverToCustomer);
        $delivery->setRelation('order', $order->fresh());
        $this->assertEqualsWithDelta(300, $delivery->toCollect(), 0.01);
        $this->assertTrue($delivery->requiresCollectedAmount());
    }

    #[Test]
    public function cash_over_the_trip_fee_left_over_after_the_count_goes_to_the_wallet_at_the_delivery(): void
    {
        $order = $this->outForDelivery();
        $bill = $order->payableTotal();
        // Taken at the pickup, approved: 20 more than the counted bill.
        Payment::create([
            'order_id' => $order->id, 'user_id' => $order->user_id, 'provider' => 'cash',
            'method' => PaymentMethod::Cash, 'amount' => $bill + 20,
            'status' => PaymentStatus::Captured, 'captured_at' => now(),
            'collected_by' => $this->driver->id,
        ]);

        $leg = $this->startedLeg($order, TaskType::DeliverToCustomer);
        $this->assertSame(0.0, $leg->fresh()->toCollect());
        $this->complete($leg, ['collected_amount' => 0] + $this->signed())->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $credit = WalletTransaction::where('reason', 'over_collected')->firstOrFail();
        $this->assertEqualsWithDelta(20, (float) $credit->amount, 0.01);
    }

    #[Test]
    public function cash_over_the_bill_is_not_refundable_a_second_time(): void
    {
        $order = $this->outForDelivery();
        $owed = $order->stillOwed();
        $leg = $this->startedLeg($order, TaskType::DeliverToCustomer);
        $this->complete($leg, ['collected_amount' => $owed + 15] + $this->signed())->assertStatus(409);
        app(TaskService::class)->approveHold(TaskHold::firstOrFail(), $this->platformOperator());

        // 15 is already in the wallet: what can be refunded is the bill, no more.
        $this->assertEqualsWithDelta(
            $order->fresh()->payableTotal(),
            app(RefundService::class)->refundableAmount($order->fresh()),
            0.01,
        );
    }

    #[Test]
    public function a_laundry_is_not_badged_for_what_only_the_platform_decides(): void
    {
        $order = $this->atTheLaundry(laundryCount: 3);
        $leg = $this->startedLeg($order, TaskType::CollectFromLaundry);
        $this->complete($leg, ['piece_count' => 2])->assertStatus(409);

        $this->actingAs($this->superAdmin());
        $platform = MenuBadges::for('order');

        $this->actingAs($this->tenant['owner']);
        $laundry = MenuBadges::for('order');

        $this->assertSame((int) $platform - 1, (int) $laundry);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Collected, counted by the laundry at `$laundryCount`, priced, confirmed
     * and cleaning — the collection from the laundry is next.
     */
    private function atTheLaundry(int $laundryCount): Order
    {
        $order = $this->placed();

        foreach ([TaskType::PickupFromCustomer, TaskType::DeliverToLaundry] as $type) {
            $this->leg($order, $type)->forceFill(['status' => TaskStatus::Completed, 'completed_at' => now(), 'driver_id' => $this->driver->id])->save();
        }

        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $reviews = app(OrderReviewService::class);
        $reviews->review($order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => $laundryCount]], null, $this->tenant['owner']);
        $this->sendReview($order);
        $reviews->confirm($order->fresh(), $this->customer);

        // Washed: the collection may start (item 18).
        return $this->finishWashing($order);
    }

    /**
     * Back from the laundry, the delivery next; nothing paid yet.
     */
    private function outForDelivery(): Order
    {
        $order = $this->atTheLaundry(laundryCount: 2);
        $this->leg($order, TaskType::CollectFromLaundry)->forceFill(['status' => TaskStatus::Completed, 'completed_at' => now(), 'driver_id' => $this->driver->id])->save();
        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::ReadyForDelivery, 'driver');

        return $order->fresh();
    }

    private function placed(): Order
    {
        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'payment_method' => 'cash',
            'accepts_review_terms' => true,
        ]);
    }

    private function leg(Order $order, TaskType $type): OrderTask
    {
        return OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();
    }

    private function startedLeg(Order $order, TaskType $type): OrderTask
    {
        $task = $this->leg($order, $type);
        app(DriverDispatcher::class)->assign($task, $this->driver);

        return app(TaskService::class)->start($task->fresh(), $this->driver);
    }

    /**
     * @return array<string, mixed>
     */
    private function signed(): array
    {
        return ['signature' => UploadedFile::fake()->image('sig.png')];
    }

    private function complete(OrderTask $leg, array $data)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->driver);

        return $this->postJson("/api/v1/driver/tasks/{$leg->id}/complete", $data, $this->apiHeaders());
    }
}
