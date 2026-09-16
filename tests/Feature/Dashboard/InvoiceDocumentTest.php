<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Payment\Services\InvoiceRenderer;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The invoice, and the order screen it has to agree with.
 *
 * It was a price list with a name on top. `InvoiceRenderer` eager-loaded the
 * service and both addresses and the view rendered none of them, so a document
 * that charges tax could not say what was done, when, or where.
 *
 * The sharper fault was that the **same order showed different money on two
 * screens**: the order card called it «Estimated subtotal» and the invoice called
 * it «Subtotal», and only the invoice carried the subtotal-before-tax line,
 * because each Blade file assembled the rows itself. `Order::moneyRows()` is now
 * the only place that decides which rows exist and what they are called.
 */
class InvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->buyer = $this->customer();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private int $made = 0;

    private function order(array $overrides = []): Order
    {
        // A distinct shop per call: `laundries.email` is unique, and a test that
        // needs two orders to compare them would otherwise collide with itself.
        $tag = chr(65 + $this->made);
        $suffix = str_pad((string) (++$this->made), 2, '0', STR_PAD_LEFT);
        $laundry = $this->laundryWithOwner($tag, '+2010111100'.$suffix, '+2010111200'.$suffix)['laundry'];
        $address = $this->addressFor($this->buyer, $this->geo['zones'][0]);

        $order = Order::withoutGlobalScopes()->create($overrides + [
            'code' => Order::generateCode(),
            'user_id' => $this->buyer->id,
            'laundry_id' => $laundry->id,
            'service_id' => $this->catalog['service']->id,
            'status' => 'completed',
            'pickup_address_id' => $address->id,
            'delivery_address_id' => $address->id,
            'delivery_fee' => 20,
            'estimated_subtotal' => 100,
            'estimated_total' => 120,
            'final_total' => null,
            'payment_method' => 'cash',
            'qr_token' => Order::generateQrToken(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->catalog['items'][0]->id,
            'phase' => 'estimated',
            'qty' => 2,
            'unit_price' => 50,
            'line_total' => 100,
        ]);

        return $order->fresh();
    }

    // ------------------------------------------------- one source for the money

    #[Test]
    public function the_invoice_and_the_order_screen_bill_the_same_rows(): void
    {
        /*
         * The whole point. Both render `Order::moneyRows()`, so they cannot
         * disagree about which lines exist or what they are called — which they
         * did, for every order, in a way nobody notices until a customer holds
         * one document and an operator is looking at the other.
         */
        $order = $this->order(['discount_total' => 10, 'cash_surcharge' => 5]);

        $fromInvoice = app(InvoiceRenderer::class)->data($order)['money_rows'];

        $this->assertSame($order->moneyRows(), $fromInvoice);
    }

    #[Test]
    public function a_zero_row_is_left_off_but_free_delivery_is_said_out_loud(): void
    {
        $order = $this->order(['discount_total' => 0, 'cash_surcharge' => 0, 'delivery_fee' => 0]);

        $keys = array_column($order->moneyRows(), 'key');

        // «Discount EGP 0.00» invites the question of which discount.
        $this->assertNotContains('discount', $keys);
        $this->assertNotContains('cash_surcharge', $keys);
        // Free delivery is a thing worth saying, so the row stays at zero.
        $this->assertContains('delivery_fee', $keys);
    }

    #[Test]
    public function a_discount_is_carried_negative_so_no_renderer_has_to_remember_the_sign(): void
    {
        $order = $this->order(['discount_total' => 10]);

        $discount = collect($order->moneyRows())->firstWhere('key', 'discount');

        $this->assertSame(-10.0, $discount['amount']);
        $this->assertSame('credit', $discount['kind']);
    }

    #[Test]
    public function the_before_tax_line_appears_only_when_something_moved_the_subtotal(): void
    {
        // With nothing between subtotal and tax it would restate the row above it.
        $plain = $this->order(['delivery_fee' => 0, 'estimated_tax' => 5, 'tax_rate' => 5]);
        $this->assertNotContains('pre_tax', array_column($plain->fresh()->moneyRows(), 'key'));

        $adjusted = $this->order(['delivery_fee' => 20, 'estimated_tax' => 5, 'tax_rate' => 5]);
        $this->assertContains('pre_tax', array_column($adjusted->fresh()->moneyRows(), 'key'));
    }

    #[Test]
    public function the_tax_row_names_the_rate_the_order_was_placed_under(): void
    {
        // Not the rate the settings hold today: the rate is copied onto the order
        // at placement and never re-read, so an old invoice must not restate.
        $order = $this->order(['estimated_tax' => 7, 'tax_rate' => 14]);

        $tax = collect($order->fresh()->moneyRows())->firstWhere('key', 'tax');

        $this->assertSame('14%', $tax['note']);
    }

    #[Test]
    public function the_rows_add_up_to_the_total(): void
    {
        $order = $this->order([
            'delivery_fee' => 20, 'discount_total' => 10, 'cash_surcharge' => 5,
            'estimated_subtotal' => 100, 'estimated_tax' => 0, 'estimated_total' => 115,
        ]);

        $rows = collect($order->fresh()->moneyRows());
        $total = $rows->firstWhere('key', 'total')['amount'];
        $parts = $rows->whereIn('kind', ['line', 'credit'])->sum('amount');

        $this->assertEqualsWithDelta($total, $parts, 0.01, 'the printed lines do not reconcile to the printed total');
    }

    // --------------------------------------------- it is a document, not a price list

    #[Test]
    public function it_carries_what_was_done_and_when_and_where(): void
    {
        $order = $this->order();
        $data = app(InvoiceRenderer::class)->data($order);

        $this->assertNotNull($data['service'], 'the invoice cannot say what was bought');
        $this->assertNotNull($data['laundry'], 'the invoice cannot say who did the work');
        $this->assertSame(__('Hand to the customer'), $data['delivery']['method']);
        $this->assertNotNull($data['collection']['address']);
        $this->assertNotNull($data['status_label']);
    }

    #[Test]
    public function the_issue_date_is_a_date_and_not_a_relative_phrase(): void
    {
        // «2 weeks ago» is right on an operator's screen and useless on a
        // document somebody has to file against a date.
        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.order.invoice', $this->order()->id))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $html);
        $this->assertStringNotContainsString('ago', $html);
    }

    #[Test]
    public function the_settlement_never_reaches_the_customers_copy(): void
    {
        /*
         * Commission and the laundry's share are the platform's arrangement with
         * the shop. Printing them here publishes the margin to the person paying
         * it — and the order screen, which this borrowed details from, shows both.
         */
        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.order.invoice', $this->order()->id))
            ->assertOk()
            ->getContent();

        foreach ([__('Commission'), __('Laundry share'), __('Note to driver')] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    // ------------------------------------------------------ no seed data on it

    #[Test]
    public function a_placeholder_setting_never_reaches_a_customers_invoice(): void
    {
        /*
         * Every issuer setting on the live install is still the seeded value.
         * Naively printing them puts «BaseCode» and `nahrPhpTeam@…` on a
         * document a customer keeps — worse than printing nothing, which is what
         * `realSetting()` makes it do.
         */
        Setting::updateOrCreate(['key' => 'App_Name'], ['value' => 'BaseCode']);
        Setting::updateOrCreate(['key' => 'Email'], ['value' => 'nahrPhpTeam@nahrPhpTeam.com']);
        Cache::flush();

        $issuer = app(InvoiceRenderer::class)->data($this->order())['issuer'];

        $this->assertNotSame('BaseCode', $issuer['name']);
        $this->assertNull($issuer['email']);
    }

    #[Test]
    public function a_registered_name_wins_over_the_product_name(): void
    {
        // A registered business is often called something other than the product,
        // and the invoice is the one document that has to use the registered one.
        Setting::updateOrCreate(['key' => 'App_Name'], ['value' => 'Laundo']);
        Setting::updateOrCreate(['key' => 'Invoice_Legal_Name'], ['value' => 'Laundo for Laundry Services LLC']);
        Setting::updateOrCreate(['key' => 'Invoice_Tax_Number'], ['value' => '123-456-789']);
        Cache::flush();

        $issuer = app(InvoiceRenderer::class)->data($this->order())['issuer'];

        $this->assertSame('Laundo for Laundry Services LLC', $issuer['name']);
        $this->assertSame('123-456-789', $issuer['tax_number']);
    }
}
