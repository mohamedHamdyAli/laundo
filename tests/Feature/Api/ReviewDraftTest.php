<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * «تحديد عملية فحص القطع من خلال المغسلة فقط … وبعدها الأدمن يبعت للعميل» —
 * the client's sheet, item 12 (2026-11-08).
 *
 * The laundry's count and price is a draft until the platform sends it: the
 * order does not move, the customer sees nothing of it, and only the platform
 * can send it on.
 */
class ReviewDraftTest extends TestCase
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

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $geo['zones'][0]);
    }

    #[Test]
    public function the_laundrys_count_is_a_draft_the_customer_does_not_see(): void
    {
        $admin = $this->superAdmin();
        $order = $this->counted(qty: 5);

        $this->assertSame(OrderStatus::PickedUp, $order->status);
        $this->assertTrue($order->hasReviewToSend());

        Sanctum::actingAs($this->customer);
        $detail = $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())->assertOk()->json('data');
        $this->assertNull($detail['pricing']['final_total']);
        $this->assertEquals((float) $order->estimated_total, $detail['pricing']['payable_total']);
        $this->assertSame(['estimated'], array_values(array_unique(array_column($detail['items'], 'phase'))));
        $this->assertSame(2, $detail['items_count']);

        $this->getJson("/api/v1/orders/{$order->id}/review", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.final', null)
            ->assertJsonPath('data.can_confirm', false);

        // Nor on the list, nor to pay (code review, 2026-11-08).
        $row = collect($this->getJson('/api/v1/orders', $this->apiHeaders())->assertOk()->json('data'))->firstWhere('id', $order->id);
        $this->assertEquals((float) $order->estimated_total, $row['total']);

        try {
            app(PaymentService::class)
                ->initiate($order->fresh(), $this->customer, PaymentMethod::Card);
            $this->fail('An unsent draft could be paid.');
        } catch (RuntimeException $e) {
            $this->assertSame('no_final_price', $e->getMessage());
        }

        // Nobody asked the customer anything; the platform was told.
        $this->assertDatabaseMissing('notification_logs', ['user_id' => $this->customer->id, 'event' => 'final_price_ready']);
        $this->assertTrue($admin->notifications()->get()->contains(
            fn ($n) => str_contains(json_encode($n->data, JSON_UNESCAPED_UNICODE), '#'.$order->code)
        ));
    }

    #[Test]
    public function the_platform_sends_it_and_the_customer_is_asked(): void
    {
        $order = $this->counted(qty: 5);

        app(OrderReviewService::class)->send($order, $this->platformOperator());

        $order->refresh();
        $this->assertSame(OrderStatus::Reviewed, $order->status);
        $this->assertTrue($order->customerSeesFinal());
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->customer->id, 'event' => 'final_price_ready']);

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())
            ->assertJsonPath('data.items_count', 5);

        // Nothing left to send.
        $this->expectExceptionMessage('nothing_to_send');
        app(OrderReviewService::class)->send($order->fresh(), $this->platformOperator());
    }

    #[Test]
    public function a_laundry_cannot_send_its_own_count(): void
    {
        $order = $this->counted(qty: 5);

        try {
            app(OrderReviewService::class)->send($order, $this->tenant['owner']);
            $this->fail('A laundry sent its own count to the customer.');
        } catch (RuntimeException $e) {
            $this->assertSame('platform_only', $e->getMessage());
        }

        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }

    #[Test]
    public function a_recount_after_a_dispute_is_a_new_draft_even_in_the_same_second(): void
    {
        $this->freezeSecond();
        $reviews = app(OrderReviewService::class);

        $order = $this->counted(qty: 5);
        $reviews->send($order, $this->platformOperator());
        $reviews->dispute($order->fresh(), $this->customer, 'count again');

        // The laundry recounts — the customer still sees the old count …
        $reviews->review($order->fresh(), [['item_id' => $this->catalog['items'][0]->id, 'qty' => 4]], null, $this->tenant['owner']);
        $order->refresh();
        $this->assertSame(OrderStatus::ReviewDisputed, $order->status);
        $this->assertTrue($order->hasReviewToSend());
        $this->assertFalse($order->customerSeesFinal());

        // … until the platform sends the new one.
        $reviews->send($order, $this->platformOperator());
        $this->assertSame(OrderStatus::Reviewed, $order->fresh()->status);
        $this->assertSame(4, $order->fresh()->final_items_count);
    }

    #[Test]
    public function the_list_filter_shows_it_until_it_is_sent(): void
    {
        $order = $this->counted(qty: 5);
        $admin = $this->superAdmin();

        $listed = fn () => $this->actingAs($admin)
            ->getJson(route('admin.order.search', ['query' => '', 'status' => 'review_to_send']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('table');

        $this->assertStringContainsString('#'.$order->code, $listed());

        app(OrderReviewService::class)->send($order, $this->platformOperator());

        $this->assertStringNotContainsString('#'.$order->code, $listed());
    }

    /**
     * Collected, and counted by the laundry — not sent.
     */
    private function counted(int $qty): Order
    {
        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        app(OrderReviewService::class)->review(
            $order->fresh(),
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => $qty]],
            null,
            $this->tenant['owner'],
        );

        return $order->fresh();
    }
}
