<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Payment\Models\Refund;
use App\Modules\Report\Data\DateRange;
use App\Modules\Report\Services\OperationsReport;
use App\Modules\Report\Services\OrderReport;
use App\Modules\Report\Services\RevenueReport;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reports.
 *
 * A report that is merely plausible is worse than no report: nobody checks a
 * number that looks about right, and every decision after it inherits the error.
 * So these assert exact figures against rows the test placed itself, rather than
 * "greater than zero".
 *
 * The definitions being defended are the ones that were decided rather than
 * derived: **revenue is paid orders** (cash included, since a driver does not mark
 * one paid until the money is in hand), **receivables sit outside revenue**, and
 * **refunds are dated by when they were paid out**.
 */
class ReportTest extends TestCase
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

        foreach ($this->geo['zones'] as $zone) {
            $zone->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
        }

        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);
    }

    // -------------------------------------------------------------- revenue

    #[Test]
    public function revenue_counts_paid_orders_whatever_the_method(): void
    {
        // Cash has no `payments` row at all — summing payments would miss it.
        $cash = $this->paidOrder('cash');
        $card = $this->paidOrder('card', '01099887701');
        $this->placedOrder('01099887702');   // unpaid, must not count

        $summary = app(RevenueReport::class)->summary(DateRange::lastDays(30));

        $expected = round($cash->payableTotal() + $card->payableTotal(), 2);

        $this->assertSame(2, $summary['orders']);
        $this->assertEqualsWithDelta($expected, $summary['gross'], 0.01);
        $this->assertEqualsWithDelta($expected, $summary['net'], 0.01);
    }

    #[Test]
    public function a_delivered_but_unpaid_order_is_receivables_and_never_revenue(): void
    {
        $order = $this->placedOrder();
        $order->update(['status' => OrderStatus::Delivered, 'payment_status' => 'unpaid']);

        $summary = app(RevenueReport::class)->summary(DateRange::lastDays(30));

        // Not income; a number somebody has to chase.
        $this->assertSame(0, $summary['orders']);
        $this->assertSame(0.0, $summary['gross']);
        $this->assertEqualsWithDelta($order->payableTotal(), $summary['receivables'], 0.01);
    }

    #[Test]
    public function refunds_are_deducted_on_the_day_they_were_paid_out(): void
    {
        $order = $this->paidOrder('cash');

        Refund::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'amount' => 20,
            'reason' => 'damaged',
            'status' => Refund::SETTLED,
            'destination' => Refund::TO_WALLET,
            // Paid out long ago, though the order is from today.
            'settled_at' => now()->subDays(90),
        ]);

        $recent = app(RevenueReport::class)->summary(DateRange::lastDays(30));

        // A closed report that changes retroactively is one nobody can trust.
        $this->assertSame(0.0, $recent['refunds']);
        $this->assertEqualsWithDelta($order->payableTotal(), $recent['net'], 0.01);

        $wide = app(RevenueReport::class)->summary(DateRange::lastDays(120));
        $this->assertSame(20.0, $wide['refunds']);
        $this->assertEqualsWithDelta($order->payableTotal() - 20, $wide['net'], 0.01);
    }

    #[Test]
    public function the_daily_series_has_a_row_for_every_day_including_the_empty_ones(): void
    {
        $this->paidOrder('cash');

        $range = DateRange::lastDays(7);
        $daily = app(RevenueReport::class)->daily($range);

        // A chart that skips a quiet Tuesday draws straight over it.
        $this->assertCount(7, $daily);
        $this->assertSame($range->eachDay(), array_column($daily, 'date'));

        $nonZero = array_filter($daily, fn ($d) => $d['total'] > 0);
        $this->assertCount(1, $nonZero);
    }

    #[Test]
    public function an_empty_range_returns_zeroes_rather_than_breaking(): void
    {
        $this->paidOrder('cash');

        $long_ago = new DateRange(now()->subYears(2)->startOfDay(), now()->subYears(2)->endOfDay());
        $summary = app(RevenueReport::class)->summary($long_ago);

        $this->assertSame(0, $summary['orders']);
        $this->assertSame(0.0, $summary['gross']);
        $this->assertSame(0.0, $summary['average_order']);
        $this->assertSame([], app(RevenueReport::class)->byLaundry($long_ago));
    }

    #[Test]
    public function a_backwards_range_is_swapped_rather_than_returning_nothing(): void
    {
        $request = Request::create('/', 'GET', ['from' => '2026-03-31', 'to' => '2026-03-01']);
        $range = DateRange::fromRequest($request);

        // Zero rows over a month reads exactly like a quiet month.
        $this->assertSame('2026-03-01', $range->from->toDateString());
        $this->assertSame('2026-03-31', $range->to->toDateString());
    }

    #[Test]
    public function an_absurd_range_is_clamped_instead_of_drawing_thirty_thousand_bars(): void
    {
        // The dates come off a URL meant to be pasted, and the daily series carries
        // one entry per day. Unclamped, this asks the chart for 36,525 bars.
        $request = Request::create('/', 'GET', ['from' => '1900-01-01', 'to' => '2099-12-31']);
        $range = DateRange::fromRequest($request);

        $this->assertSame(DateRange::MAX_DAYS, $range->days());
        $this->assertCount(DateRange::MAX_DAYS, $range->eachDay());

        // The end the person asked for is kept: a report is read backwards from a
        // known date, so it is the start that gives way.
        $this->assertSame('2099-12-31', $range->to->toDateString());
    }

    #[Test]
    public function a_range_inside_the_limit_is_left_exactly_as_asked_for(): void
    {
        $request = Request::create('/', 'GET', ['from' => '2026-01-01', 'to' => '2026-01-31']);
        $range = DateRange::fromRequest($request);

        $this->assertSame('2026-01-01', $range->from->toDateString());
        $this->assertSame('2026-01-31', $range->to->toDateString());
        $this->assertSame(31, $range->days());
    }

    #[Test]
    public function a_range_exactly_at_the_limit_is_not_clamped(): void
    {
        // The boundary is worth its own test: `from` is a start-of-day and `to` an
        // end-of-day, so the raw diff is a fraction under the whole number and an
        // uncast comparison trims a day off a range that was already legal.
        $to = now()->endOfDay();
        $from = $to->copy()->subDays(DateRange::MAX_DAYS - 1)->startOfDay();

        $range = DateRange::fromRequest(Request::create('/', 'GET', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]));

        $this->assertSame(DateRange::MAX_DAYS, $range->days());
        $this->assertSame($from->toDateString(), $range->from->toDateString());
    }

    #[Test]
    public function the_form_shows_the_range_the_report_actually_used(): void
    {
        $this->actingAs($this->tenant['owner']);

        // A clamp nobody can see is a report quietly covering a different window
        // from the one in the address bar.
        $this->get('/admin/report/revenue?from=1900-01-01&to=2099-12-31')
            ->assertOk()
            ->assertDontSee('value="1900-01-01"', false)
            ->assertSee('value="2099-12-31"', false);
    }

    // --------------------------------------------------------------- orders

    #[Test]
    public function the_price_movement_report_measures_only_reviewed_orders(): void
    {
        // Reviewed upward: the customer said 2, the laundry counted 3.
        $up = $this->reviewedOrder(2, 3);
        // Reviewed downward.
        $down = $this->reviewedOrder(3, 2, '01099887711');
        // Never reviewed — folding this in as a zero would drag the average
        // toward «nothing changes» and hide the effect.
        $this->placedOrder('01099887712');

        $movement = app(OrderReport::class)->priceMovement(DateRange::lastDays(30));

        $this->assertSame(2, $movement['reviewed']);
        $this->assertSame(1, $movement['increased']);
        $this->assertSame(1, $movement['decreased']);
        $this->assertSame(0, $movement['unchanged']);
        $this->assertSame(50.0, $movement['increase_rate']);

        $expected = round(
            ((float) $up->fresh()->final_total - (float) $up->estimated_total)
            + ((float) $down->fresh()->final_total - (float) $down->estimated_total),
            2
        );

        $this->assertEqualsWithDelta($expected, $movement['total_change'], 0.01);
        $this->assertEqualsWithDelta($expected / 2, $movement['average_change'], 0.01);
    }

    #[Test]
    public function price_movement_on_nothing_reviewed_is_zero_not_a_division_by_zero(): void
    {
        $this->placedOrder();

        $movement = app(OrderReport::class)->priceMovement(DateRange::lastDays(30));

        $this->assertSame(0, $movement['reviewed']);
        $this->assertSame(0.0, $movement['average_change']);
        $this->assertSame(0.0, $movement['increase_rate']);
    }

    #[Test]
    public function the_order_summary_reports_rates_not_just_counts(): void
    {
        $this->placedOrder();
        $this->placedOrder('01099887721');
        $cancelled = $this->placedOrder('01099887722');

        app(OrderService::class)->cancel($cancelled, User::findOrFail($cancelled->user_id), 'changed my mind');

        $summary = app(OrderReport::class)->summary(DateRange::lastDays(30));

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['cancelled']);
        // A count means nothing without the volume it came out of.
        $this->assertEqualsWithDelta(33.3, $summary['cancellation_rate'], 0.1);
    }

    // ------------------------------------------------------------ isolation

    #[Test]
    public function a_laundry_owner_sees_only_their_own_revenue(): void
    {
        $mine = $this->paidOrder('cash');

        // A second laundry with its own paid order.
        $other = $this->laundryWithOwner('B', '01022220001', '01022220002');
        $this->cover($other['laundry'], $this->geo['zones'][1]->id, $this->catalog['service']->id);

        $theirCustomer = $this->customer('01099887731');
        $theirAddress = $this->addressFor($theirCustomer, $this->geo['zones'][1], 29.96, 31.26);

        $theirs = app(OrderService::class)->place($theirCustomer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $theirAddress->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
        $theirs->update(['payment_status' => 'paid', 'paid_at' => now(), 'payment_method' => 'cash']);

        // The super admin sees both.
        $this->actingAs($this->superAdmin());
        $this->assertSame(2, app(RevenueReport::class)->summary(DateRange::lastDays(30))['orders']);

        // Laundry A's owner sees one, with no rule in the report to get wrong.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->tenant['owner']);

        $scoped = app(RevenueReport::class)->summary(DateRange::lastDays(30));
        $this->assertSame(1, $scoped['orders']);
        $this->assertEqualsWithDelta($mine->payableTotal(), $scoped['gross'], 0.01);
    }

    #[Test]
    public function the_pages_render_for_a_super_admin(): void
    {
        $this->paidOrder('cash');
        $admin = $this->superAdmin();

        foreach (['revenue', 'orders', 'laundries', 'drivers', 'operations'] as $report) {
            $this->actingAs($admin)->get("/admin/report/{$report}")->assertOk();
        }
    }

    #[Test]
    public function a_laundry_owner_gets_the_scoped_reports_and_not_the_others(): void
    {
        $this->paidOrder('cash');
        $owner = $this->tenant['owner'];

        foreach (['revenue', 'orders', 'laundries'] as $report) {
            $this->actingAs($owner)->get("/admin/report/{$report}")->assertOk();
        }

        // Drivers are not tenant-scoped, so the scope would not stop one laundry
        // seeing another's. These carry their own permission.
        foreach (['drivers', 'operations'] as $report) {
            $this->actingAs($owner)->get("/admin/report/{$report}")->assertForbidden();
        }
    }

    #[Test]
    public function a_customer_cannot_reach_any_report(): void
    {
        $customer = $this->customer('01099887741');

        foreach (['revenue', 'orders', 'operations'] as $report) {
            $this->actingAs($customer)->get("/admin/report/{$report}")->assertForbidden();
        }
    }

    // --------------------------------------------------------------- export

    #[Test]
    public function the_csv_export_streams_a_utf8_file_excel_can_read(): void
    {
        $this->paidOrder('cash');

        $response = $this->actingAs($this->superAdmin())
            ->get('/admin/report/export/revenue?from='.now()->subDays(6)->toDateString().'&to='.now()->toDateString());

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $response->streamedContent();

        // The BOM is how most of these files actually get read: without it Excel
        // renders Arabic as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('date,orders,total', $body);
        $this->assertSame(8, substr_count(trim($body), "\n") + 1); // header + 7 days
    }

    #[Test]
    public function an_unknown_export_is_not_found(): void
    {
        $this->actingAs($this->superAdmin())->get('/admin/report/export/nonsense')->assertNotFound();
    }

    // --------------------------------------------------- operations health

    #[Test]
    public function operations_health_surfaces_what_earlier_phases_left_open(): void
    {
        // An order waiting on a customer — the P7 silence problem.
        $waiting = $this->reviewedOrder(2, 2);
        $waiting->fresh()->update(['reviewed_at' => now()->subHours(30)]);

        // A wallet that has drifted from its ledger.
        $customer = $this->customer('01099887751');
        $wallets = app(WalletService::class);
        $wallets->credit($customer, 100, TransactionReason::TopUp);
        $wallets->forUser($customer)->forceFill(['balance' => 999])->save();

        // A failed notification.
        NotificationLog::create([
            'user_id' => $customer->id,
            'event' => 'order_placed',
            'channel' => 'push',
            'status' => NotificationLog::FAILED,
            'failure_reason' => 'token rejected permanently',
        ]);

        $snapshot = app(OperationsReport::class)->snapshot();

        $this->assertCount(1, $snapshot['orders_awaiting_customer']);
        $this->assertSame(30, $snapshot['orders_awaiting_customer'][0]['waiting_hours']);

        $this->assertCount(1, $snapshot['wallets_out_of_balance']);
        $this->assertEqualsWithDelta(899.0, $snapshot['wallets_out_of_balance'][0]['difference'], 0.01);

        $this->assertSame(1, $snapshot['notifications_failed']);
    }

    #[Test]
    public function a_healthy_system_reports_empty_lists_not_errors(): void
    {
        $snapshot = app(OperationsReport::class)->snapshot();

        $this->assertSame([], $snapshot['orders_awaiting_customer']);
        $this->assertSame([], $snapshot['wallets_out_of_balance']);
        $this->assertSame(0, $snapshot['refunds_pending']);
    }

    // ------------------------------------------------------------------ helpers

    private function placedOrder(string $phone = '01099887766'): Order
    {
        $customer = $this->customer($phone);
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
    }

    private function paidOrder(string $method, string $phone = '01099887766'): Order
    {
        $order = $this->placedOrder($phone);

        $order->update([
            'status' => OrderStatus::Completed,
            'payment_status' => 'paid',
            'paid_at' => now(),
            'payment_method' => $method,
        ]);

        return $order->fresh();
    }

    /** Placed with one count, reviewed with another. */
    private function reviewedOrder(int $ordered, int $counted, string $phone = '01099887766'): Order
    {
        $customer = $this->customer($phone);
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => $ordered]],
            'accepts_review_terms' => true,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->transition($order, OrderStatus::DriverOnWay, 'driver');
        $order = $machine->transition($order->fresh(), OrderStatus::PickedUp, 'driver');

        app(OrderReviewService::class)->review(
            $order,
            [['item_id' => $this->catalog['items'][0]->id, 'qty' => $counted]],
            null,
            $this->tenant['owner']
        );

        return $order->fresh();
    }
}
