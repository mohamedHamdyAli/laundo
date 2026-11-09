<?php

namespace Tests\Feature\Api;

use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\Loyalty\Services\PointsService;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The client's sheet, items 13–15 (2026-11-08): the driver's profile counts
 * the orders they took part in that went all the way; the customer's counts the
 * orders they placed that did, and their points used and left.
 */
class ProfileCountsTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer();
    }

    #[Test]
    public function the_customer_sees_their_completed_orders_and_points(): void
    {
        $this->order($this->customer, OrderStatus::Completed);
        $this->order($this->customer, OrderStatus::Completed);
        // Not completed: cancelled, still moving, delivered but unpaid.
        $this->order($this->customer, OrderStatus::Cancelled);
        $this->order($this->customer, OrderStatus::Cleaning);
        $this->order($this->customer, OrderStatus::Delivered);
        // Somebody else's.
        $this->order($this->customer('+201099887700'), OrderStatus::Completed);

        $points = app(PointsService::class);
        $points->credit($this->customer, 100, PointReason::OrderEarned);
        $spend = $points->debit($this->customer, 30, PointReason::Redeemed);
        // Ten came back from an order counted lower.
        $points->giveBack($spend, keep: 20);
        // An operator's correction is not spending.
        $points->debit($this->customer, 5, PointReason::Adjustment);

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/profile', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.completed_orders_count', 2)
            ->assertJsonPath('data.points_used', 20)
            ->assertJsonPath('data.points_remaining', 75);

        $this->getJson('/api/v1/points', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.used', 20)
            ->assertJsonPath('data.balance', 75);
    }

    #[Test]
    public function a_new_customer_sees_zeros(): void
    {
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/profile', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.completed_orders_count', 0)
            ->assertJsonPath('data.points_used', 0)
            ->assertJsonPath('data.points_remaining', 0);
    }

    #[Test]
    public function the_driver_sees_the_completed_orders_they_took_part_in(): void
    {
        $driver = $this->driverUser('+201044440001');
        $other = $this->driverUser('+201044440002');

        // Two legs of one completed order: one order, two trips.
        $first = $this->order($this->customer, OrderStatus::Completed);
        $this->leg($first, TaskType::PickupFromCustomer, $driver);
        $this->leg($first, TaskType::DeliverToCustomer, $driver);
        // Only the trip to the laundry of another completed order.
        $second = $this->order($this->customer, OrderStatus::Completed);
        $this->leg($second, TaskType::DeliverToLaundry, $driver);
        // A leg done on an order that is not completed yet: a trip, not an order.
        $moving = $this->order($this->customer, OrderStatus::Cleaning);
        $this->leg($moving, TaskType::PickupFromCustomer, $driver);
        // A leg assigned and not done, and another driver's.
        $this->leg($second, TaskType::DeliverToCustomer, $driver, TaskStatus::Assigned);
        $this->leg($second, TaskType::PickupFromCustomer, $other);

        Sanctum::actingAs($driver);
        $this->getJson('/api/v1/driver/profile', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.completed_orders_count', 2)
            ->assertJsonPath('data.completed_trips_count', 4);
    }

    private function order(User $customer, OrderStatus $status): Order
    {
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        return Order::withoutGlobalScopes()->create([
            'code' => Order::generateCode(), 'user_id' => $customer->id,
            'service_id' => $this->catalog['service']->id, 'status' => $status->value,
            'pickup_address_id' => $address->id, 'delivery_address_id' => $address->id,
            'estimated_total' => 100, 'payment_method' => 'cash', 'qr_token' => Order::generateQrToken(),
        ]);
    }

    private function leg(Order $order, TaskType $type, $driver, TaskStatus $status = TaskStatus::Completed): OrderTask
    {
        return OrderTask::create([
            'order_id' => $order->id, 'type' => $type, 'sequence' => $type->sequence(),
            'status' => $status, 'driver_id' => $driver->id,
            'completed_at' => $status === TaskStatus::Completed ? now() : null,
        ]);
    }
}
