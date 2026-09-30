<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Report\Data\DateRange;
use App\Modules\Report\Services\FinanceSummary;
use App\Modules\Report\Services\RevenueReport;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «ملخص الماليات» — the money that left the home page (the owner, 2026-09-30).
 *
 * What these guard is who does *not* see it: the home page has no permission,
 * so the money moved behind `finance.view`, which nobody holds until somebody
 * grants it — not a moderator, not a laundry owner (who holds `report.view`).
 */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

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
        $this->customer = $this->customer('+201055550001');
        $this->artisan('db:seed', ['--class' => 'PermissionSeeder']);
    }

    private function paidOrder(float $total, ?int $laundryId = null, $paidAt = null): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);

        $order->forceFill([
            'laundry_id' => $laundryId ?? $this->tenant['laundry']->id,
            'status' => OrderStatus::Completed->value,
            'final_total' => $total,
            'paid_at' => $paidAt ?? now(),
        ])->save();

        return $order->fresh();
    }

    private function moderator(): User
    {
        return User::create([
            'name' => 'Moderator', 'email' => 'moderator@test.local',
            'phone' => '+201055550009', 'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'phone_verified_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- who

    #[Test]
    public function nobody_opens_it_without_the_permission_but_the_super_admin(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.finance.index'))->assertOk();

        $this->actingAs($this->moderator())->get(route('admin.finance.index'))->assertForbidden();

        // A laundry owner holds `report.view` — which is exactly why this page
        // does not borrow it.
        $this->actingAs($this->tenant['owner'])->get(route('admin.finance.index'))->assertForbidden();
    }

    #[Test]
    public function granting_it_opens_it(): void
    {
        $moderator = $this->moderator();
        $this->grant('admin', ['finance.view']);

        $this->actingAs($moderator)->get(route('admin.finance.index'))->assertOk();
    }

    #[Test]
    public function the_sidebar_offers_it_only_to_whoever_may_open_it(): void
    {
        $link = 'href="'.route('admin.finance.index').'"';

        $this->actingAs($this->superAdmin())->get('/admin/home')->assertSee($link, false);
        $this->actingAs($this->tenant['owner'])->get('/admin/home')->assertDontSee($link, false);

        $moderator = $this->moderator();
        $this->grant('admin', ['order.view']);
        $this->actingAs($moderator)->get('/admin/home')->assertDontSee($link, false);

        $this->grant('admin', ['order.view', 'finance.view']);
        // Fresh: the sidebar reads the role's permissions off the user, and the
        // instance above still holds the ones it loaded before the grant.
        $this->actingAs($moderator->fresh())->get('/admin/home')->assertSee($link, false);
    }

    // ---------------------------------------------------------------- what

    #[Test]
    public function it_shows_the_money_the_home_page_no_longer_does(): void
    {
        $this->paidOrder(123.45);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee(moneyFormat(123.45), false)
            ->assertSee(__('Net revenue'))
            ->assertSee(__('Owed to us'))
            ->assertSee('id="viz-data-money"', false);
    }

    #[Test]
    public function money_taken_today_is_dated_by_payment_not_by_order(): void
    {
        $old = $this->paidOrder(80);
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();

        $this->actingAs($this->superAdmin());

        // Paid today for an order placed two months ago counts today — the rule
        // the revenue report uses, so the two cannot disagree.
        $this->assertEquals(80.0, app(FinanceSummary::class)->today()['money_taken']);
        $this->assertSame(1, app(FinanceSummary::class)->today()['paid_orders']);
    }

    #[Test]
    public function the_month_is_the_revenue_reports_own_figures(): void
    {
        $this->paidOrder(100);
        $this->paidOrder(50, null, now()->subMonths(2));

        $this->actingAs($this->superAdmin());

        $report = app(RevenueReport::class)->summary(new DateRange(now()->startOfMonth(), now()->endOfDay()));
        $month = app(FinanceSummary::class)->thisMonth();

        $this->assertEquals($report['net'], $month['net_revenue']);
        $this->assertEquals($report['receivables'], $month['receivables']);
        $this->assertSame(1, $month['paid_orders']);
    }

    #[Test]
    public function money_per_day_covers_fourteen_days_quiet_ones_included(): void
    {
        $this->paidOrder(40);
        $this->paidOrder(60, null, now()->subDays(2));

        $this->actingAs($this->superAdmin());
        $days = collect(app(FinanceSummary::class)->byDay())->keyBy('date');

        $this->assertCount(14, $days);
        $this->assertEquals(40.0, $days[now()->toDateString()]['total']);
        $this->assertEquals(60.0, $days[now()->subDays(2)->toDateString()]['total']);
        $this->assertEquals(0.0, $days[now()->subDay()->toDateString()]['total']);
    }

    // ------------------------------------------------------------- more of it

    #[Test]
    public function the_month_is_compared_with_the_same_days_of_last_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00'));

        $this->paidOrder(200, null, Carbon::parse('2026-09-10 10:00'));
        $this->paidOrder(100, null, Carbon::parse('2026-08-10 10:00'));
        // After the 15th of August: not «the same days», so not compared.
        $this->paidOrder(999, null, Carbon::parse('2026-08-20 10:00'));

        $this->actingAs($this->superAdmin());
        $gross = app(FinanceSummary::class)->compared()['gross'];

        $this->assertEquals(200.0, $gross['now']);
        $this->assertEquals(100.0, $gross['before']);
        $this->assertEquals(100.0, $gross['change']);
    }

    #[Test]
    public function nothing_last_month_gives_no_percentage(): void
    {
        $this->paidOrder(50);

        $this->actingAs($this->superAdmin());

        // «Up from zero» is not a percentage.
        $this->assertNull(app(FinanceSummary::class)->compared()['gross']['change']);
    }

    private function settlement(Order $order, string $status, float $laundry, float $platform, $settledAt = null): OrderSettlement
    {
        return OrderSettlement::withoutGlobalScopes()->create([
            'order_id' => $order->id,
            'laundry_id' => $order->laundry_id,
            'basis' => $laundry + $platform,
            'commission_rate' => 0,
            'commission_amount' => $platform,
            'laundry_amount' => $laundry,
            'platform_fee_amount' => 5,
            'tax_amount' => 3,
            'status' => $status,
            'settled_at' => $status === OrderSettlement::SETTLED ? ($settledAt ?? now()) : null,
        ]);
    }

    #[Test]
    public function the_split_is_this_months_settled_orders_and_nothing_else(): void
    {
        $this->settlement($this->paidOrder(100), OrderSettlement::SETTLED, 90, 10);
        $this->settlement($this->paidOrder(100), OrderSettlement::PENDING, 80, 20);
        $this->settlement($this->paidOrder(100), OrderSettlement::SETTLED, 70, 30, now()->subMonths(2));

        $this->actingAs($this->superAdmin());
        $split = app(FinanceSummary::class)->split();

        $this->assertSame(1, $split['orders']);
        $this->assertEquals(90.0, $split['laundries']);
        $this->assertEquals(10.0, $split['platform']);
        $this->assertEquals(5.0, $split['platform_fees']);
        $this->assertEquals(3.0, $split['tax']);
    }

    #[Test]
    public function a_platform_that_paid_for_a_coupon_is_shown_below_zero(): void
    {
        $this->settlement($this->paidOrder(100), OrderSettlement::SETTLED, 95, -15);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee(moneyFormat(-15), false)
            ->assertSee(__('Discounts the platform paid for cost more than its part'));
    }

    #[Test]
    public function owed_counts_unsettled_shares_and_unreleased_bonuses(): void
    {
        $order = $this->paidOrder(100);
        $this->settlement($order, OrderSettlement::PENDING, 45, 5);

        $driver = $this->driverUser('+201066660001');
        DriverEarning::create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'order_task_id' => $order->tasks()->orderBy('sequence')->firstOrFail()->id,
            'amount' => 12, 'basis' => 48, 'rate' => 0.25,
            'status' => DriverEarning::PENDING,
        ]);

        $this->actingAs($this->superAdmin());
        $owed = app(FinanceSummary::class)->owed();

        $this->assertSame(1, $owed['settlements']['count']);
        $this->assertEquals(45.0, $owed['settlements']['amount']);
        $this->assertEquals(12.0, $owed['drivers']);
    }

    #[Test]
    public function a_laundry_is_not_shown_the_drivers_figure_or_the_ranking(): void
    {
        $this->paidOrder(100);
        $this->grant('laundry_owner', ['finance.view']);

        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.finance.index'))
            ->assertOk()
            // Driver earnings carry no laundry_id; the ranking would be a list of one.
            ->assertDontSee(__('Driver bonuses not released yet'))
            ->assertDontSee(__('Top laundries'))
            ->assertSee(__('By service'));

        $this->assertNull(app(FinanceSummary::class)->owed()['drivers']);
    }

    #[Test]
    public function payment_methods_read_by_their_labels_and_keep_their_colours(): void
    {
        $this->paidOrder(100)->forceFill(['payment_method' => 'card'])->save();
        $this->paidOrder(40)->forceFill(['payment_method' => 'cash'])->save();

        $this->actingAs($this->superAdmin());
        $methods = collect(app(FinanceSummary::class)->byMethod())->keyBy('label');

        $card = $methods[__(PaymentMethod::Card->label())];
        $cash = $methods[__(PaymentMethod::Cash->label())];

        $this->assertEquals(100.0, $card['total']);
        // Cash takes the first colour — the method this install runs on —
        // even in a month card earned more; the rest follow the enum.
        $this->assertSame(1, $cash['slot']);
        $this->assertSame(2, $card['slot']);
    }

    #[Test]
    public function the_rankings_keep_the_top_and_sum_the_rest(): void
    {
        $this->paidOrder(100);
        $this->paidOrder(30)->forceFill(['service_id' => $this->catalog['quoted']->id])->save();

        $this->actingAs($this->superAdmin());
        $top = app(FinanceSummary::class)->top('service', 1);

        $this->assertCount(1, $top['rows']);
        $this->assertEquals(100.0, $top['rows'][0]['total']);
        $this->assertEquals(30.0, $top['other']);
    }

    #[Test]
    public function a_laundry_granted_the_page_reads_only_its_own_money(): void
    {
        $other = $this->laundryWithOwner('B', '+201011110003', '+201011110004');
        $this->paidOrder(70);
        $this->paidOrder(900, $other['laundry']->id);

        $this->grant('laundry_owner', ['finance.view']);

        $this->actingAs($this->tenant['owner'])
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee(moneyFormat(70), false)
            ->assertDontSee(moneyFormat(900), false)
            ->assertDontSee(moneyFormat(970), false);
    }
}
