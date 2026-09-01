<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Pricing\Models\ItemPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «تحميل الفاتورة».
 *
 * The claim worth protecting: an invoice is assembled from the order's stored
 * figures and never recomputed. A document that recalculates itself can disagree
 * with the copy the customer already holds — and theirs is the one produced in an
 * argument.
 */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
    }

    #[Test]
    public function an_invoice_renders_with_the_orders_own_figures(): void
    {
        $order = $this->reviewedOrder();

        $response = $this->actingAs($this->superAdmin())->get("/admin/order/invoice/{$order->id}");

        $response->assertOk()
            ->assertSee('INV-'.$order->code, false)
            ->assertSee(getLocalizedValueDashboard($this->catalog['items'][0], 'name'), false)
            ->assertSee(__('Unpaid'), false);

        // 2 x 17 = 34 plus the delivery fee.
        $response->assertSee(number_format($order->payableTotal(), 2), false);
    }

    #[Test]
    public function an_invoice_does_not_follow_a_later_price_change(): void
    {
        $order = $this->reviewedOrder();
        $before = $order->payableTotal();

        // The matrix moves afterwards. The document must not.
        ItemPrice::where('item_id', $this->catalog['items'][0]->id)->update(['price' => 999]);

        $response = $this->actingAs($this->superAdmin())->get("/admin/order/invoice/{$order->id}");

        $response->assertOk()
            ->assertSee(number_format($before, 2), false)
            ->assertDontSee('999.00', false);
    }

    #[Test]
    public function an_unreviewed_order_says_plainly_that_it_is_an_estimate(): void
    {
        $order = $this->placedOrder();
        $this->assertFalse($order->hasFinalPrice());

        // An estimate that looks like a bill is how a dispute starts.
        $this->actingAs($this->superAdmin())->get("/admin/order/invoice/{$order->id}")
            ->assertOk()
            ->assertSee(__('Estimated — the final price is set after the pieces are reviewed.'), false);
    }

    #[Test]
    public function a_paid_order_shows_as_paid(): void
    {
        $order = $this->reviewedOrder();
        $order->update(['payment_status' => 'paid', 'paid_at' => now(), 'payment_method' => 'card']);

        $this->actingAs($this->superAdmin())->get("/admin/order/invoice/{$order->id}")
            ->assertOk()
            ->assertSee(__('Paid'), false);
    }

    #[Test]
    public function a_laundry_can_print_its_own_invoice_and_no_one_elses(): void
    {
        $order = $this->reviewedOrder();

        $this->actingAs($this->tenant['owner'])->get("/admin/order/invoice/{$order->id}")->assertOk();

        $intruder = $this->laundryWithOwner('B', '01022220001', '01022220002');
        $this->actingAs($intruder['owner'])->get("/admin/order/invoice/{$order->id}")->assertNotFound();
    }

    #[Test]
    public function the_invoice_is_gated_on_the_view_permission(): void
    {
        $order = $this->reviewedOrder();

        $this->grant('laundry_owner', ['laundry.view']);

        $this->actingAs($this->tenant['owner'])->get("/admin/order/invoice/{$order->id}")
            ->assertForbidden();
    }

    #[Test]
    public function the_order_page_links_to_the_invoice(): void
    {
        $order = $this->reviewedOrder();

        $this->actingAs($this->superAdmin())->get("/admin/order/show/{$order->id}")
            ->assertOk()
            ->assertSee("/admin/order/invoice/{$order->id}", false);
    }

    private function placedOrder(): Order
    {
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

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
