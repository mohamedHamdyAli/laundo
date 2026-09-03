<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «قد يتم تطبيق رسوم إضافية» — the cash surcharge.
 *
 * The field has been on the settings form, validated and stored since P9, and
 * **nothing read it**. A configured surcharge changed no price at all — the most
 * complete kind of silent failure, because the setting screen said it was working.
 *
 * This is the only change in the batch that touches what a customer pays, so what
 * is asserted hardest is the arithmetic and the *visibility*: the fee is its own
 * line, it appears while the customer is still choosing, and it is recorded on the
 * order so the total can be reconstructed a month later.
 */
class CashSurchargeTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

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
        $this->customer = $this->customer('+201055550001');
    }

    private function surcharge(?string $amount): void
    {
        if ($amount === null) {
            Setting::where('key', 'Cash_Surcharge')->delete();
        } else {
            Setting::updateOrCreate(['key' => 'Cash_Surcharge'], ['value' => $amount]);
        }

        // getSettingValue caches for ever.
        Cache::flush();
    }

    /** @return array<string, mixed> */
    private function quote(?string $method): array
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->quote($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'payment_method' => $method,
        ]);
    }

    private function place(?string $method): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'payment_method' => $method,
            'accepts_review_terms' => true,
        ]);
    }

    // ------------------------------------------------------------ the arithmetic

    #[Test]
    public function a_cash_order_carries_the_surcharge(): void
    {
        $this->surcharge('10');

        $quote = $this->quote('cash');

        $this->assertSame(10.0, $quote['cash_surcharge']);

        // Its own line, and inside the total.
        $expected = round(
            $quote['subtotal'] + (float) ($quote['delivery_fee'] ?? 0) - $quote['discount'] + 10.0,
            2
        );
        $this->assertSame($expected, $quote['total']);
    }

    #[Test]
    public function a_card_order_carries_none(): void
    {
        $this->surcharge('10');

        $quote = $this->quote('card');

        $this->assertSame(0.0, $quote['cash_surcharge']);
        $this->assertSame(
            round($quote['subtotal'] + (float) ($quote['delivery_fee'] ?? 0) - $quote['discount'], 2),
            $quote['total']
        );
    }

    #[Test]
    public function a_wallet_order_carries_none(): void
    {
        $this->surcharge('10');

        $this->assertSame(0.0, $this->quote('wallet')['cash_surcharge']);
    }

    #[Test]
    public function a_quote_taken_before_the_customer_chooses_shows_no_fee(): void
    {
        $this->surcharge('10');

        // The wizard quotes as the basket is built, long before the payment screen.
        // Showing a fee they may never incur would be a lie in the other direction.
        $this->assertSame(0.0, $this->quote(null)['cash_surcharge']);
    }

    #[Test]
    public function an_unset_surcharge_charges_nothing(): void
    {
        $this->surcharge(null);

        // «قد يتم تطبيق» is permissive, not a promise. Off is the default.
        $this->assertSame(0.0, $this->quote('cash')['cash_surcharge']);
    }

    #[Test]
    public function a_zero_surcharge_charges_nothing(): void
    {
        $this->surcharge('0');

        $this->assertSame(0.0, $this->quote('cash')['cash_surcharge']);
    }

    #[Test]
    public function a_negative_surcharge_never_pays_the_customer(): void
    {
        // The form validates min:0, but a value written straight to the settings
        // table must not be able to hand out money.
        $this->surcharge('-50');

        $this->assertSame(0.0, $this->quote('cash')['cash_surcharge']);
    }

    // ---------------------------------------------------------- the discount rule

    #[Test]
    public function a_coupon_does_not_discount_the_surcharge(): void
    {
        $this->surcharge('25');

        $withFee = $this->quote('cash');
        $withoutFee = $this->quote('card');

        // Added after the discount on purpose. A coupon discounts the washing, not
        // the cost of handling notes — otherwise a large enough coupon would pay
        // the customer to use cash.
        $this->assertSame(
            round($withoutFee['total'] + 25.0, 2),
            $withFee['total']
        );
    }

    // ------------------------------------------------------------- on the order

    #[Test]
    public function the_amount_is_recorded_on_the_order(): void
    {
        $this->surcharge('15');

        $order = $this->place('cash');

        // Without the column, `estimated_total` is a figure nobody can
        // reconstruct — and an invoice printed after somebody changes the setting
        // would disagree with the order.
        $this->assertEquals(15.0, $order->cash_surcharge);
    }

    #[Test]
    public function the_stored_total_matches_what_the_customer_was_quoted(): void
    {
        $this->surcharge('15');

        $quote = $this->quote('cash');
        $order = $this->place('cash');

        // quote() and place() run the same pricing pass with the same method, so
        // the total agreed to is the total stored.
        $this->assertEquals($quote['total'], $order->estimated_total);
    }

    #[Test]
    public function changing_the_setting_later_does_not_change_a_placed_order(): void
    {
        $this->surcharge('15');

        $order = $this->place('cash');
        $total = $order->estimated_total;

        $this->surcharge('99');

        // The order holds its own figures. A setting is not a retroactive price.
        $order->refresh();
        $this->assertEquals($total, $order->estimated_total);
        $this->assertEquals(15.0, $order->cash_surcharge);
    }

    #[Test]
    public function a_card_order_records_zero_rather_than_null(): void
    {
        $this->surcharge('15');

        // Every order has a surcharge; for almost all of them it is nothing. Null
        // would mean "unknown", which is not a state this can be in.
        $this->assertEquals(0.0, $this->place('card')->cash_surcharge);
    }

    // --------------------------------------------------------------- the API

    #[Test]
    public function the_quote_endpoint_shows_the_fee_as_its_own_line(): void
    {
        $this->surcharge('12');

        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $data = $this->actingAs($this->customer)
            ->postJson('/api/v1/orders/quote', [
                'service_id' => $this->catalog['service']->id,
                'pickup_address_id' => $address->id,
                'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
                'payment_method' => 'cash',
            ])
            ->assertOk()
            ->json('data');

        // A charge the customer cannot see is a charge they cannot avoid — and
        // this one they can, by paying another way.
        $this->assertArrayHasKey('cash_surcharge', $data);
        $this->assertEquals(12, $data['cash_surcharge']);
    }
}
