<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Services\RecurrenceService;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The final price: the laundry sets it, the customer agrees to it.
 *
 * The claims worth protecting here are commercial, not technical. The estimated
 * basket must survive the review so the comparison the design draws is always
 * reconstructable; the final prices must be copied rather than referenced; and
 * nothing may be cleaned before the customer has agreed to what it costs.
 */
class OrderReviewTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private User $customer;

    private array $tenant;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    // ---------------------------------------------------------------- the review

    #[Test]
    public function a_review_prices_what_arrived_and_leaves_the_estimate_alone(): void
    {
        // The customer said 2 shirts; 3 shirts and a pair of trousers turned up.
        $order = $this->collectedOrder([['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]]);

        $this->review($order, [
            ['item_id' => $this->catalog['items'][0]->id, 'qty' => 3],
            ['item_id' => $this->catalog['items'][1]->id, 'qty' => 1],
        ], 'تم العثور على قطعة إضافية أثناء المراجعة');

        $order->refresh();

        // 3 x 17 + 1 x 23 = 74, plus the delivery fee carried over.
        $this->assertSame(4, $order->final_items_count);
        $this->assertSame('74.00', $order->final_subtotal);
        $this->assertSame(
            number_format(74 + (float) $order->delivery_fee, 2, '.', ''),
            $order->final_total
        );
        $this->assertSame(OrderStatus::Reviewed, $order->status);
        $this->assertSame(1, $order->review_round);
        $this->assertNotNull($order->reviewed_at);

        // The original agreement is untouched — this is what makes the design's
        // «مقارنة القطع» reconstructable at any later date.
        $this->assertSame(2, $order->estimated_items_count);
        $this->assertSame('34.00', $order->estimated_subtotal);

        $estimated = OrderItem::where('order_id', $order->id)->where('phase', 'estimated')->get();
        $this->assertCount(1, $estimated);
        $this->assertSame(2, $estimated->first()->qty);

        $this->assertCount(2, OrderItem::where('order_id', $order->id)->where('phase', 'final')->get());
    }

    #[Test]
    public function final_prices_are_copied_not_referenced(): void
    {
        $order = $this->collectedOrder();
        $this->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]]);

        // Move the matrix afterwards. The order must not follow.
        ItemPrice::where('item_id', $this->catalog['items'][0]->id)->update(['price' => 99]);

        $this->assertSame('34.00', $order->fresh()->final_subtotal);
        $this->assertSame(
            '17.00',
            OrderItem::where('order_id', $order->id)->where('phase', 'final')->value('unit_price')
        );
    }

    #[Test]
    public function a_review_reprices_pieces_only_never_the_trip(): void
    {
        $order = $this->collectedOrder();
        $feeBefore = $order->delivery_fee;

        $this->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 5]]);

        // The laundry counted clothes, not kilometres.
        $this->assertSame($feeBefore, $order->fresh()->delivery_fee);
    }

    #[Test]
    public function a_piece_the_service_cannot_price_is_refused(): void
    {
        $order = $this->collectedOrder();
        $orphan = $this->catalog['items'][1];
        ItemPrice::where('item_id', $orphan->id)->delete();

        $this->expectException(RuntimeException::class);

        $this->review($order, [['item_id' => $orphan->id, 'qty' => 1]]);
    }

    #[Test]
    public function a_review_of_nothing_is_refused(): void
    {
        $order = $this->collectedOrder();

        try {
            // Everything counted down to zero: the pieces are physically here,
            // somebody just did not enter them.
            $this->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 0]]);
            $this->fail('An empty review was accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('empty_review', $e->getMessage());
        }

        $this->assertNull($order->fresh()->final_total);
    }

    #[Test]
    public function an_order_cannot_be_reviewed_before_it_is_collected(): void
    {
        $order = $this->placeOrder();
        $this->assertSame(OrderStatus::AwaitingPickup, $order->status);

        try {
            $this->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]]);
            $this->fail('An uncollected order was reviewed.');
        } catch (RuntimeException $e) {
            $this->assertSame('not_reviewable', $e->getMessage());
        }
    }

    #[Test]
    public function a_reviewed_order_cannot_be_reviewed_again_without_a_dispute(): void
    {
        $order = $this->collectedOrder();
        $this->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]]);

        try {
            $this->review($order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 9]]);
            $this->fail('A price was rewritten while the customer was looking at it.');
        } catch (RuntimeException $e) {
            $this->assertSame('not_reviewable', $e->getMessage());
        }
    }

    // ------------------------------------------------------------ the customer

    #[Test]
    public function the_review_endpoint_returns_the_comparison_the_design_draws(): void
    {
        $order = $this->collectedOrder([['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]]);
        $this->review($order, [
            ['item_id' => $this->catalog['items'][0]->id, 'qty' => 3],
        ], 'قطعة إضافية');

        Sanctum::actingAs($this->customer);

        $response = $this->getJson("/api/v1/orders/{$order->id}/review", $this->apiHeaders());

        $response->assertOk()
            ->assertJsonPath('data.estimated.items_count', 2)
            ->assertJsonPath('data.final.items_count', 3)
            ->assertJsonPath('data.note', 'قطعة إضافية')
            ->assertJsonPath('data.review_round', 1)
            ->assertJsonPath('data.can_confirm', true)
            ->assertJsonPath('data.can_dispute', true);

        // 51 + fee, against 34 + fee — the difference is exactly the extra shirt.
        $this->assertEquals(17.0, $response->json('data.difference'));
        $this->assertCount(1, $response->json('data.estimated.lines'));
        $this->assertCount(1, $response->json('data.final.lines'));
    }

    #[Test]
    public function confirming_releases_the_work_without_taking_money(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/confirm", ['payment_method' => 'cash'], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertSame('cash', $order->payment_method);

        // The whole point: agreement moved the order, money did not.
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNull($order->paid_at);

        // And the laundry may now start.
        $this->assertTrue($order->status->canTransitionTo(OrderStatus::Cleaning));
    }

    #[Test]
    public function nothing_is_cleaned_before_the_customer_agrees(): void
    {
        $order = $this->reviewedOrder();

        $this->expectException(RuntimeException::class);

        app(OrderStateMachine::class)->transition($order, OrderStatus::Cleaning, 'laundry');
    }

    #[Test]
    public function a_disputed_price_goes_back_for_a_second_count(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/dispute", ['reason' => 'العدد غلط'], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'review_disputed');

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'to_status' => 'review_disputed',
            'note' => 'العدد غلط',
        ]);

        // The laundry can price it again, and the round counter remembers.
        $order->refresh();
        $this->assertTrue($order->status->isReviewable());

        $this->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]]);

        $order->refresh();
        $this->assertSame(OrderStatus::Reviewed, $order->status);
        $this->assertSame(2, $order->review_round);
        $this->assertSame('34.00', $order->final_subtotal);

        // A re-review replaces the previous final set rather than stacking on it.
        $this->assertCount(1, OrderItem::where('order_id', $order->id)->where('phase', 'final')->get());
    }

    #[Test]
    public function a_price_question_records_itself_without_moving_the_order(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson(
            "/api/v1/orders/{$order->id}/queries",
            ['message' => 'ليه السعر زاد؟'],
            $this->apiHeaders()
        )->assertCreated();

        // A question is not a refusal, and the order must not pretend otherwise.
        $this->assertSame(OrderStatus::Reviewed, $order->fresh()->status);

        $this->assertDatabaseHas('order_price_queries', [
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'message' => 'ليه السعر زاد؟',
            'answered_at' => null,
        ]);

        $this->getJson("/api/v1/orders/{$order->id}/queries", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.answered', false);
    }

    #[Test]
    public function confirming_twice_is_refused(): void
    {
        $order = $this->reviewedOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/confirm", [], $this->apiHeaders())->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/confirm", [], $this->apiHeaders())->assertStatus(400);
        $this->postJson("/api/v1/orders/{$order->id}/dispute", [], $this->apiHeaders())->assertStatus(400);
    }

    #[Test]
    public function a_price_cannot_be_confirmed_before_one_exists(): void
    {
        $order = $this->collectedOrder();

        // Moved by hand, without a review having set a figure.
        app(OrderStateMachine::class)->transition($order, OrderStatus::Reviewed, 'laundry');

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/orders/{$order->id}/confirm", [], $this->apiHeaders())->assertStatus(400);
        $this->assertSame(OrderStatus::Reviewed, $order->fresh()->status);
    }

    #[Test]
    public function a_customer_cannot_touch_another_customers_review(): void
    {
        $order = $this->reviewedOrder();

        $stranger = $this->customer('+201088776655');
        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/orders/{$order->id}/review", $this->apiHeaders())->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/confirm", [], $this->apiHeaders())->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/dispute", [], $this->apiHeaders())->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/queries", ['message' => 'x'], $this->apiHeaders())
            ->assertNotFound();

        $this->assertSame(OrderStatus::Reviewed, $order->fresh()->status);
    }

    #[Test]
    public function the_review_endpoints_require_a_token(): void
    {
        $order = $this->reviewedOrder();

        $this->getJson("/api/v1/orders/{$order->id}/review", $this->apiHeaders())->assertUnauthorized();
        $this->postJson("/api/v1/orders/{$order->id}/confirm", [], $this->apiHeaders())->assertUnauthorized();
    }

    // ------------------------------------------------------------- the P6 gap

    #[Test]
    public function an_order_records_the_customers_consent_to_being_repriced(): void
    {
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/orders', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $this->assertNotNull(Order::withoutGlobalScopes()->firstOrFail()->review_terms_accepted_at);
    }

    #[Test]
    public function an_order_without_that_consent_is_refused(): void
    {
        Sanctum::actingAs($this->customer);

        foreach ([[], ['accepts_review_terms' => false]] as $extra) {
            $this->postJson('/api/v1/orders', $extra + [
                'service_id' => $this->catalog['service']->id,
                'pickup_address_id' => $this->address->id,
                'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            ], $this->apiHeaders())->assertStatus(422);
        }

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_recurring_order_carries_the_consent_too(): void
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

        $prompt = RecurrencePrompt::firstOrFail();
        $order = $recurrences->confirm($prompt, $this->customer);

        // Otherwise a recurring order would reach the laundry with no record that
        // anybody agreed to being re-priced.
        $this->assertNotNull($order->review_terms_accepted_at);
    }

    // ------------------------------------------------------------------ helpers

    private function placeOrder(?array $items = null): Order
    {
        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => $items ?? [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    /** An order the driver has already collected — the point a review is legal. */
    private function collectedOrder(?array $items = null): Order
    {
        $order = $this->placeOrder($items);
        $machine = app(OrderStateMachine::class);

        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');

        return $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');
    }

    /** Collected, counted and priced — waiting on the customer. */
    private function reviewedOrder(): Order
    {
        $order = $this->collectedOrder();
        $this->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 3]]);

        return $order->fresh();
    }

    private function review(Order $order, array $lines, ?string $note = null): Order
    {
        return app(OrderReviewService::class)->review($order, $lines, $note, $this->tenant['owner']);
    }
}
