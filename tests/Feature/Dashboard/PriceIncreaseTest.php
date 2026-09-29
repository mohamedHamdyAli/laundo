<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Pricing\Models\PriceChange;
use App\Modules\Pricing\Services\PriceIncrease;
use App\Modules\Pricing\Services\pricingService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use App\Services\Landing\LandingContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «زيادة سنوية تنزل على كل الأسعار» — a rise across the catalogue, for a period
 * or for good.
 *
 * What matters most is that the price a customer is quoted, the price the review
 * charges and the price every list shows are the same number — and that a period
 * rise coming off never moves an order somebody already agreed to.
 */
class PriceIncreaseTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $tenant;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer('+201099880011');

        // No customer fee, so every figure below is the laundry's price.
        Setting::updateOrCreate(['key' => 'Commission_Rate'], ['value' => '0']);
        Cache::flush();
    }

    private function shirtPrice(): string
    {
        return ItemPrice::where('item_id', $this->catalog['items'][0]->id)->value('price');
    }

    /** What the app's catalogue quotes for the shirt. */
    private function cataloguePrice(): string
    {
        $catalog = app(pricingService::class)->publicCatalog($this->catalog['service']->id);

        return $catalog[0]['categories'][0]['items'][0]['price'];
    }

    private function place(): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    private function increase(array $payload)
    {
        return $this->actingAs($this->superAdmin())->post(route('admin.pricing.increase'), $payload);
    }

    #[Test]
    public function a_period_rise_reaches_every_price_and_leaves_the_list_alone(): void
    {
        $this->increase(['mode' => 'period', 'rate' => 10])->assertRedirect();

        // The stored price is untouched...
        $this->assertSame('17.00', $this->shirtPrice());

        // ...and everything a customer sees carries the rise: 17 at 10% is 18.70.
        $this->assertSame('18.70', $this->cataloguePrice());

        $order = $this->place();
        $line = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->assertSame('18.70', $line->base_unit_price);
        $this->assertSame('37.40', $order->estimated_subtotal);
        $this->assertSame('10.00', $order->price_increase_rate);
    }

    #[Test]
    public function taking_a_period_rise_off_puts_the_list_back(): void
    {
        $this->increase(['mode' => 'period', 'rate' => 10]);
        $this->increase(['mode' => 'remove'])->assertRedirect();

        $this->assertSame(0.0, app(PriceIncrease::class)->rate());
        $this->assertSame('17.00', $this->cataloguePrice());
        $this->assertNull($this->place()->price_increase_rate);
    }

    #[Test]
    public function a_period_rise_comes_off_by_itself_at_its_end_date(): void
    {
        $this->increase(['mode' => 'period', 'rate' => 10, 'ends_at' => now()->addDay()->format('Y-m-d\TH:i')]);
        $this->assertSame(10.0, app(PriceIncrease::class)->rate());

        // No job has to remember to switch it off: past its date it reads as none.
        $this->travel(2)->days();
        $this->assertSame(0.0, app(PriceIncrease::class)->rate());
        $this->assertSame('17.00', $this->cataloguePrice());
    }

    #[Test]
    public function an_end_date_in_the_past_is_refused(): void
    {
        $this->increase(['mode' => 'period', 'rate' => 10, 'ends_at' => now()->subDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('ends_at');

        $this->assertSame(0.0, app(PriceIncrease::class)->rate());
    }

    #[Test]
    public function making_it_permanent_rewrites_the_list_and_clears_the_box(): void
    {
        $this->increase(['mode' => 'permanent', 'rate' => 10])->assertRedirect();

        $this->assertSame('18.70', $this->shirtPrice());
        $this->assertSame('25.30', ItemPrice::where('item_id', $this->catalog['items'][1]->id)->value('price'));

        // The box is back to zero, so the rise is not charged twice.
        $this->assertSame(0.0, app(PriceIncrease::class)->rate());
        $this->assertSame('18.70', $this->cataloguePrice());
    }

    #[Test]
    public function a_period_rise_made_permanent_leaves_the_customer_price_where_it_was(): void
    {
        $this->increase(['mode' => 'period', 'rate' => 10]);
        $before = $this->cataloguePrice();

        $this->increase(['mode' => 'permanent', 'rate' => 10]);

        $this->assertSame($before, $this->cataloguePrice());
        $this->assertSame(0.0, app(PriceIncrease::class)->rate());
    }

    #[Test]
    public function the_review_prices_at_the_rise_the_order_was_placed_under(): void
    {
        // Placed during a 10% period; the period ends while the bag sits in the
        // laundry. The review reads the matrix again — and must still charge the
        // price the customer agreed to, not drop it back to 17.
        $this->increase(['mode' => 'period', 'rate' => 10]);
        $order = $this->place();

        $this->increase(['mode' => 'remove']);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        app(OrderReviewService::class)->review(
            $order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']
        );

        $final = OrderItem::where('order_id', $order->id)->where('phase', 'final')->firstOrFail();

        $this->assertSame('18.70', $final->base_unit_price);
        $this->assertSame('37.40', $order->fresh()->final_subtotal);
    }

    private function reviewShirts(Order $order): OrderItem
    {
        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        app(OrderReviewService::class)->review(
            $order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']
        );

        return OrderItem::where('order_id', $order->id)->where('phase', 'final')->firstOrFail();
    }

    #[Test]
    public function a_period_rise_made_permanent_is_not_charged_twice_on_an_order_in_the_laundry(): void
    {
        // Placed at 18.70 during a 10% period; the rise is made permanent while
        // the bag sits in the laundry. The catalogue now says 18.70 — and the
        // order must not add its stamped 10% on top of that (20.57).
        $this->increase(['mode' => 'period', 'rate' => 10]);
        $order = $this->place();

        $this->increase(['mode' => 'permanent', 'rate' => 10]);

        $this->assertSame('18.70', $this->reviewShirts($order)->base_unit_price);
    }

    #[Test]
    public function a_permanent_rise_above_the_period_one_still_charges_what_the_customer_agreed(): void
    {
        // 5% for a period (17.85), then 10% for good (18.70 in the list).
        $this->increase(['mode' => 'period', 'rate' => 5]);
        $order = $this->place();

        $this->increase(['mode' => 'permanent', 'rate' => 10]);

        $this->assertSame('17.85', $this->reviewShirts($order)->base_unit_price);
    }

    #[Test]
    public function undoing_a_rise_keeps_an_order_placed_under_it_at_its_price(): void
    {
        $this->increase(['mode' => 'permanent', 'rate' => 10]);
        $order = $this->place();                     // agreed to 18.70

        app(PriceIncrease::class)->undo(app(PriceIncrease::class)->undoable());
        $this->assertSame('17.00', $this->shirtPrice());

        $this->assertSame('18.70', $this->reviewShirts($order)->base_unit_price);
    }

    #[Test]
    public function a_permanent_rise_and_its_undo_reach_the_front_page_at_once(): void
    {
        $landing = fn () => json_encode(app(LandingContentService::class)->pageData()['priceGrid'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString(moneyFormat(17), $landing());

        // No period rate is involved, so neither rate in the cache key moves —
        // the list itself has to.
        $this->increase(['mode' => 'permanent', 'rate' => 10]);
        $this->assertStringContainsString(moneyFormat(18.70), $landing());

        // Within the same second as the rise: the key must still move.
        app(PriceIncrease::class)->undo(app(PriceIncrease::class)->undoable());
        $this->assertStringContainsString(moneyFormat(17), $landing());
    }

    #[Test]
    public function a_permanent_rise_is_in_the_history_and_can_be_undone(): void
    {
        $this->increase(['mode' => 'permanent', 'rate' => 5]);
        $this->assertSame('17.85', $this->shirtPrice());

        $change = PriceChange::sole();
        $this->assertTrue($change->isPermanent());
        $this->assertSame(2, $change->prices_count);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.increase.undo', $change->id))
            ->assertSessionHas('success');

        $this->assertSame('17.00', $this->shirtPrice());
        $this->assertSame('23.00', ItemPrice::where('item_id', $this->catalog['items'][1]->id)->value('price'));
        $this->assertTrue($change->fresh()->isUndone());
        $this->assertSame(2, $change->fresh()->restored_count);
    }

    #[Test]
    public function undoing_leaves_a_price_corrected_by_hand_since(): void
    {
        $this->increase(['mode' => 'permanent', 'rate' => 5]);

        // Somebody fixes the trousers by hand after the rise.
        ItemPrice::where('item_id', $this->catalog['items'][1]->id)->update(['price' => 30]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.increase.undo', PriceChange::sole()->id))
            ->assertSessionHas('success');

        $this->assertSame('17.00', $this->shirtPrice());
        $this->assertSame('30.00', ItemPrice::where('item_id', $this->catalog['items'][1]->id)->value('price'));
    }

    #[Test]
    public function only_the_latest_standing_rise_can_be_undone(): void
    {
        $this->increase(['mode' => 'permanent', 'rate' => 5]);
        $this->increase(['mode' => 'permanent', 'rate' => 10]);

        [$first, $second] = PriceChange::orderBy('id')->get()->all();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.increase.undo', $first->id))
            ->assertSessionHas('error');
        $this->assertFalse($first->fresh()->isUndone());

        // Newest first, then the one under it: back to the list as it was.
        $this->actingAs($this->superAdmin())->post(route('admin.pricing.increase.undo', $second->id));
        $this->actingAs($this->superAdmin())->post(route('admin.pricing.increase.undo', $first->id));

        $this->assertSame('17.00', $this->shirtPrice());

        // And an undone rise cannot be undone twice.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.pricing.increase.undo', $first->id))
            ->assertSessionHas('error');
    }

    #[Test]
    public function a_period_rise_is_in_the_history_and_ends_when_taken_off(): void
    {
        $this->increase(['mode' => 'period', 'rate' => 10]);
        $this->assertTrue(PriceChange::sole()->isRunning());

        $this->increase(['mode' => 'remove']);
        $this->assertFalse(PriceChange::sole()->isRunning());
        $this->assertNotNull(PriceChange::sole()->ended_at);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee(__('History'), false);
    }

    #[Test]
    public function the_rise_is_for_somebody_who_may_edit_the_prices(): void
    {
        $this->grant('laundry_owner', ['item_price.view']);

        $this->actingAs($this->tenant['owner'])
            ->post(route('admin.pricing.increase'), ['mode' => 'permanent', 'rate' => 50])
            ->assertForbidden();

        $this->assertSame('17.00', $this->shirtPrice());
    }

    #[Test]
    public function the_price_screen_shows_the_rise_in_force(): void
    {
        $this->increase(['mode' => 'period', 'rate' => 10]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('price-increase-form', false)
            ->assertSee(moneyFormat(18.70), false);
    }
}
