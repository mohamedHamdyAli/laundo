<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Services\OrderService;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «تنظيف جاف» — pricing a service that has no price list.
 *
 * A quote-mode service carries no catalogue prices by design: what a suit costs
 * depends on the fabric and the stain, which is why it is costed after the pieces
 * are inspected. Until now the review screen simply rendered nothing for one —
 * the order could be placed and then never priced, which stranded it at
 * `picked_up` with no way forward.
 *
 * The rule that matters most here is the one about the *other* services: a price
 * arriving in the request must never be able to re-cost an item the platform sets
 * the price of.
 */
class QuotePricedReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $tenant;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->customer = $this->customer('01055550001');
    }

    private function order(bool $quoted): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);
        $service = $quoted ? $this->catalog['quoted'] : $this->catalog['service'];

        $payload = [
            'service_id' => $service->id,
            'pickup_address_id' => $address->id,
            'accepts_review_terms' => true,
        ];

        // A quoted basket produces no priced lines at all, so the order arrives
        // at the laundry with nothing on it — which is exactly the state this
        // screen has to be able to work from.
        if (! $quoted) {
            $payload['items'] = [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]];
        }

        $order = app(OrderService::class)->place($this->customer, $payload);

        $order->forceFill([
            'laundry_id' => $this->tenant['laundry']->id,
            'status' => OrderStatus::PickedUp->value,
        ])->save();

        return $order->fresh();
    }

    // ------------------------------------------------------------- the screen

    #[Test]
    public function the_review_form_offers_the_whole_catalogue(): void
    {
        $order = $this->order(quoted: true);

        $items = $this->actingAs($this->tenant['owner'])
            ->get('/admin/order/show/'.$order->id)
            ->assertOk()
            ->viewData('reviewItems');

        // There is no matrix to filter by, so every active piece is offered and
        // the laundry counts the ones that arrived. Before this it was an empty
        // array and the form did not render at all.
        $this->assertCount(count($this->catalog['items']), $items);
        $this->assertNull($items[0]['price']);
    }

    #[Test]
    public function a_catalogued_service_still_shows_a_read_only_price(): void
    {
        $order = $this->order(quoted: false);

        $html = $this->actingAs($this->tenant['owner'])
            ->get('/admin/order/show/'.$order->id)
            ->assertOk()
            ->getContent();

        // The recalculation script mentions the class on every review form, so
        // the input's own name is what distinguishes a rendered box from a
        // selector that matches nothing.
        $this->assertStringNotContainsString('[unit_price]', (string) $html);
    }

    #[Test]
    public function the_quoted_form_renders_a_price_box(): void
    {
        $order = $this->order(quoted: true);

        $html = (string) $this->actingAs($this->tenant['owner'])
            ->get('/admin/order/show/'.$order->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('review-price', $html);
        $this->assertStringContainsString('[unit_price]', $html);
    }

    // ------------------------------------------------------------- the pricing

    #[Test]
    public function the_laundry_prices_each_line_itself(): void
    {
        $order = $this->order(quoted: true);
        [$shirt, $trousers] = $this->catalog['items'];

        $this->actingAs($this->tenant['owner'])
            ->post('/admin/order/review/'.$order->id, [
                'lines' => [
                    ['item_id' => $shirt->id, 'qty' => 2, 'unit_price' => '90'],
                    ['item_id' => $trousers->id, 'qty' => 1, 'unit_price' => '150.50'],
                ],
            ])->assertRedirect();

        $order->refresh();

        $this->assertSame(OrderStatus::Reviewed, $order->status);
        $this->assertEquals(3, $order->final_items_count);
        $this->assertEquals(330.50, $order->final_subtotal);

        $lines = OrderItem::where('order_id', $order->id)->where('phase', 'final')->get();
        $this->assertEquals(90.0, $lines->firstWhere('item_id', $shirt->id)->unit_price);
        $this->assertEquals(150.50, $lines->firstWhere('item_id', $trousers->id)->unit_price);
    }

    #[Test]
    public function a_counted_piece_with_no_price_is_refused(): void
    {
        $order = $this->order(quoted: true);
        [$shirt, $trousers] = $this->catalog['items'];

        $this->actingAs($this->tenant['owner'])
            ->post('/admin/order/review/'.$order->id, [
                'lines' => [
                    ['item_id' => $shirt->id, 'qty' => 2, 'unit_price' => '90'],
                    // Counted and left blank: it would be cleaned and never
                    // charged for, which is the one failure that must not pass
                    // quietly.
                    ['item_id' => $trousers->id, 'qty' => 1, 'unit_price' => ''],
                ],
            ])->assertSessionHas('error');

        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }

    #[Test]
    public function a_piece_that_did_not_arrive_needs_no_price(): void
    {
        $order = $this->order(quoted: true);
        [$shirt, $trousers] = $this->catalog['items'];

        $this->actingAs($this->tenant['owner'])
            ->post('/admin/order/review/'.$order->id, [
                'lines' => [
                    ['item_id' => $shirt->id, 'qty' => 2, 'unit_price' => '90'],
                    // Zero means "not in the bag". Demanding a price for nothing
                    // would make the form impossible to submit.
                    ['item_id' => $trousers->id, 'qty' => 0, 'unit_price' => ''],
                ],
            ])->assertRedirect();

        $this->assertEquals(180.0, $order->fresh()->final_subtotal);
    }

    #[Test]
    public function a_negative_price_never_pays_the_customer(): void
    {
        $order = $this->order(quoted: true);
        [$shirt] = $this->catalog['items'];

        $this->actingAs($this->tenant['owner'])
            ->post('/admin/order/review/'.$order->id, [
                'lines' => [['item_id' => $shirt->id, 'qty' => 2, 'unit_price' => '-90']],
            ])->assertSessionHasErrors('lines.0.unit_price');

        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }

    // -------------------------------------------------- the rule for everyone else

    #[Test]
    public function a_posted_price_cannot_re_cost_a_catalogued_service(): void
    {
        $order = $this->order(quoted: false);
        [$shirt] = $this->catalog['items'];

        $catalogue = (float) ItemPrice::where('service_id', $this->catalog['service']->id)
            ->where('item_id', $shirt->id)->value('price');

        $this->actingAs($this->tenant['owner'])
            ->post('/admin/order/review/'.$order->id, [
                // A laundry posting its own price for a shirt the platform prices
                // at 17. The value validates and is then ignored.
                'lines' => [['item_id' => $shirt->id, 'qty' => 2, 'unit_price' => '999']],
            ])->assertRedirect();

        $order->refresh();

        $this->assertEquals($catalogue * 2, $order->final_subtotal);
        $this->assertEquals(
            $catalogue,
            OrderItem::where('order_id', $order->id)->where('phase', 'final')->value('unit_price')
        );
    }

    #[Test]
    public function a_catalogued_service_still_refuses_an_unpriced_piece(): void
    {
        $order = $this->order(quoted: false);
        [$shirt] = $this->catalog['items'];

        // The price list is the list of pieces the service handles at all, so a
        // missing row is a catalogue problem and the message says so.
        ItemPrice::where('service_id', $this->catalog['service']->id)
            ->where('item_id', $shirt->id)->delete();

        $this->actingAs($this->tenant['owner'])
            ->post('/admin/order/review/'.$order->id, [
                'lines' => [['item_id' => $shirt->id, 'qty' => 2]],
            ])->assertSessionHas('error');

        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }

    // ------------------------------------------------------------- a re-count

    #[Test]
    public function a_second_count_keeps_the_prices_that_were_entered(): void
    {
        $order = $this->order(quoted: true);
        [$shirt] = $this->catalog['items'];

        $this->actingAs($this->tenant['owner'])
            ->post('/admin/order/review/'.$order->id, [
                'lines' => [['item_id' => $shirt->id, 'qty' => 2, 'unit_price' => '90']],
            ])->assertRedirect();

        $order->refresh()->forceFill(['status' => OrderStatus::ReviewDisputed->value])->save();

        $items = $this->actingAs($this->tenant['owner'])
            ->get('/admin/order/show/'.$order->id)
            ->assertOk()
            ->viewData('reviewItems');

        // A dispute is about the count, usually. Making the laundry retype every
        // price they already agreed is how a second count becomes a third.
        $entry = collect($items)->firstWhere(fn ($row) => $row['item']->id === $shirt->id);
        $this->assertEquals(90.0, $entry['price']);
        $this->assertSame(2, $entry['final_qty']);
    }
}
