<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The laundry's review screen, and the boundary around it.
 *
 * The isolation claim is the sharp one: a laundry may price its own work and
 * nobody else's. The Order model's tenant scope is what enforces it, so these
 * tests exercise the real routes rather than the service, to prove the scope is
 * actually in the path.
 */
class OrderReviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        foreach ($this->geo['zones'] as $zone) {
            $zone->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
        }
    }

    #[Test]
    public function the_review_form_appears_only_once_the_pieces_are_here(): void
    {
        [$order, $owner] = $this->collectedOrder();

        // Before collection: nothing to count.
        $early = $this->placeOnly();
        $this->actingAs($owner)->get("/admin/order/show/{$early->id}")
            ->assertOk()
            ->assertDontSee('review-table');

        $this->actingAs($owner)->get("/admin/order/show/{$order->id}")
            ->assertOk()
            ->assertSee('review-table', false)
            ->assertSee(__('Send the final price to the customer'), false);
    }

    #[Test]
    public function the_form_offers_every_priced_piece_not_only_the_ones_ordered(): void
    {
        // Ordered: shirts only. The trousers must still be reachable, because
        // «تم العثور على قطعة إضافية أثناء المراجعة» is the whole point.
        [$order, $owner] = $this->collectedOrder([
            ['item_id' => $this->catalog['items'][0]->id, 'qty' => 2],
        ]);

        $response = $this->actingAs($owner)->get("/admin/order/show/{$order->id}");

        $response->assertOk();

        foreach ($this->catalog['items'] as $item) {
            $response->assertSee(getLocalizedValueDashboard($item, 'name'), false);
        }
    }

    #[Test]
    public function submitting_a_review_prices_the_order_and_asks_the_customer(): void
    {
        [$order, $owner] = $this->collectedOrder();

        $this->actingAs($owner)->post("/admin/order/review/{$order->id}", [
            'lines' => [
                ['item_id' => $this->catalog['items'][0]->id, 'qty' => 3],
                ['item_id' => $this->catalog['items'][1]->id, 'qty' => 0],
            ],
            'note' => 'تم العثور على قطعة إضافية',
        ])->assertRedirect()->assertSessionHas('success');

        $order->refresh();

        // 3 x 17 = 51. The zero-count line is dropped, not stored.
        $this->assertSame(OrderStatus::Reviewed, $order->status);
        $this->assertSame(3, $order->final_items_count);
        $this->assertSame('51.00', $order->final_subtotal);
        $this->assertSame('تم العثور على قطعة إضافية', $order->review_note);

        $this->assertDatabaseMissing('order_items', [
            'order_id' => $order->id,
            'item_id' => $this->catalog['items'][1]->id,
            'phase' => 'final',
        ]);

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'to_status' => 'reviewed',
            'actor_type' => 'laundry',
            'actor_id' => $owner->id,
        ]);
    }

    #[Test]
    public function a_laundry_cannot_price_another_laundrys_order(): void
    {
        [$order] = $this->collectedOrder();

        $intruder = $this->laundryWithOwner('B', '+201022220001', '+201022220002');

        // The tenant scope makes the row invisible, so findOrFail 404s — there is
        // no ownership check to forget.
        $this->actingAs($intruder['owner'])->post("/admin/order/review/{$order->id}", [
            'lines' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 99]],
        ])->assertNotFound();

        $this->assertNull($order->fresh()->final_total);
    }

    #[Test]
    public function reviewing_is_gated_on_the_update_permission(): void
    {
        [$order, $owner] = $this->collectedOrder();

        // Laundry staff read orders; pricing them is the owner's call.
        $this->grant('laundry_owner', ['laundry.view', 'order.view']);

        $this->actingAs($owner)->post("/admin/order/review/{$order->id}", [
            'lines' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ])->assertForbidden();

        // And the form is not rendered either.
        $this->actingAs($owner)->get("/admin/order/show/{$order->id}")
            ->assertOk()
            ->assertDontSee('review-table');
    }

    #[Test]
    public function an_empty_review_is_rejected_with_a_message_not_a_crash(): void
    {
        [$order, $owner] = $this->collectedOrder();

        $this->actingAs($owner)->post("/admin/order/review/{$order->id}", [
            'lines' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 0]],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertNull($order->fresh()->final_total);
    }

    #[Test]
    public function reviewing_an_order_at_the_wrong_stage_is_refused(): void
    {
        [$order, $owner] = $this->collectedOrder();
        $order->update(['status' => OrderStatus::Cleaning]);

        $this->actingAs($owner)->post("/admin/order/review/{$order->id}", [
            'lines' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertNull($order->fresh()->final_total);
    }

    #[Test]
    public function the_detail_page_shows_the_difference_the_review_made(): void
    {
        [$order, $owner] = $this->collectedOrder([
            ['item_id' => $this->catalog['items'][0]->id, 'qty' => 2],
        ]);

        $this->actingAs($owner)->post("/admin/order/review/{$order->id}", [
            'lines' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 3]],
        ]);

        $response = $this->actingAs($owner)->get("/admin/order/show/{$order->id}");

        $response->assertOk()
            ->assertSee(__('Final total'), false)
            ->assertSee(__('Difference'), false)
            // And that the order is now waiting on somebody else.
            ->assertSee(__('Waiting for the customer to confirm the final price.'), false);

        $this->assertEquals(17.0, $order->fresh()->priceDifference());
    }

    #[Test]
    public function a_price_question_surfaces_and_can_be_answered(): void
    {
        [$order, $owner] = $this->collectedOrder();

        $query = OrderPriceQuery::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'message' => 'ليه السعر زاد؟',
        ]);

        $this->actingAs($owner)->get("/admin/order/show/{$order->id}")
            ->assertOk()
            ->assertSee('ليه السعر زاد؟', false)
            ->assertSee(__('unanswered'), false);

        $this->actingAs($owner)->post("/admin/order/query/{$order->id}", [
            'query_id' => $query->id,
            'answer' => 'اتحسبت قطعة زيادة',
        ])->assertRedirect()->assertSessionHas('success');

        $query->refresh();
        $this->assertNotNull($query->answered_at);
        $this->assertSame($owner->id, $query->answered_by);
        $this->assertSame('اتحسبت قطعة زيادة', $query->answer);
    }

    #[Test]
    public function a_laundry_cannot_answer_a_question_on_another_laundrys_order(): void
    {
        [$order] = $this->collectedOrder();

        $query = OrderPriceQuery::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'message' => 'سؤال',
        ]);

        $intruder = $this->laundryWithOwner('B', '+201022220001', '+201022220002');

        $this->actingAs($intruder['owner'])->post("/admin/order/query/{$order->id}", [
            'query_id' => $query->id,
            'answer' => 'رد من مغسلة تانية',
        ])->assertNotFound();

        $this->assertNull($query->fresh()->answered_at);
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function collectedOrder(?array $items = null): array
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => $items ?? [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        return [$order, $tenant['owner']];
    }

    /** An order that has not been collected yet, on an already-covered zone. */
    private function placeOnly(): Order
    {
        $customer = $this->customer('+201099887799');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);
    }
}
