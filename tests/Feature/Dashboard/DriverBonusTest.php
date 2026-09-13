<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Driver\Enums\BonusBasis;
use App\Modules\Driver\Models\DriverBonusAward;
use App\Modules\Driver\Models\DriverBonusRule;
use App\Modules\Driver\Models\DriverBonusTier;
use App\Modules\Driver\Models\DriverProfile;
use App\Modules\Driver\Services\MonthlyBonusService;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * «مكافأة آخر الشهر» — the monthly half of a driver's bonus.
 *
 * The salary is not in this system at all, by the owner's decision, so nothing
 * here records or pays one. What is tested is the bonus, and two claims matter
 * more than the rest:
 *
 * **A bonus paid on volume alone pays a driver to rush.** So the tiers count
 * orders and three gates then decide whether the driver keeps what the count
 * earned — measured from `order_tasks.due_at`, `order_ratings.delivery` and the
 * failed-task count, all of which the application has always written and nothing
 * has ever read.
 *
 * **Nothing pays itself.** A month is computed to `due` and moves no money;
 * approving is a separate act by a person, and it cannot happen twice.
 */
class DriverBonusTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    private User $customer;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('+201099887711');

        $this->month = CarbonImmutable::now()->startOfMonth();
    }

    // ------------------------------------------------------------------ builders

    private function rule(array $attributes = []): DriverBonusRule
    {
        return DriverBonusRule::create($attributes + [
            'name' => json_encode(['en' => 'Standard', 'ar' => 'القياسية'], JSON_UNESCAPED_UNICODE),
            'basis' => BonusBasis::PerOrder->value,
            'amount' => 20,
            'status' => 'active',
        ]);
    }

    private function driverOn(?DriverBonusRule $rule, string $phone = '+201033330011'): User
    {
        $driver = $this->driverUser($phone, zoneIds: [$this->geo['zones'][0]->id]);

        DriverProfile::where('user_id', $driver->id)->update(['bonus_rule_id' => $rule?->id]);

        return $driver;
    }

    /**
     * A completed delivery leg for this driver, in this month.
     *
     * Built directly rather than by walking an order through four legs: what the
     * monthly calculator reads is `order_tasks`, and driving the whole state
     * machine per order would make a 120-order month a very slow test.
     */
    private function delivered(
        User $driver,
        bool $onTime = true,
        ?int $rating = null,
        ?CarbonImmutable $at = null,
    ): OrderTask {
        $at ??= $this->month->addDays(3);

        $order = Order::create([
            'code' => Order::generateCode(),
            'user_id' => $this->customer->id,
            'service_id' => $this->catalog['service']->id,
            'status' => 'completed',
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'delivery_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'delivery_fee' => 20,
            'estimated_total' => 100,
            'qr_token' => Order::generateQrToken(),
        ]);

        $task = OrderTask::create([
            'order_id' => $order->id,
            'type' => TaskType::DeliverToCustomer->value,
            'sequence' => 4,
            'status' => TaskStatus::Completed->value,
            'driver_id' => $driver->id,
            'due_at' => $at,
            'completed_at' => $onTime ? $at->subMinutes(10) : $at->addMinutes(30),
        ]);

        if ($rating !== null) {
            OrderRating::withoutGlobalScopes()->create([
                'order_id' => $order->id,
                'user_id' => $this->customer->id,
                'overall' => $rating,
                'delivery' => $rating,
            ]);
        }

        return $task;
    }

    private function failedLeg(User $driver): void
    {
        $order = Order::create([
            'code' => Order::generateCode(),
            'user_id' => $this->customer->id,
            'service_id' => $this->catalog['service']->id,
            'status' => 'awaiting_pickup',
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'delivery_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'delivery_fee' => 20,
            'estimated_total' => 100,
            'qr_token' => Order::generateQrToken(),
        ]);

        OrderTask::create([
            'order_id' => $order->id,
            'type' => TaskType::PickupFromCustomer->value,
            'sequence' => 1,
            'status' => TaskStatus::Failed->value,
            'driver_id' => $driver->id,
        ]);
    }

    private function compute(User $driver): ?DriverBonusAward
    {
        $profile = DriverProfile::where('user_id', $driver->id)->firstOrFail();

        return app(MonthlyBonusService::class)->computeFor($profile->fresh(), $this->month);
    }

    // ------------------------------------------------------------------ measuring

    #[Test]
    public function it_counts_orders_delivered_not_journeys_driven(): void
    {
        $driver = $this->driverOn($this->rule());

        $this->delivered($driver);
        $this->delivered($driver);

        // «وصّل ١٠٠ طلب» means orders, not the four legs each of them makes. A
        // target counted in legs is a target four times easier than it reads.
        $award = $this->compute($driver);

        $this->assertSame(2, $award->orders_count);
    }

    #[Test]
    public function the_on_time_rate_comes_from_the_due_time_on_the_leg(): void
    {
        $driver = $this->driverOn($this->rule());

        $this->delivered($driver, onTime: true);
        $this->delivered($driver, onTime: true);
        $this->delivered($driver, onTime: true);
        $this->delivered($driver, onTime: false);

        $award = $this->compute($driver);

        $this->assertEquals(75.0, (float) $award->on_time_rate);
    }

    #[Test]
    public function a_driver_with_no_scheduled_legs_has_no_on_time_record(): void
    {
        $driver = $this->driverOn($this->rule());

        // Null, not 100. Treating «never scheduled» as perfect would let a gate
        // be passed by doing nothing at all.
        $award = $this->compute($driver);

        $this->assertNull($award->on_time_rate);
    }

    #[Test]
    public function the_rating_is_the_delivery_score_not_the_overall_one(): void
    {
        $driver = $this->driverOn($this->rule());

        $task = $this->delivered($driver, rating: 5);

        // A driver must not lose their bonus because a laundry ironed a shirt
        // badly, so the overall score is deliberately not what is read.
        OrderRating::withoutGlobalScopes()
            ->where('order_id', $task->order_id)
            ->update(['overall' => 1, 'delivery' => 5]);

        $award = $this->compute($driver);

        $this->assertEquals(5.0, (float) $award->avg_delivery_rating);
    }

    #[Test]
    public function failed_journeys_are_counted(): void
    {
        $driver = $this->driverOn($this->rule());

        $this->delivered($driver);
        $this->failedLeg($driver);
        $this->failedLeg($driver);

        $this->assertSame(2, $this->compute($driver)->failed_tasks);
    }

    #[Test]
    public function another_drivers_work_is_not_counted(): void
    {
        $mine = $this->driverOn($this->rule(), '+201033330012');
        $theirs = $this->driverOn($this->rule(), '+201033330013');

        $this->delivered($mine);
        $this->delivered($theirs);
        $this->delivered($theirs);

        $this->assertSame(1, $this->compute($mine)->orders_count);
        $this->assertSame(2, $this->compute($theirs)->orders_count);
    }

    #[Test]
    public function last_months_work_does_not_count_towards_this_month(): void
    {
        $driver = $this->driverOn($this->rule());

        $this->delivered($driver);
        $this->delivered($driver, at: $this->month->subMonth()->addDays(3));

        $this->assertSame(1, $this->compute($driver)->orders_count);
    }

    // --------------------------------------------------------------------- tiers

    #[Test]
    public function the_highest_target_reached_is_paid_never_the_sum(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 2, 'amount' => 100]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 4, 'amount' => 250]);

        $driver = $this->driverOn($rule);

        foreach (range(1, 5) as $ignored) {
            $this->delivered($driver);
        }

        $award = $this->compute($driver);

        // 5 orders clears both targets. «١٥٠ طلب → ٩٠٠ ج» is the price of the
        // level, not an increment on the one below — so 250, not 350.
        $this->assertEquals(250.0, (float) $award->amount);
        $this->assertSame(4, $award->tier_min_orders);
    }

    #[Test]
    public function missing_every_target_pays_nothing(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 10, 'amount' => 100]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);

        $award = $this->compute($driver);

        $this->assertEquals(0.0, (float) $award->amount);
        $this->assertNull($award->tier_min_orders);
        // Distinct from being gated: this driver simply did not get there.
        $this->assertFalse($award->wasGated());
    }

    // --------------------------------------------------------------------- gates

    #[Test]
    public function a_missed_on_time_rate_blocks_the_bonus(): void
    {
        $rule = $this->rule(['min_on_time_rate' => 90]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 2, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver, onTime: true);
        $this->delivered($driver, onTime: false);

        $award = $this->compute($driver);

        // The target was reached and the quality was not. That is the whole
        // point of the gate: «وصّل كتير وكويس», not «وصّل كتير».
        $this->assertSame(2, $award->orders_count);
        $this->assertEquals(0.0, (float) $award->amount);
        $this->assertContains('on_time', $award->gate_failures);
    }

    #[Test]
    public function a_low_delivery_rating_blocks_the_bonus(): void
    {
        $rule = $this->rule(['min_delivery_rating' => 4.5]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver, rating: 3);

        $award = $this->compute($driver);

        $this->assertEquals(0.0, (float) $award->amount);
        $this->assertContains('rating', $award->gate_failures);
    }

    #[Test]
    public function too_many_failed_journeys_block_the_bonus(): void
    {
        $rule = $this->rule(['max_failed_tasks' => 1]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);
        $this->failedLeg($driver);
        $this->failedLeg($driver);

        $this->assertContains('failed_tasks', $this->compute($driver)->gate_failures);
    }

    #[Test]
    public function a_missing_measurement_never_fails_a_gate(): void
    {
        $rule = $this->rule(['min_delivery_rating' => 4.5, 'min_on_time_rate' => 90]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);

        // Delivered, on time, and nobody rated it. A driver with no ratings has
        // not fallen below a threshold — they have no rating — and refusing the
        // bonus for an absence of evidence is how a new driver is told the
        // scheme is rigged.
        $this->delivered($driver, onTime: true, rating: null);

        $award = $this->compute($driver);

        $this->assertNull($award->avg_delivery_rating);
        $this->assertFalse($award->wasGated());
        $this->assertEquals(500.0, (float) $award->amount);
    }

    #[Test]
    public function a_gate_of_zero_is_a_real_rule_and_not_the_same_as_empty(): void
    {
        $rule = $this->rule(['max_failed_tasks' => 0]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);
        $this->failedLeg($driver);

        $this->assertContains('failed_tasks', $this->compute($driver)->gate_failures);
    }

    // ------------------------------------------------------------------ the money

    #[Test]
    public function computing_a_month_moves_no_money(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);

        $award = $this->compute($driver);

        $this->assertSame(DriverBonusAward::DUE, $award->status);
        $this->assertSame('0.00', app(WalletService::class)->forUser($driver)->balance);
    }

    #[Test]
    public function approving_credits_the_wallet_once(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);
        $award = $this->compute($driver);

        $operator = $this->superAdmin();
        app(MonthlyBonusService::class)->approve($award, $operator);

        $wallet = app(WalletService::class)->forUser($driver)->fresh();
        $this->assertEquals(500.0, (float) $wallet->balance);
        $this->assertTrue($wallet->isReconciled());

        $transaction = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('reason', TransactionReason::Bonus->value)->first();

        $this->assertNotNull($transaction);
        $this->assertStringContainsString($award->period, (string) $transaction->note);
        $this->assertSame(DriverBonusAward::class, $transaction->source_type);
    }

    #[Test]
    public function a_month_cannot_be_approved_twice(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);
        $award = $this->compute($driver);

        app(MonthlyBonusService::class)->approve($award->fresh(), $this->superAdmin());

        // The double click and the replayed POST, either of which would credit a
        // driver twice for one month.
        $this->expectException(RuntimeException::class);
        app(MonthlyBonusService::class)->approve($award->fresh(), $this->superAdmin());
    }

    #[Test]
    public function an_approved_month_is_never_recomputed(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);
        app(MonthlyBonusService::class)->approve($this->compute($driver), $this->superAdmin());

        // More work arrives, and the terms change. Money has moved against the
        // four numbers already on the row.
        $this->delivered($driver);
        DriverBonusTier::where('driver_bonus_rule_id', $rule->id)->update(['amount' => 9999]);

        $again = $this->compute($driver);

        $this->assertEquals(500.0, (float) $again->amount);
        $this->assertSame(1, $again->orders_count);
        $this->assertSame(DriverBonusAward::APPROVED, $again->status);
    }

    #[Test]
    public function an_open_month_is_recomputed_as_the_work_arrives(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 3, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);

        $this->assertEquals(0.0, (float) $this->compute($driver)->amount);

        $this->delivered($driver);
        $this->delivered($driver);

        // A figure that was right on the 3rd is not a figure to approve on the
        // 30th.
        $award = $this->compute($driver);
        $this->assertSame(3, $award->orders_count);
        $this->assertEquals(500.0, (float) $award->amount);

        // Recomputed, not duplicated.
        $this->assertSame(1, DriverBonusAward::where('driver_id', $driver->id)->count());
    }

    #[Test]
    public function a_zero_month_cannot_be_approved(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 10, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);

        $this->expectException(RuntimeException::class);
        app(MonthlyBonusService::class)->approve($this->compute($driver), $this->superAdmin());
    }

    #[Test]
    public function declining_records_a_reason_rather_than_deleting_the_row(): void
    {
        $rule = $this->rule();
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);

        $award = app(MonthlyBonusService::class)
            ->reject($this->compute($driver), $this->superAdmin(), 'Under review');

        // «ليه مخدتش المكافأة» is a question that has to have an answer, and a
        // row that was deleted cannot answer it.
        $this->assertSame(DriverBonusAward::REJECTED, $award->status);
        $this->assertSame('Under review', $award->note);
        $this->assertSame('0.00', app(WalletService::class)->forUser($driver)->balance);
    }

    // ----------------------------------------------------------------- eligibility

    #[Test]
    public function a_driver_on_no_rule_is_not_on_the_screen_at_all(): void
    {
        $driver = $this->driverOn(null);
        $this->delivered($driver);

        $this->assertNull($this->compute($driver));
        $this->assertSame(0, DriverBonusAward::count());
    }

    #[Test]
    public function an_inactive_rule_produces_no_award(): void
    {
        $rule = $this->rule(['status' => 'inactive']);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $driver = $this->driverOn($rule);
        $this->delivered($driver);

        $this->assertNull($this->compute($driver));
    }

    // --------------------------------------------------------------------- screens

    #[Test]
    public function the_month_screen_shows_what_is_waiting_and_why_it_was_blocked(): void
    {
        $rule = $this->rule(['min_on_time_rate' => 90]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 1, 'amount' => 500]);

        $blocked = $this->driverOn($rule, '+201033330021');
        $this->delivered($blocked, onTime: false);

        $paid = $this->driverOn($rule, '+201033330022');
        $this->delivered($paid, onTime: true);

        $this->grant('super_admin', ['driver_bonus_award.view', 'driver_bonus_award.update']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.driver_bonus.index'))
            ->assertOk()
            ->assertSee(__('Missed the on-time rate'), false)
            ->assertSee(moneyFormat(500), false);
    }

    #[Test]
    public function an_absurd_period_in_the_url_falls_back_to_this_month(): void
    {
        $this->grant('super_admin', ['driver_bonus_award.view']);

        // A period comes off a query string, and a URL is untrusted input.
        foreach (['0000-99', 'not-a-month', '1066-01', '2026-13'] as $bad) {
            $this->actingAs($this->superAdmin())
                ->get(route('admin.driver_bonus.index', ['period' => $bad]))
                ->assertOk()
                ->assertSee(CarbonImmutable::now()->format('Y-m'), false);
        }
    }

    #[Test]
    public function the_rule_screen_lists_the_terms_and_the_driver_count(): void
    {
        $rule = $this->rule();
        $this->driverOn($rule);

        $this->grant('super_admin', ['driver_bonus_rule.view']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.driver_bonus_rule.index'))
            ->assertOk()
            ->assertSee('Standard', false)
            ->assertSee(moneyFormat(20), false);
    }

    #[Test]
    public function a_rule_saves_its_tiers_and_drops_the_amount_it_does_not_use(): void
    {
        $this->grant('super_admin', ['driver_bonus_rule.create', 'driver_bonus_rule.view']);

        $this->actingAs($this->superAdmin())->post(route('admin.driver_bonus_rule.store'), [
            'name' => ['en' => 'Distance based', 'ar' => ''],
            'basis' => BonusBasis::PercentDeliveryFee->value,
            // Both boxes posted. The basis decides which survives — a rule
            // carrying two answers is a rule with none.
            'amount' => 50,
            'rate' => 12,
            'status' => 'active',
            'tier_min_orders' => [10, 20, ''],
            'tier_amounts' => [100, 250, ''],
        ])->assertRedirect();

        $rule = DriverBonusRule::latest('id')->firstOrFail();

        $this->assertNull($rule->amount);
        $this->assertEquals(12.0, (float) $rule->rate);
        // The blank third row is a row somebody started, not a tier of zero.
        $this->assertSame(2, $rule->tiers()->count());
    }

    #[Test]
    public function a_driver_cannot_be_put_on_a_rule_without_the_money_permission(): void
    {
        $rule = $this->rule();
        $driver = $this->driverOn(null, '+201033330031');

        // An operator holds `driver.update` to keep licences and shifts current.
        // What a driver is paid is a money term.
        $this->grant('admin', ['driver.view', 'driver.update']);

        $moderator = User::create([
            'name' => 'Ops', 'email' => 'ops@test.local', 'phone' => '+201033330099',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        $this->actingAs($moderator)
            ->post(route('admin.driver.bonus', $driver->id), ['bonus_rule_id' => $rule->id])
            ->assertForbidden();

        $this->assertNull(DriverProfile::where('user_id', $driver->id)->value('bonus_rule_id'));
    }

    #[Test]
    public function an_operator_with_the_money_permission_assigns_and_clears_it(): void
    {
        $rule = $this->rule();
        $driver = $this->driverOn(null, '+201033330032');

        $this->grant('super_admin', ['driver.view', 'setting.update']);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.driver.bonus', $driver->id), ['bonus_rule_id' => $rule->id])
            ->assertRedirect();

        $this->assertSame($rule->id, DriverProfile::where('user_id', $driver->id)->value('bonus_rule_id'));

        // An empty selection means no bonus, not «the standard rule».
        $this->actingAs($this->superAdmin())
            ->post(route('admin.driver.bonus', $driver->id), ['bonus_rule_id' => ''])
            ->assertRedirect();

        $this->assertNull(DriverProfile::where('user_id', $driver->id)->value('bonus_rule_id'));
    }
}
