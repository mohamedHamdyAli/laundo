<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Models\LaundryDeduction;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «إيرادات المغاسل» — one row per laundry, and what it came to.
 *
 * The rows are built here from stored `orders` and `order_settlements` rather
 * than by walking an order through the state machine, and that is the point
 * rather than a shortcut: this screen's contract is that it **reads** what
 * `SettlementService` already decided. `CommissionSettlementTest` owns the
 * arithmetic that produces those rows; what is asserted here is that the screen
 * adds them up without re-deriving any of them.
 *
 * What is guarded hardest:
 *
 *  - **A pending share and a settled share are different columns.** Collapsing
 *    them would report money as received that has not moved.
 *  - **A cancelled settlement contributes nothing** — the order never completed,
 *    so nobody is owed.
 *  - **The payer does not hold the dial.** Reading is `laundry_revenue.view`;
 *    deducting is `setting.update`, which no laundry role holds.
 *  - **A deduction is reversed, never deleted**, so the reason survives.
 */
class LaundryRevenueTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $alpha;

    /** @var array<string, mixed> */
    private array $beta;

    private User $customer;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->alpha = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->beta = $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        $this->customer = $this->customer('+201099880011');
    }

    // ------------------------------------------------------------- fixtures

    /**
     * An order against a laundry, with the settlement it would have produced.
     *
     * `withoutGlobalScopes()` on the settlement for the same reason
     * `SettlementService` uses it: the creating hook would otherwise rewrite
     * `laundry_id` to the acting tenant's own, and a test acts as nobody.
     */
    private function order(
        Laundry $laundry,
        OrderStatus $status = OrderStatus::Completed,
        float $paid = 0.0,
        ?float $basis = null,
        float $commission = 0.0,
        float $tax = 0.0,
        string $settlementStatus = OrderSettlement::SETTLED,
        ?string $createdAt = null,
    ): Order {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);
        $this->sequence++;

        $order = Order::withoutGlobalScopes()->create([
            'code' => 'ORD-'.str_pad((string) $this->sequence, 5, '0', STR_PAD_LEFT),
            'user_id' => $this->customer->id,
            'laundry_id' => $laundry->id,
            'service_id' => $this->catalog['service']->id,
            'status' => $status,
            'pickup_address_id' => $address->id,
            'delivery_address_id' => $address->id,
            'estimated_total' => $paid,
            'final_total' => $paid,
            'payment_status' => $paid > 0 ? 'paid' : 'unpaid',
            'paid_at' => $paid > 0 ? now() : null,
            'qr_token' => 'qr-'.$this->sequence.'-'.$laundry->id,
        ]);

        if ($createdAt !== null) {
            // Written past the model so `updated_at` does not drag the row back
            // to now: the window is read off `created_at` alone.
            Order::withoutGlobalScopes()->where('id', $order->id)
                ->update(['created_at' => $createdAt]);
        }

        if ($basis !== null) {
            OrderSettlement::withoutGlobalScopes()->create([
                'order_id' => $order->id,
                'laundry_id' => $laundry->id,
                'basis' => $basis,
                'commission_rate' => $basis > 0 ? round($commission / $basis * 100, 2) : 0,
                'commission_amount' => $commission,
                'laundry_amount' => round($basis - $commission, 2),
                'tax_amount' => $tax,
                'status' => $settlementStatus,
                'settled_at' => $settlementStatus === OrderSettlement::SETTLED ? now() : null,
            ]);
        }

        return $order->fresh();
    }

    /**
     * Somebody who may read the screen but not write to it.
     */
    private function viewer(): User
    {
        $this->grant('admin', ['laundry_revenue.view']);

        return User::create([
            'name' => 'Viewer', 'email' => 'viewer@test.local',
            'phone' => '+201055550001', 'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed> the row the screen built for this laundry
     */
    private function rowFor(int $laundryId, array $query = []): array
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.laundry_revenue.index', $query));

        $response->assertOk();

        $rows = collect($response->viewData('rows')->items());

        return $rows->firstWhere('id', $laundryId) ?? [];
    }

    // ----------------------------------------------------------- the figures

    #[Test]
    public function a_row_reports_what_the_settlements_already_stored(): void
    {
        // 100 of washing, 10 of it the platform's, so 90 is the laundry's — the
        // owner's own worked example, arriving here as two stored rows.
        $this->order($this->alpha['laundry'], paid: 134.0, basis: 100.0, commission: 10.0, tax: 14.0);

        $row = $this->rowFor($this->alpha['laundry']->id);

        $this->assertSame(1, $row['orders']);
        $this->assertSame(1, $row['completed']);
        $this->assertEqualsWithDelta(134.0, $row['user_paid'], 0.001);
        $this->assertEqualsWithDelta(14.0, $row['tax'], 0.001);
        $this->assertEqualsWithDelta(10.0, $row['commission'], 0.001);
        $this->assertEqualsWithDelta(90.0, $row['entitled'], 0.001);
        $this->assertEqualsWithDelta(90.0, $row['received'], 0.001);
        // Nothing has been taken back, so the net is what arrived.
        $this->assertEqualsWithDelta(90.0, $row['net_payable'], 0.001);
    }

    #[Test]
    public function a_pending_share_is_entitled_but_not_received(): void
    {
        // The gap this screen exists to show: recorded when the price was
        // agreed, and not yet moved into anybody's wallet.
        $this->order(
            $this->alpha['laundry'],
            status: OrderStatus::Confirmed,
            paid: 0.0,
            basis: 200.0,
            commission: 20.0,
            settlementStatus: OrderSettlement::PENDING,
        );

        $row = $this->rowFor($this->alpha['laundry']->id);

        $this->assertEqualsWithDelta(180.0, $row['entitled'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['received'], 0.001);
        // Unpaid, so nothing was collected from the customer either.
        $this->assertEqualsWithDelta(0.0, $row['user_paid'], 0.001);
    }

    #[Test]
    public function a_cancelled_settlement_owes_nobody_anything(): void
    {
        $this->order(
            $this->alpha['laundry'],
            status: OrderStatus::Cancelled,
            basis: 300.0,
            commission: 30.0,
            tax: 42.0,
            settlementStatus: OrderSettlement::CANCELLED,
        );

        $row = $this->rowFor($this->alpha['laundry']->id);

        // The order is still counted — it happened — but none of its money is.
        $this->assertSame(1, $row['orders']);
        $this->assertSame(1, $row['cancelled']);
        $this->assertEqualsWithDelta(0.0, $row['commission'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['entitled'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['tax'], 0.001);
    }

    #[Test]
    public function the_three_outcomes_add_back_to_the_order_count(): void
    {
        $laundry = $this->alpha['laundry'];

        $this->order($laundry, status: OrderStatus::Completed, basis: 50.0, commission: 5.0);
        $this->order($laundry, status: OrderStatus::Returned, basis: 50.0, commission: 5.0, settlementStatus: OrderSettlement::CANCELLED);
        $this->order($laundry, status: OrderStatus::Cleaning);
        $this->order($laundry, status: OrderStatus::Cleaning);

        $row = $this->rowFor($laundry->id);

        $this->assertSame(4, $row['orders']);
        $this->assertSame(1, $row['completed']);
        // `returned` sits with `cancelled`: the pieces came back, so the laundry
        // earned nothing, even though the delivery fee is still owed.
        $this->assertSame(1, $row['cancelled']);
        $this->assertSame(2, $row['in_progress']);
        $this->assertSame(
            $row['orders'],
            $row['completed'] + $row['cancelled'] + $row['in_progress'],
        );
    }

    #[Test]
    public function one_laundry_figures_never_leak_into_another(): void
    {
        $this->order($this->alpha['laundry'], paid: 100.0, basis: 100.0, commission: 10.0);
        $this->order($this->beta['laundry'], paid: 500.0, basis: 400.0, commission: 40.0);

        $alpha = $this->rowFor($this->alpha['laundry']->id);
        $beta = $this->rowFor($this->beta['laundry']->id);

        $this->assertEqualsWithDelta(10.0, $alpha['commission'], 0.001);
        $this->assertEqualsWithDelta(40.0, $beta['commission'], 0.001);
    }

    #[Test]
    public function the_cards_cover_every_laundry_not_the_page(): void
    {
        $this->order($this->alpha['laundry'], paid: 114.0, basis: 100.0, commission: 10.0, tax: 14.0);
        $this->order($this->beta['laundry'], paid: 228.0, basis: 200.0, commission: 20.0, tax: 28.0);

        $response = $this->actingAs($this->superAdmin())->get(route('admin.laundry_revenue.index'));
        $summary = $response->viewData('summary');

        $this->assertSame(2, $summary['orders']);
        $this->assertEqualsWithDelta(342.0, $summary['user_paid'], 0.001);
        $this->assertEqualsWithDelta(42.0, $summary['tax'], 0.001);
        $this->assertEqualsWithDelta(30.0, $summary['commission'], 0.001);
        $this->assertEqualsWithDelta(270.0, $summary['laundries_receive'], 0.001);
        $this->assertSame(2, $summary['laundries']);
    }

    // -------------------------------------------------------------- the window

    #[Test]
    public function the_window_filters_by_the_order_date(): void
    {
        $this->order($this->alpha['laundry'], paid: 100.0, basis: 100.0, commission: 10.0, createdAt: '2026-03-15 10:00:00');
        $this->order($this->alpha['laundry'], paid: 200.0, basis: 200.0, commission: 20.0, createdAt: '2026-05-15 10:00:00');

        $march = $this->rowFor($this->alpha['laundry']->id, ['year' => '2026', 'month' => '3']);

        $this->assertSame(1, $march['orders']);
        $this->assertEqualsWithDelta(100.0, $march['user_paid'], 0.001);

        $year = $this->rowFor($this->alpha['laundry']->id, ['year' => '2026']);

        $this->assertSame(2, $year['orders']);
        $this->assertEqualsWithDelta(300.0, $year['user_paid'], 0.001);
    }

    #[Test]
    public function explicit_dates_beat_the_dropdowns(): void
    {
        $this->order($this->alpha['laundry'], paid: 100.0, createdAt: '2026-03-15 10:00:00');
        $this->order($this->alpha['laundry'], paid: 200.0, createdAt: '2026-05-15 10:00:00');

        // A year and month that would select March, overridden by dates that
        // select May. Saying which won is the whole reason the screen prints a
        // note when both are set.
        $row = $this->rowFor($this->alpha['laundry']->id, [
            'year' => '2026', 'month' => '3',
            'from' => '2026-05-01', 'to' => '2026-05-31',
        ]);

        $this->assertSame(1, $row['orders']);
        $this->assertEqualsWithDelta(200.0, $row['user_paid'], 0.001);
    }

    #[Test]
    public function a_laundry_with_no_orders_still_has_a_row_reading_zero(): void
    {
        $this->order($this->alpha['laundry'], paid: 100.0, basis: 100.0, commission: 10.0);

        $row = $this->rowFor($this->beta['laundry']->id);

        // A missing row would read as «no such laundry»; a zero reads as «they
        // did nothing this month», which is the true and useful answer.
        $this->assertNotSame([], $row);
        $this->assertSame(0, $row['orders']);
        $this->assertEqualsWithDelta(0.0, $row['net_payable'], 0.001);
    }

    // ----------------------------------------------------------- deductions

    #[Test]
    public function a_deduction_lowers_what_is_payable_without_touching_what_was_earned(): void
    {
        $laundry = $this->alpha['laundry'];
        $this->order($laundry, paid: 100.0, basis: 100.0, commission: 10.0);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry_revenue.deduct', $laundry->id), [
                'amount' => 25,
                'reason' => 'Refund issued to a customer on their behalf',
            ])
            ->assertRedirect();

        $row = $this->rowFor($laundry->id);

        // Earned is untouched; only what is payable moves. A deduction that
        // rewrote the settlement would put this screen at odds with the
        // settlements list and the wallet.
        $this->assertEqualsWithDelta(90.0, $row['entitled'], 0.001);
        $this->assertEqualsWithDelta(90.0, $row['received'], 0.001);
        $this->assertEqualsWithDelta(25.0, $row['deducted'], 0.001);
        $this->assertEqualsWithDelta(65.0, $row['net_payable'], 0.001);
        $this->assertSame('Refund issued to a customer on their behalf', $row['reasons']->first()->reason);
    }

    #[Test]
    public function a_deduction_must_carry_a_reason_and_a_positive_amount(): void
    {
        $laundry = $this->alpha['laundry'];
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.laundry_revenue.deduct', $laundry->id), ['amount' => 25, 'reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post(route('admin.laundry_revenue.deduct', $laundry->id), ['amount' => 0, 'reason' => 'Nothing at all'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, LaundryDeduction::withoutGlobalScopes()->count());
    }

    #[Test]
    public function withdrawing_reverses_the_rows_rather_than_deleting_them(): void
    {
        $laundry = $this->alpha['laundry'];
        $this->order($laundry, paid: 100.0, basis: 100.0, commission: 10.0);
        $admin = $this->superAdmin();

        foreach ([10, 15] as $amount) {
            $this->actingAs($admin)->post(route('admin.laundry_revenue.deduct', $laundry->id), [
                'amount' => $amount,
                'reason' => "Deducted {$amount} for a damaged piece",
            ]);
        }

        $this->assertEqualsWithDelta(65.0, $this->rowFor($laundry->id)['net_payable'], 0.001);

        $this->actingAs($admin)
            ->post(route('admin.laundry_revenue.reverse', $laundry->id))
            ->assertRedirect();

        $row = $this->rowFor($laundry->id);

        $this->assertEqualsWithDelta(0.0, $row['deducted'], 0.001);
        $this->assertEqualsWithDelta(90.0, $row['net_payable'], 0.001);

        // Kept, not removed. «Why was I charged 25 in March» has to stay
        // answerable after somebody gives it back.
        $this->assertSame(2, LaundryDeduction::withoutGlobalScopes()->count());
        $this->assertSame(0, LaundryDeduction::withoutGlobalScopes()->applied()->count());
        $this->assertNotNull(LaundryDeduction::withoutGlobalScopes()->first()->reversed_at);
    }

    #[Test]
    public function a_laundry_deducted_more_than_it_earned_shows_a_negative_row(): void
    {
        $laundry = $this->alpha['laundry'];
        $this->order($laundry, paid: 100.0, basis: 100.0, commission: 10.0);

        $this->actingAs($this->superAdmin())->post(route('admin.laundry_revenue.deduct', $laundry->id), [
            'amount' => 150,
            'reason' => 'Overpaid last month',
        ]);

        // Negative on the row, because it is a real state somebody has to act
        // on, and floored on the card, because a negative headline reads as a
        // system fault.
        $this->assertEqualsWithDelta(-60.0, $this->rowFor($laundry->id)['net_payable'], 0.001);

        $summary = $this->actingAs($this->superAdmin())
            ->get(route('admin.laundry_revenue.index'))
            ->viewData('summary');

        $this->assertEqualsWithDelta(0.0, $summary['laundries_receive'], 0.001);
    }

    // ----------------------------------------------------------- permissions

    #[Test]
    public function the_screen_needs_its_own_view_permission(): void
    {
        $nobody = $this->customer('+201055550009');

        // A customer is refused by `dashboard.only` before the permission is
        // even consulted; the laundry owner below is the case that matters.
        $this->actingAs($nobody)->get(route('admin.laundry_revenue.index'))->assertForbidden();

        $this->actingAs($this->alpha['owner'])
            ->get(route('admin.laundry_revenue.index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_laundry_owner_role_does_not_hold_the_permission(): void
    {
        // `seedCore()` mirrors RoleSeeder's grant for this role. If somebody
        // widens it, the screen that lists every laundry's earnings becomes
        // readable by one of them, and this is where that is caught.
        $slugs = Role::where('slug', 'laundry_owner')
            ->firstOrFail()->permissions()->pluck('slug')->all();

        $this->assertNotContains('laundry_revenue.view', $slugs);
    }

    #[Test]
    public function reading_the_screen_does_not_carry_the_right_to_deduct(): void
    {
        $viewer = $this->viewer();
        $laundry = $this->alpha['laundry'];

        // The boundary from MoneyBoundaryTest, applied to the other money
        // decision on this screen: what a laundry is paid is a `setting.update`
        // act, not something the read permission grants.
        $this->actingAs($viewer)->get(route('admin.laundry_revenue.index'))->assertOk();

        $this->actingAs($viewer)
            ->post(route('admin.laundry_revenue.deduct', $laundry->id), ['amount' => 5, 'reason' => 'Because'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('admin.laundry_revenue.reverse', $laundry->id))
            ->assertForbidden();

        $this->assertSame(0, LaundryDeduction::withoutGlobalScopes()->count());
    }

    // --------------------------------------------------------- search, export

    #[Test]
    public function the_search_finds_a_laundry_by_name_and_by_email(): void
    {
        $this->order($this->alpha['laundry'], paid: 100.0, basis: 100.0, commission: 10.0);
        $this->order($this->beta['laundry'], paid: 200.0, basis: 200.0, commission: 20.0);

        foreach (['laundry a', 'LAUNDRYA@TEST.LOCAL'] as $term) {
            $response = $this->actingAs($this->superAdmin())
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->getJson(route('admin.laundry_revenue.search', ['query' => $term]));

            $response->assertOk();

            // Case folded on both sides, which is what makes a `json` name
            // column searchable on MariaDB's binary collation.
            $this->assertStringContainsString('Laundry A', $response->json('table'), "term: {$term}");
            $this->assertStringNotContainsString('Laundry B', $response->json('table'), "term: {$term}");
        }
    }

    #[Test]
    public function a_bare_get_to_search_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.laundry_revenue.search', ['query' => 'a']))
            ->assertStatus(400);
    }

    #[Test]
    public function the_export_flattens_every_column(): void
    {
        $this->order($this->alpha['laundry'], paid: 114.0, basis: 100.0, commission: 10.0, tax: 14.0);

        $this->actingAs($this->superAdmin())->post(route('admin.laundry_revenue.deduct', $this->alpha['laundry']->id), [
            'amount' => 5,
            'reason' => 'A lost sock',
        ]);

        $response = $this->actingAs($this->superAdmin())->get(route('admin.laundry_revenue.export'));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'the BOM is what makes Excel read Arabic as Arabic');
        $this->assertStringContainsString('laundry_entitled', $csv);
        $this->assertStringContainsString('net_payable', $csv);
        $this->assertStringContainsString('Laundry A', $csv);
        $this->assertStringContainsString('A lost sock', $csv);
        // 90 earned, 5 taken back.
        $this->assertStringContainsString('85', $csv);
    }

    #[Test]
    public function the_sidebar_offers_the_screen_to_a_super_admin_and_not_to_a_laundry(): void
    {
        // A menu item leading to a 403 teaches an operator that the panel is
        // broken rather than that the screen is not theirs — the half
        // MoneyBoundaryTest says quietly regresses.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.laundry_revenue.index'))
            ->assertOk()
            ->assertSee(route('admin.laundry_revenue.index'), false);

        // Their own laundry screen, which `seedCore()` grants them — not the
        // settlements screen, whose permission only `RoleSeeder` hands out.
        $this->actingAs($this->alpha['owner'])
            ->get(route('admin.laundry.index'))
            ->assertOk()
            ->assertDontSee(route('admin.laundry_revenue.index'), false);
    }
}
