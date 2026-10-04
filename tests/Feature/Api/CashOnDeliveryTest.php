<?php

namespace Tests\Feature\Api;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\TaskService;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Models\Payment;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cash on delivery, end to end (the owner, 2026-10-01: «حاسس فيه حاجة غلط»).
 *
 * What live showed: orders still `picked_up` collected from the laundry and
 * delivered with no price; deliveries closed with no amount, so the order was
 * never paid and never completed; most orders carrying no payment method at
 * all; and no trace of the cash anywhere but a flag on the order.
 */
class CashOnDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private array $tenant;

    private User $customer;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->driver = $this->driverUser('+201044440001', zoneIds: [$this->geo['zones'][0]->id]);
    }

    private function place(array $extra = []): Order
    {
        return app(OrderService::class)->place($this->customer, $extra + [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    private function leg(Order $order, TaskType $type): OrderTask
    {
        return OrderTask::where('order_id', $order->id)->where('type', $type->value)->firstOrFail();
    }

    private function walk(Order $order, TaskType $type, array $data = []): void
    {
        $task = $this->leg($order, $type);

        if ($task->driver_id === null) {
            app(DriverDispatcher::class)->assign($task, $this->driver);
        }

        $tasks = app(TaskService::class);
        $tasks->start($task->fresh(), $this->driver);
        $tasks->complete(
            $task->fresh(),
            $this->driver,
            $data + ($type->countsPieces() ? ['piece_count' => 2] : []),
            [],
            $type->requiresSignature() ? UploadedFile::fake()->image('sig.png') : null,
        );
    }

    private function priced(Order $order): Order
    {
        $reviews = app(OrderReviewService::class);
        $reviews->review($order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);

        return $reviews->confirm($order->fresh(), $this->customer);
    }

    /** Collected, priced, agreed and back from the laundry: the door is next. */
    private function atTheDoor(): Order
    {
        $order = $this->place();
        $this->walk($order, TaskType::PickupFromCustomer);
        $this->walk($order->fresh(), TaskType::DeliverToLaundry);
        $this->priced($order);
        $this->walk($order->fresh(), TaskType::CollectFromLaundry);

        $delivery = $this->leg($order, TaskType::DeliverToCustomer);
        app(TaskService::class)->start($delivery->fresh(), $this->driver);

        return $order->fresh();
    }

    private function deliver(Order $order, array $data)
    {
        Sanctum::actingAs($this->driver);
        $leg = $this->leg($order, TaskType::DeliverToCustomer);

        return $this->post("/api/v1/driver/tasks/{$leg->id}/complete", $data + [
            'signature' => UploadedFile::fake()->image('sig.png'),
        ], $this->apiHeaders());
    }

    private function row(OrderTask $task): array
    {
        Sanctum::actingAs($this->driver);

        return collect($this->getJson('/api/v1/driver/tasks?per_page=50', $this->apiHeaders())->assertOk()->json('data'))
            ->firstWhere('id', $task->id);
    }

    // --------------------------------------------------- nothing leaves early

    #[Test]
    public function the_laundry_collection_waits_for_the_agreed_price(): void
    {
        $order = $this->place();
        $this->walk($order, TaskType::PickupFromCustomer);
        $this->walk($order->fresh(), TaskType::DeliverToLaundry);
        $collect = $this->leg($order, TaskType::CollectFromLaundry);

        // At the laundry, not yet priced: the app is told why it cannot start.
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
        $row = $this->row($collect);
        $this->assertFalse($row['can_start']);
        $this->assertSame(
            __("This order is still waiting for the laundry's review and the customer's price confirmation."),
            $row['blocked_reason']
        );
        $this->postJson("/api/v1/driver/tasks/{$collect->id}/start", [], $this->apiHeaders())
            ->assertStatus(400)
            ->assertJsonPath('msg', $row['blocked_reason']);

        // Priced and agreed with the pieces already there: the work starts at
        // once, which is what nothing used to do.
        $this->assertSame(OrderStatus::Cleaning, $this->priced($order)->status);

        $row = $this->row($collect);
        $this->assertTrue($row['can_start']);
        $this->assertNull($row['blocked_reason']);
    }

    #[Test]
    public function a_price_agreed_before_the_handover_waits_for_it(): void
    {
        $order = $this->place();
        $this->walk($order, TaskType::PickupFromCustomer);

        // Counted on the way in: agreed before the pieces reach the laundry.
        $this->assertSame(OrderStatus::Confirmed, $this->priced($order)->status);

        $this->walk($order->fresh(), TaskType::DeliverToLaundry);
        $this->assertSame(OrderStatus::Cleaning, $order->fresh()->status);
    }

    // ------------------------------------------------------ what the door says

    #[Test]
    public function an_unpaid_delivery_must_say_what_was_collected(): void
    {
        $order = $this->atTheDoor();
        $due = $order->payableTotal();

        Sanctum::actingAs($this->driver);
        $payment = $this->getJson("/api/v1/driver/tasks/{$this->leg($order, TaskType::DeliverToCustomer)->id}", $this->apiHeaders())
            ->assertOk()->json('data.payment');
        $this->assertTrue($payment['collect_required']);
        $this->assertEquals($due, $payment['to_collect']);

        $this->deliver($order, [])->assertStatus(422)->assertJsonValidationErrors('collected_amount');
        $this->deliver($order, ['collected_amount' => $due + 200])->assertStatus(422)->assertJsonValidationErrors('collected_amount');
        $this->assertSame(0, Payment::count());

        $this->deliver($order, ['collected_amount' => $due])->assertOk();

        // Paid and closed, and the cash is a payment in the driver's name.
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $cash = Payment::sole();
        $this->assertSame(PaymentMethod::Cash, $cash->method);
        $this->assertSame($this->driver->id, $cash->collected_by);
        $this->assertNull($cash->handed_over_at);
    }

    #[Test]
    public function nothing_or_part_of_it_is_recorded_as_exactly_that(): void
    {
        $order = $this->atTheDoor();

        $this->deliver($order, ['collected_amount' => 0])->assertOk();

        // Delivered, unpaid, and no cash invented.
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function a_paid_order_collects_nothing_at_the_door(): void
    {
        $order = $this->atTheDoor();
        $order->forceFill(['payment_status' => 'paid', 'paid_at' => now()])->save();

        Sanctum::actingAs($this->driver);
        $payment = $this->getJson("/api/v1/driver/tasks/{$this->leg($order, TaskType::DeliverToCustomer)->id}", $this->apiHeaders())
            ->assertOk()->json('data.payment');
        $this->assertFalse($payment['collect_required']);
        $this->assertEquals(0, $payment['to_collect']);

        // An amount sent anyway is dropped, not recorded as cash.
        $this->deliver($order, ['collected_amount' => 50])->assertOk();

        $this->assertNull($this->leg($order, TaskType::DeliverToCustomer)->collected_amount);
        $this->assertSame(0, Payment::count());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    #[Test]
    public function the_release_starts_the_work_on_orders_the_old_confirmation_stranded(): void
    {
        // Confirmed the old way, after the handover: stuck at `confirmed`.
        $stranded = $this->place();
        $this->walk($stranded, TaskType::PickupFromCustomer);
        $this->walk($stranded->fresh(), TaskType::DeliverToLaundry);
        app(OrderReviewService::class)->review($stranded->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);
        $stranded->fresh()->forceFill(['status' => OrderStatus::Confirmed->value])->save();

        // Its legs ran ahead of it (#10053 on live): left as it is.
        $ranAhead = $this->place();
        $ranAhead->forceFill(['status' => OrderStatus::Confirmed->value])->save();
        OrderTask::where('order_id', $ranAhead->id)->whereIn('type', [TaskType::DeliverToLaundry->value, TaskType::CollectFromLaundry->value])
            ->update(['status' => 'completed']);

        (require database_path('migrations/2026_10_01_100200_start_the_work_on_confirmed_orders_already_at_the_laundry.php'))->up();

        $this->assertSame(OrderStatus::Cleaning, $stranded->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $ranAhead->fresh()->status);
    }

    // ------------------------------------------------------- no method is cash

    #[Test]
    public function an_order_with_no_payment_method_is_a_cash_order(): void
    {
        Setting::updateOrCreate(['key' => 'Cash_Surcharge'], ['value' => '10']);
        Cache::flush();

        $quote = app(OrderService::class)->quote($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
        ]);
        $order = $this->place();

        // Filed as cash, so the driver is shown cash — but charged the fee only
        // when cash was chosen: a quote with no method showed none, and the
        // total agreed is the total stored.
        $this->assertEquals(0, $quote['cash_surcharge']);
        $this->assertSame('cash', $order->payment_method);
        $this->assertEquals(0, $order->cash_surcharge);
        $this->assertEquals($quote['total'], (float) $order->estimated_total);

        $this->assertEquals(10, $this->place(['payment_method' => 'cash'])->cash_surcharge);
        $this->assertEquals(0, $this->place(['payment_method' => 'card'])->cash_surcharge);
    }

    #[Test]
    public function the_orders_placed_without_a_method_are_filed_as_cash(): void
    {
        $none = $this->place();
        $none->forceFill(['payment_method' => null])->save();
        $card = $this->place(['payment_method' => 'card']);

        (require database_path('migrations/2026_10_01_100100_file_orders_without_a_payment_method_as_cash.php'))->up();

        $this->assertSame('cash', $none->fresh()->payment_method);
        $this->assertSame('card', $card->fresh()->payment_method);
    }
}
