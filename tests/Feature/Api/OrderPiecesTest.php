<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The client's sheet, item 2 (2026-11-08): «أعداد القطع لسه بتظهر للعميل غير
 * مطابقة بعد ما سلّم المندوب عدد أكبر». The order screen listed the basket and
 * the laundry's count together; the owner's answer is that the count is the
 * order once the customer is shown it, and an extra piece reads «added».
 */
class OrderPiecesTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $tenant;

    private User $customer;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $geo['zones'][0]);
    }

    #[Test]
    public function before_the_count_the_order_is_the_basket(): void
    {
        $order = $this->collectedOrder([['item_id' => $this->shirt(), 'qty' => 2]]);

        $data = $this->show($order);

        $this->assertCount(1, $data['items']);
        $this->assertSame('estimated', $data['items'][0]['phase']);
        $this->assertNull($data['items'][0]['change']);
        $this->assertSame(2, $data['items_count']);
        $this->assertNull($data['estimated_items'][0]['counted']);
        $this->assertSame(0, $data['pieces_added']);
    }

    #[Test]
    public function after_the_count_the_order_is_the_laundrys_count_and_extra_pieces_read_added(): void
    {
        // Ordered two shirts, handed the driver four and a pair of trousers.
        $order = $this->collectedOrder([['item_id' => $this->shirt(), 'qty' => 2]]);
        $this->review($order, [
            ['item_id' => $this->shirt(), 'qty' => 4],
            ['item_id' => $this->trousers(), 'qty' => 1],
        ]);

        $data = $this->show($order);

        // One list, the count's — never the basket beside it.
        $this->assertSame(['final', 'final'], array_column($data['items'], 'phase'));
        $this->assertSame(5, $data['items_count']);
        $this->assertSame(5, array_sum(array_column($data['items'], 'qty')));

        $changes = array_column($data['items'], 'change', 'item_id');
        $this->assertSame('more', $changes[$this->shirt()]);
        $this->assertSame('added', $changes[$this->trousers()]);

        $this->assertSame(3, $data['pieces_added']);
        $this->assertSame(0, $data['pieces_missing']);

        // The basket stays readable, with what was found against it.
        $this->assertSame(2, $data['estimated_items'][0]['qty']);
        $this->assertSame(4, $data['estimated_items'][0]['counted']);
        $this->assertSame('more', $data['estimated_items'][0]['change']);
    }

    #[Test]
    public function a_piece_not_received_is_named_on_the_basket(): void
    {
        $order = $this->collectedOrder([
            ['item_id' => $this->shirt(), 'qty' => 2],
            ['item_id' => $this->trousers(), 'qty' => 1],
        ]);
        $this->review($order, [['item_id' => $this->shirt(), 'qty' => 2]]);

        $data = $this->show($order);

        $this->assertCount(1, $data['items']);
        $this->assertSame('same', $data['items'][0]['change']);

        $basket = collect($data['estimated_items'])->keyBy('item_id');
        $this->assertSame('missing', $basket[$this->trousers()]['change']);
        $this->assertSame(0, $basket[$this->trousers()]['counted']);
        $this->assertSame(1, $data['pieces_missing']);
    }

    #[Test]
    public function the_price_review_marks_each_line_the_same_way(): void
    {
        $order = $this->collectedOrder([['item_id' => $this->shirt(), 'qty' => 2]]);
        $this->review($order, [
            ['item_id' => $this->shirt(), 'qty' => 2],
            ['item_id' => $this->trousers(), 'qty' => 1],
        ]);

        Sanctum::actingAs($this->customer);
        $data = $this->getJson("/api/v1/orders/{$order->id}/review", $this->apiHeaders())
            ->assertOk()
            ->json('data');

        $changes = array_column($data['final']['lines'], 'change', 'item_id');
        $this->assertSame('same', $changes[$this->shirt()]);
        $this->assertSame('added', $changes[$this->trousers()]);
        $this->assertSame(1, $data['pieces_added']);
        $this->assertSame('same', $data['estimated']['lines'][0]['change']);
    }

    private function shirt(): int
    {
        return $this->catalog['items'][0]->id;
    }

    private function trousers(): int
    {
        return $this->catalog['items'][1]->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function show(Order $order): array
    {
        Sanctum::actingAs($this->customer);

        return $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())
            ->assertOk()
            ->json('data');
    }

    private function collectedOrder(array $items): Order
    {
        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => $items,
            'accepts_review_terms' => true,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');

        return $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');
    }

    /**
     * The laundry's count, sent to the customer by the platform (item 12) —
     * what the customer sees is what was sent.
     */
    private function review(Order $order, array $lines): void
    {
        app(OrderReviewService::class)->review($order->fresh(), $lines, null, $this->tenant['owner']);
        $this->sendReview($order);
    }
}
