<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderPricing;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Payment\Models\CommissionRule;
use App\Modules\Payment\Services\SettlementService;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform's own charge, carried by the customer.
 *
 * Three separate things take money out of an order and only two of them used to
 * exist: the state takes the tax, the laundry pays its commission, and now the
 * platform charges a fee the **customer** pays. Before this the platform could
 * only ever be paid by taking a slice of the laundry's work.
 *
 * What is asserted hardest here is the thing that makes it safe to hide: the
 * invoice still adds up. The fee is folded into the per-piece price rather than
 * added as an unnamed line, so every row, the subtotal and the total reconcile
 * exactly — a customer who subtracts what they can see finds nothing left over.
 */
class PlatformFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('+201055550001');
        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
    }

    private function setting(string $key, ?string $value): void
    {
        if ($value === null) {
            Setting::where('key', $key)->delete();
        } else {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // getSettingValue caches for ever.
        Cache::flush();
    }

    /** The laundry's own price for the one item these tests order. */
    private function basePrice(float $price): void
    {
        ItemPrice::updateOrCreate(
            ['service_id' => $this->catalog['service']->id, 'item_id' => $this->catalog['items'][0]->id],
            ['price' => $price]
        );
    }

    private function place(int $qty = 2): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => $qty]],
            'accepts_review_terms' => true,
        ]);
    }

    // ------------------------------------------------------- inside the price

    #[Test]
    public function the_fee_is_inside_the_piece_price_and_not_beside_it(): void
    {
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');
        $this->basePrice(100);

        $quote = app(OrderPricing::class)->quote(
            $this->catalog['service'],
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            $this->addressFor($this->customer, $this->geo['zones'][0]),
        );

        // The customer is quoted 110 a piece, not 100 with 10 added at the end.
        $this->assertSame(110.0, $quote['lines'][0]['unit_price']);
        $this->assertSame(220.0, $quote['lines'][0]['line_total']);
        $this->assertSame(220.0, $quote['subtotal']);

        // And the fee is carried out separately so the order can store it —
        // 20, which is the gap, not a second percentage of anything.
        $this->assertSame(20.0, $quote['platform_fee']);
        $this->assertSame(10.0, $quote['platform_fee_rate']);
    }

    #[Test]
    public function no_rate_changes_nothing(): void
    {
        // The install that has not set one charges nothing, and every figure is
        // exactly what it was before this existed.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', null);
        $this->basePrice(100);

        $order = $this->place();

        $this->assertSame('200.00', $order->estimated_subtotal);
        $this->assertSame(0.0, (float) $order->platform_fee);
    }

    #[Test]
    public function the_invoice_still_adds_up(): void
    {
        // The reason the fee is folded into the price rather than added as a
        // hidden line. An invoice carries a tax line, and a tax line obliges the
        // document to reconcile: a customer who subtracts the rows they can see
        // from the total they paid must not find a gap with no name on it.
        $this->setting('Tax', '14');
        $this->setting('Commission_Rate', '10');
        $this->basePrice(100);

        $order = $this->place(3);

        $lines = $order->items->where('phase', 'estimated');
        $sumOfLines = round($lines->sum(fn ($l) => (float) $l->line_total), 2);

        $this->assertSame((float) $order->estimated_subtotal, $sumOfLines);

        foreach ($lines as $line) {
            $this->assertSame(
                round((float) $line->unit_price * $line->qty, 2),
                round((float) $line->line_total, 2),
                'a line must equal its own unit price times its own quantity'
            );
        }

        $this->assertSame(
            round($order->preTaxTotal() + $order->payableTax(), 2),
            round($order->payableTotal(), 2)
        );
    }

    // --------------------------------------------------------------- the split

    #[Test]
    public function the_laundry_is_not_charged_for_the_platforms_fee(): void
    {
        // The whole point. The fee sits inside the subtotal, so leaving it there
        // would hand the laundry a share of the platform's own charge and then
        // take a commission off it as well.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');
        $this->basePrice(100);

        $order = $this->place();

        $this->assertSame('220.00', $order->estimated_subtotal);
        $this->assertSame('20.00', $order->platform_fee);

        // 200 is the laundry's price; the 20 is the platform's and is not divided.
        $this->assertSame(200.0, $order->cleaningRevenue());
        $this->assertSame(20.0, $order->platformFeeEarned());
    }

    #[Test]
    public function the_discount_is_shared_in_proportion(): void
    {
        // A coupon reduces the price, and the fee is inside the price — so both
        // sides give up the same proportion. Any other split needs a rule about
        // who pays for a coupon, and a rule nobody wrote down gets decided
        // differently the next time.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');

        $order = new Order([
            'estimated_subtotal' => 220,
            'platform_fee' => 20,
            'discount_total' => 22,
        ]);

        // 10% off: the laundry's 200 becomes 180, the platform's 20 becomes 18.
        $this->assertSame(180.0, $order->cleaningRevenue());
        $this->assertSame(18.0, $order->platformFeeEarned());

        // And nothing falls between them.
        $this->assertSame(
            198.0,
            round($order->cleaningRevenue() + $order->platformFeeEarned(), 2)
        );
    }

    #[Test]
    public function an_order_placed_before_the_fee_existed_divides_as_it_always_did(): void
    {
        // Null is «placed before this existed», which is a different fact from
        // zero, and the old arithmetic has to survive it exactly.
        $order = new Order([
            'estimated_subtotal' => 200,
            'platform_fee' => null,
            'discount_total' => 50,
        ]);

        $this->assertSame(150.0, $order->cleaningRevenue());
        $this->assertSame(0.0, $order->platformFeeEarned());
    }

    #[Test]
    public function the_settlement_records_all_three_shares(): void
    {
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');
        $this->basePrice(100);

        $rule = CommissionRule::create([
            'name' => json_encode(['en' => 'Deal', 'ar' => 'اتفاق'], JSON_UNESCAPED_UNICODE),
            'basis' => 'percent',
            'rate' => 15,
            'status' => 'active',
        ]);
        $this->tenant['laundry']->commissionRules()->syncWithoutDetaching([$rule->id]);

        $order = $this->place();
        $order->forceFill([
            'laundry_id' => $this->tenant['laundry']->id,
            'delivery_fee' => 0,
        ])->save();

        $settlement = app(SettlementService::class)->recordFor($order->fresh());

        // The customer's 220 divides three ways and only three ways.
        $this->assertSame('200.00', $settlement->basis);
        $this->assertSame('20.00', $settlement->platform_fee_amount);
        $this->assertSame('30.00', $settlement->commission_amount);
        $this->assertSame('170.00', $settlement->laundry_amount);

        // The platform takes its fee from the customer and its commission from
        // the laundry, and the two are recorded apart because they are paid by
        // different people.
        $this->assertSame(
            220.0,
            round(
                (float) $settlement->platform_fee_amount
                + (float) $settlement->commission_amount
                + (float) $settlement->laundry_amount,
                2
            )
        );
    }

    #[Test]
    public function the_rate_is_stamped_and_never_re_read(): void
    {
        // Same rule as the tax rate: raising the fee next month must not restate
        // what somebody already agreed to and already paid.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');
        $this->basePrice(100);

        $order = $this->place();

        $this->setting('Commission_Rate', '50');

        $this->assertSame('10.00', $order->fresh()->platform_fee_rate);
        $this->assertSame('220.00', $order->fresh()->estimated_subtotal);
        $this->assertSame(200.0, $order->fresh()->cleaningRevenue());
    }

    #[Test]
    public function raising_the_rate_does_not_reach_an_order_already_agreed(): void
    {
        // The review re-prices the pieces, so it re-applies the fee — and it has
        // to do that at the rate the order was placed under. Reading the setting
        // there instead hands the customer a final bill above the estimate they
        // agreed to, for a piece count that did not change, while the one column
        // that could explain it still shows the old rate.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');
        $this->basePrice(100);

        $order = $this->place();
        $this->assertSame('220.00', $order->estimated_subtotal);

        // Somebody raises it while the bag is at the laundry.
        $this->setting('Commission_Rate', '50');

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        app(OrderReviewService::class)->review(
            $order,
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            null,
            $this->tenant['owner']
        );

        $order = $order->fresh();

        // Same pieces, same price. Not 300.
        $this->assertSame('220.00', $order->final_subtotal);
        $this->assertSame('20.00', $order->platform_fee);
        $this->assertSame(200.0, $order->cleaningRevenue());
    }

    #[Test]
    public function an_order_from_before_the_fee_gains_none_at_review(): void
    {
        // Null rate means «placed before this existed». The review must not
        // quietly introduce a charge the customer never saw at the estimate.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', null);
        $this->basePrice(100);

        $order = $this->place();

        $this->setting('Commission_Rate', '25');

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        app(OrderReviewService::class)->review(
            $order,
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            null,
            $this->tenant['owner']
        );

        $this->assertSame('200.00', $order->fresh()->final_subtotal);
        $this->assertSame(0.0, (float) $order->fresh()->platform_fee);
    }

    #[Test]
    public function a_second_count_does_not_charge_the_fee_twice(): void
    {
        // «تنظيف جاف» is priced by the laundry typing a figure, and the review
        // form prefills that box from the stored price. The stored price now
        // carries the fee, so prefilling it fed the fee back through the fee:
        // 100 became 110, then 121, then 133.10 — once per dispute — while the
        // laundry's own price never moved. The customer was overcharged and the
        // laundry was overpaid at the same time.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');

        $quoted = Service::create([
            'name' => json_encode(['en' => 'Dry clean', 'ar' => 'تنظيف جاف'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'quote',
            'duration' => 1,
            'duration_unit' => 'day',
            'status' => 'active',
        ]);
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $quoted->id);

        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);
        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $quoted->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $reviews = app(OrderReviewService::class);
        $line = [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1, 'unit_price' => 100]];

        // The laundry types 100.
        $reviews->review($order, $line, null, $this->tenant['owner']);
        $this->assertSame('110.00', $order->fresh()->final_subtotal);
        $this->assertSame('100.00', $order->fresh()->items->where('phase', 'final')->first()->base_unit_price);

        // The customer asks for another count — «طلب مراجعة إضافية» — which is
        // the real path back into the form and the one the compounding rode.
        $reviews->dispute($order->fresh(), $this->customer, 'Please count again.');

        // The form gives the laundry back its own 100, and it types it again.
        $prefilled = (float) $order->fresh()->items->where('phase', 'final')->first()->base_unit_price;
        $this->assertSame(100.0, $prefilled);

        $reviews->review(
            $order->fresh(),
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1, 'unit_price' => $prefilled]],
            null,
            $this->tenant['owner']
        );

        // Still 110, not 121.
        $this->assertSame('110.00', $order->fresh()->final_subtotal);
        $this->assertSame(100.0, $order->fresh()->cleaningRevenue());
    }

    #[Test]
    public function completing_credits_the_fee_to_the_platform_wallet(): void
    {
        // It has to land somewhere a person can see. Before this feature, an
        // install with a general rate set saw exactly this money arrive in the
        // platform wallet as commission — so recording it only on a revenue
        // screen would read as the platform having stopped being paid.
        $this->setting('Tax', null);
        $this->setting('Commission_Rate', '10');
        $this->basePrice(100);

        $platform = $this->superAdmin();

        $order = $this->place();
        $order->forceFill(['laundry_id' => $this->tenant['laundry']->id, 'delivery_fee' => 0])->save();

        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        $reviews = app(OrderReviewService::class);
        $reviews->review($order, [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], null, $this->tenant['owner']);
        $order = $reviews->confirm($order->fresh(), $this->customer);

        foreach ([OrderStatus::Cleaning, OrderStatus::ReadyForDelivery, OrderStatus::Delivered, OrderStatus::Completed] as $next) {
            $order = $machine->transition($order->fresh(), $next, 'system');
        }

        $wallet = app(WalletService::class)->forUser($platform);

        // No commission rule is attached, so the whole of the platform's 20 is
        // the customer's fee — and it is in the wallet under its own reason.
        $this->assertSame(20.0, (float) $wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'reason' => TransactionReason::PlatformFee->value,
        ]);
    }

    #[Test]
    public function the_app_catalogue_quotes_what_the_customer_will_be_charged(): void
    {
        // The screen somebody decides on before there is an order at all. A
        // figure here that the quote later disagrees with reads as a charge that
        // appeared at checkout.
        $this->setting('Commission_Rate', '10');
        $this->basePrice(100);

        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/v1/catalog');

        $perItem = collect($response->assertOk()->json('data'))
            ->firstWhere('pricing_mode', 'per_item');

        $prices = collect($perItem['categories'])
            ->flatMap(fn ($c) => $c['items'])
            ->pluck('price');

        $this->assertContains('110.00', $prices->all());
    }
}
