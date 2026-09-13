<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «App Tax» — the state's tax on the order.
 *
 * The same fault the cash surcharge had, found the same way: the field had been
 * on the settings form, validated and stored since P9 and **read by nothing**, so
 * a configured tax changed no price and appeared on no invoice.
 *
 * Two claims are worth more than the rest here. **The tax is added on the whole
 * total**, which is the owner's instruction — «ضريبة الدولة بتضاف على الإجمالي
 * نفسه» — and it is what makes the invoice readable top to bottom. And **an order
 * keeps the rate it was placed under**: a rate that moves next quarter must not
 * restate an invoice already in a customer's hands, which is the same rule that
 * copies unit prices onto the order.
 */
class AppTaxTest extends TestCase
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
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $this->tenant = $this->laundryWithOwner('T', '+201044440001', '+201044440002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer('+201055550002');
    }

    private function tax(?string $rate): void
    {
        if ($rate === null) {
            Setting::where('key', 'Tax')->delete();
        } else {
            Setting::updateOrCreate(['key' => 'Tax'], ['value' => $rate]);
        }

        // getSettingValue caches for ever.
        Cache::flush();
    }

    private function surcharge(?string $amount): void
    {
        if ($amount === null) {
            Setting::where('key', 'Cash_Surcharge')->delete();
        } else {
            Setting::updateOrCreate(['key' => 'Cash_Surcharge'], ['value' => $amount]);
        }

        Cache::flush();
    }

    /** @return array<string, mixed> */
    private function quote(?string $method = null): array
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->quote($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'payment_method' => $method,
        ]);
    }

    private function place(?string $method = null): Order
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

    /** The two legal steps that put an order in the laundry's hands. */
    private function pickedUp(Order $order): Order
    {
        $machine = app(OrderStateMachine::class);
        $machine->transition($order->fresh(), OrderStatus::DriverOnWay, 'driver');

        return $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');
    }

    // ------------------------------------------------------------ the arithmetic

    #[Test]
    public function the_tax_is_a_percentage_of_everything_above_it(): void
    {
        $this->tax('10');

        $quote = $this->quote();

        $preTax = round(
            $quote['subtotal'] + (float) ($quote['delivery_fee'] ?? 0) - $quote['discount'] + $quote['cash_surcharge'],
            2
        );

        $this->assertSame($preTax, $quote['pre_tax_total']);
        $this->assertSame(10.0, $quote['tax_rate']);
        $this->assertSame(round($preTax * 0.10, 2), $quote['tax']);
        $this->assertSame(round($preTax + $quote['tax'], 2), $quote['total']);
    }

    #[Test]
    public function the_delivery_fee_is_taxed_with_everything_else(): void
    {
        $this->tax('10');

        $quote = $this->quote();

        // The claim, stated as a number rather than as a restatement of the
        // formula: a delivery fee left out of the base would make the tax
        // smaller than the subtotal alone implies.
        $this->assertGreaterThan(0.0, (float) $quote['delivery_fee']);
        $this->assertGreaterThan(round($quote['subtotal'] * 0.10, 2), $quote['tax']);
    }

    #[Test]
    public function the_cash_surcharge_is_taxed_too(): void
    {
        $this->tax('10');
        $this->surcharge('10');

        $cash = $this->quote('cash');
        $card = $this->quote('card');

        $this->assertSame(10.0, $cash['cash_surcharge']);
        $this->assertSame(0.0, $card['cash_surcharge']);

        // Exactly the tax on the surcharge itself, and not a rounding accident.
        $this->assertSame(1.0, round($cash['tax'] - $card['tax'], 2));
    }

    #[Test]
    public function no_configured_tax_adds_nothing(): void
    {
        $this->tax(null);

        $quote = $this->quote();

        $this->assertSame(0.0, $quote['tax_rate']);
        $this->assertSame(0.0, $quote['tax']);
        $this->assertSame($quote['pre_tax_total'], $quote['total']);
    }

    #[Test]
    public function an_absurd_rate_is_clamped_rather_than_trusted(): void
    {
        // The settings column is a string, and 1000 in a numeric box would
        // otherwise multiply every invoice in the country by eleven.
        $this->tax('1000');

        $quote = $this->quote();

        $this->assertSame(100.0, $quote['tax_rate']);
        $this->assertSame(round($quote['pre_tax_total'] * 2, 2), $quote['total']);
    }

    // ------------------------------------------------------------ on the order

    #[Test]
    public function the_rate_and_the_amount_are_stored_on_the_order(): void
    {
        $this->tax('14');

        $order = $this->place();

        // Stored, not derived: an invoice reconstructed a month later must not
        // depend on what the settings say then.
        $this->assertSame('14.00', $order->tax_rate);
        $this->assertGreaterThan(0.0, (float) $order->estimated_tax);
        $this->assertSame(
            round((float) $order->estimated_subtotal + (float) $order->delivery_fee
                - (float) $order->discount_total + (float) $order->cash_surcharge
                + (float) $order->estimated_tax, 2),
            (float) $order->estimated_total
        );
    }

    #[Test]
    public function an_order_keeps_the_rate_it_was_placed_under(): void
    {
        $this->tax('10');
        $order = $this->place();
        $originalTax = (float) $order->estimated_tax;
        $originalTotal = (float) $order->estimated_total;

        // The state raises it the next morning.
        $this->tax('25');

        $order->refresh();

        $this->assertSame(10.0, $order->taxRate());
        $this->assertSame($originalTax, (float) $order->estimated_tax);
        $this->assertSame($originalTotal, (float) $order->estimated_total);
    }

    #[Test]
    public function the_review_taxes_the_final_price_at_the_orders_own_rate(): void
    {
        $this->tax('10');
        $order = $this->place();

        $this->tax('25');

        $order = $this->pickedUp($order);

        $order = app(OrderReviewService::class)->review(
            $order,
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 5]],
            null,
            $this->tenant['owner'],
        )->fresh();

        // 10%, the order's own — not the 25% now in the settings.
        $this->assertSame(
            round(((float) $order->final_subtotal + (float) $order->delivery_fee
                - (float) $order->discount_total + (float) $order->cash_surcharge) * 0.10, 2),
            (float) $order->final_tax
        );
        $this->assertSame(
            round((float) $order->final_subtotal + (float) $order->delivery_fee
                - (float) $order->discount_total + (float) $order->cash_surcharge
                + (float) $order->final_tax, 2),
            (float) $order->final_total
        );
    }

    #[Test]
    public function the_final_total_keeps_the_cash_surcharge(): void
    {
        // The bug this fixes: the estimate added the surcharge and the review
        // recomputed the total without it, so a cash customer's handling fee
        // vanished the moment their pieces were counted.
        $this->tax(null);
        $this->surcharge('15');

        $order = $this->place('cash');
        $this->assertSame('15.00', $order->cash_surcharge);

        $order = $this->pickedUp($order);

        $order = app(OrderReviewService::class)->review(
            $order,
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            null,
            $this->tenant['owner'],
        )->fresh();

        $this->assertSame(
            round((float) $order->final_subtotal + (float) $order->delivery_fee
                - (float) $order->discount_total + 15.0, 2),
            (float) $order->final_total
        );
    }

    // ------------------------------------------------------------ what it shows

    #[Test]
    public function the_quote_endpoint_names_the_rate_as_well_as_the_amount(): void
    {
        $this->tax('14');

        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        Sanctum::actingAs($this->customer);

        // A number the app cannot label is a charge the customer cannot check.
        $this->postJson('/api/v1/orders/quote', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
        ], $this->apiHeaders())
            ->assertOk()
            // JSON has one number type: 14.0 comes back as int 14, so the
            // comparison is numeric rather than identical.
            ->assertJsonPath('data.tax_rate', fn ($value) => (float) $value === 14.0)
            ->assertJsonStructure(['data' => ['tax', 'tax_rate', 'pre_tax_total', 'total']]);
    }

    #[Test]
    public function the_order_detail_carries_the_tax(): void
    {
        $this->tax('14');
        $order = $this->place();

        Sanctum::actingAs($this->customer);

        $this->getJson("/api/v1/orders/{$order->id}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.pricing.tax_rate', fn ($value) => (float) $value === 14.0)
            ->assertJsonPath('data.pricing.estimated_tax', fn ($value) => (float) $value === (float) $order->estimated_tax)
            ->assertJsonPath('data.pricing.payable_total', fn ($value) => (float) $value === $order->payableTotal());
    }

    #[Test]
    public function the_invoice_shows_the_tax_line_and_the_rate(): void
    {
        $this->tax('14');
        $this->surcharge('15');
        $order = $this->place('cash');

        $this->grant('super_admin', ['order.view']);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.order.invoice', $order->id))
            ->assertOk();

        $response->assertSee('14%', false);
        $response->assertSee(moneyFormat($order->estimated_tax), false);
        // The line that was computed into the total and shown nowhere.
        $response->assertSee(moneyFormat($order->cash_surcharge), false);
        $response->assertSee(moneyFormat($order->preTaxTotal()), false);
    }

    #[Test]
    public function an_untaxed_invoice_draws_no_tax_line(): void
    {
        $this->tax(null);
        $order = $this->place();

        $this->grant('super_admin', ['order.view']);

        // A zero line on a bill is a question somebody has to ask.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.order.invoice', $order->id))
            ->assertOk()
            ->assertDontSee(__('Subtotal before tax'), false);
    }

    // ------------------------------------------------------------ the settings

    #[Test]
    public function the_rate_is_capped_on_the_settings_form(): void
    {
        $this->grant('super_admin', ['setting.view', 'setting.update']);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), ['Tax' => '150'])
            ->assertSessionHasErrors('Tax');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), ['Tax' => '14'])
            ->assertSessionHasNoErrors();
    }
}
