<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Models\Refund;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The panel's one destructive action on an order.
 *
 * The rule being protected is not «deleting is dangerous» in the abstract —
 * eleven tables cascade off an order row and `wallet_transactions` does not
 * cascade at all, so the question each of these asks is whether a record that
 * money moved can be destroyed from a list screen. It cannot.
 */
class OrderDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        $this->admin = $this->superAdmin();
        $this->buyer = $this->customer();
    }

    private function order(string $status = 'awaiting_pickup'): Order
    {
        return Order::create([
            'code' => Order::generateCode(),
            'user_id' => $this->buyer->id,
            'service_id' => $this->catalog['service']->id,
            'status' => $status,
            'pickup_address_id' => $this->addressFor($this->buyer, $this->geo['zones'][0])->id,
            'delivery_address_id' => $this->addressFor($this->buyer, $this->geo['zones'][0])->id,
            'delivery_fee' => 20,
            'estimated_total' => 100,
            'qr_token' => Order::generateQrToken(),
        ]);
    }

    private function deleteOrder(Order $order)
    {
        return $this->actingAs($this->admin)
            ->delete(route('admin.order.delete', $order->id));
    }

    // ------------------------------------------------------------- it works

    #[Test]
    public function an_untouched_order_can_be_deleted(): void
    {
        $order = $this->order();

        $this->deleteOrder($order)
            ->assertRedirect(route('admin.order.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    #[Test]
    public function the_rows_hanging_off_it_go_with_it(): void
    {
        // The cascade is the reason the guard exists, so it is worth one test
        // proving it really is a cascade and not eleven orphans.
        $order = $this->order();

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->catalog['items'][0]->id,
            'phase' => 'estimated',
            'qty' => 2,
            'unit_price' => 30,
            'line_total' => 60,
        ]);

        OrderTask::create([
            'order_id' => $order->id,
            'type' => 'pickup_from_customer',
            'sequence' => 1,
            'status' => 'pending',
        ]);

        $this->deleteOrder($order)->assertSessionHas('success');

        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('order_tasks', ['order_id' => $order->id]);
    }

    #[Test]
    public function a_cancelled_order_is_still_deletable(): void
    {
        // Cancelled is not «in custody»: nothing was collected, so there is no
        // record of somebody's clothes to protect.
        $order = $this->order(OrderStatus::Cancelled->value);

        $this->deleteOrder($order)->assertSessionHas('success');
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    // --------------------------------------------------------- it refuses

    #[Test]
    public function an_order_already_collected_cannot_be_deleted(): void
    {
        // Somebody is holding this customer's clothes. The order row is the only
        // record of whose they are and where they go back to.
        $order = $this->order(OrderStatus::PickedUp->value);

        $this->deleteOrder($order)->assertSessionHas('error');
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    #[Test]
    public function every_status_in_custody_is_refused(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if (! $status->isInCustody()) {
                continue;
            }

            $order = $this->order($status->value);

            $this->deleteOrder($order)->assertSessionHas('error');

            // No message argument: assertDatabaseHas's third parameter is the
            // connection name, and passing a sentence there asks Laravel for a
            // database called «picked_up should not be deletable».
            $this->assertDatabaseHas('orders', ['id' => $order->id]);
        }
    }

    #[Test]
    public function a_captured_payment_refuses_the_delete(): void
    {
        $order = $this->order();

        Payment::create([
            'order_id' => $order->id,
            'user_id' => $this->buyer->id,
            'provider' => 'cash',
            'method' => 'cash',
            'status' => PaymentStatus::Captured->value,
            'amount' => 100,
        ]);

        $this->deleteOrder($order)->assertSessionHas('error');
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    #[Test]
    public function a_pending_payment_does_not(): void
    {
        // Nothing moved. A row the gateway wrote on the way to an outcome that
        // never arrived is not a financial record, and treating it as one would
        // make almost every order undeletable for no reason.
        $order = $this->order();

        Payment::create([
            'order_id' => $order->id,
            'user_id' => $this->buyer->id,
            'provider' => 'cash',
            'method' => 'cash',
            'status' => PaymentStatus::Pending->value,
            'amount' => 100,
        ]);

        $this->deleteOrder($order)->assertSessionHas('success');
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    #[Test]
    public function a_wallet_transaction_refuses_the_delete(): void
    {
        // The one that would not cascade. It names its source polymorphically,
        // so deleting the order leaves a ledger entry pointing at nothing.
        $order = $this->order();

        $wallet = Wallet::create(['user_id' => $this->buyer->id, 'balance' => 50]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'direction' => 'debit',
            'amount' => 50,
            'reason' => 'order_payment',
            'balance_after' => 0,
            'source_type' => Order::class,
            'source_id' => $order->id,
        ]);

        $this->deleteOrder($order)->assertSessionHas('error');
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    #[Test]
    public function a_settlement_refuses_the_delete(): void
    {
        $order = $this->order();
        $laundry = $this->laundryWithOwner('S', '+201033330001', '+201033330002')['laundry'];

        OrderSettlement::create([
            'order_id' => $order->id,
            'laundry_id' => $laundry->id,
            'basis' => 100,
            'commission_rate' => 10,
            'commission_amount' => 10,
            'laundry_amount' => 90,
            'status' => 'pending',
        ]);

        $this->deleteOrder($order)->assertSessionHas('error');
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    #[Test]
    public function a_driver_earning_refuses_the_delete(): void
    {
        $order = $this->order();
        $driver = $this->driverUser();

        $task = OrderTask::create([
            'order_id' => $order->id,
            'type' => 'deliver_to_customer',
            'sequence' => 4,
            'status' => 'completed',
            'driver_id' => $driver->id,
        ]);

        DriverEarning::create([
            'order_id' => $order->id,
            'order_task_id' => $task->id,
            'driver_id' => $driver->id,
            'amount' => 25,
            'basis' => 100,
            'rate' => 0.25,
            'status' => 'pending',
        ]);

        $this->deleteOrder($order)->assertSessionHas('error');
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    #[Test]
    public function a_refund_refuses_the_delete(): void
    {
        $order = $this->order();

        Refund::create([
            'order_id' => $order->id,
            'user_id' => $this->buyer->id,
            'amount' => 40,
            'reason' => 'goodwill',
            'status' => 'completed',
        ]);

        $this->deleteOrder($order)->assertSessionHas('error');
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    // ------------------------------------------------------- who may press it

    #[Test]
    public function the_delete_needs_the_permission(): void
    {
        $order = $this->order();

        $operator = User::create([
            'name' => 'Reader',
            'email' => 'reader@example.test',
            'phone' => '+201044440001',
            'password' => bcrypt('secret123'),
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'status' => 'active',
        ]);

        $this->grant('admin', ['order.view']);

        $this->actingAs($operator)
            ->delete(route('admin.order.delete', $order->id))
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    #[Test]
    public function the_detail_screen_says_why_rather_than_going_quiet(): void
    {
        // The list can only afford the status half of the guard, so the detail
        // screen is the only place the money half can be explained before it is
        // hit. A header that simply has no delete button teaches nothing.
        $order = $this->order();

        Payment::create([
            'order_id' => $order->id,
            'user_id' => $this->buyer->id,
            'provider' => 'cash',
            'method' => 'cash',
            'status' => PaymentStatus::Captured->value,
            'amount' => 100,
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('admin.order.delete', $order->id), $html);
        $this->assertStringContainsString('A payment has been recorded', $html);
    }

    #[Test]
    public function the_detail_screen_offers_the_delete_when_it_is_allowed(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->get(route('admin.order.show', $order->id))
            ->assertOk()
            ->assertSee(route('admin.order.delete', $order->id), false);
    }

    #[Test]
    public function the_button_is_not_drawn_for_an_order_in_custody(): void
    {
        $deletable = $this->order();
        $collected = $this->order(OrderStatus::PickedUp->value);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.order.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('admin.order.delete', $deletable->id), $html
        );
        $this->assertStringNotContainsString(
            route('admin.order.delete', $collected->id), $html
        );
    }
}
