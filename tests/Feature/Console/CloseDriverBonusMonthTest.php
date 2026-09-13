<?php

namespace Tests\Feature\Console;

use App\Modules\Driver\Enums\BonusBasis;
use App\Modules\Driver\Models\DriverBonusAward;
use App\Modules\Driver\Models\DriverBonusRule;
use App\Modules\Driver\Models\DriverBonusTier;
use App\Modules\Driver\Models\DriverProfile;
use App\Modules\Driver\Services\MonthlyBonusService;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Services\WalletService;
use App\Services\MenuBadges;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «مكافآت الشهر» closing itself, and saying so.
 *
 * The screen already recomputes an open month on every visit, so this command is
 * not what makes the figures right — it is what makes them **noticed**. A bonus
 * nobody is reminded of is a bonus paid late.
 *
 * The two claims worth testing hardest are both about restraint: it **never
 * approves anything**, and it raises its alert **once per period**. An alert that
 * repeats every day teaches people to dismiss it, and then the one that mattered
 * is dismissed too.
 */
class CloseDriverBonusMonthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $catalog;

    private User $customer;

    private CarbonImmutable $lastMonth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('+201099887733');

        $this->lastMonth = CarbonImmutable::now()->subMonth()->startOfMonth();
    }

    private function ruleWithTier(): DriverBonusRule
    {
        $rule = DriverBonusRule::create([
            'name' => json_encode(['en' => 'Standard', 'ar' => 'القياسية'], JSON_UNESCAPED_UNICODE),
            'basis' => BonusBasis::PerOrder->value,
            'amount' => 20,
            'status' => 'active',
        ]);

        DriverBonusTier::create([
            'driver_bonus_rule_id' => $rule->id,
            'min_orders' => 1,
            'amount' => 500,
        ]);

        return $rule;
    }

    private function driverWhoDelivered(DriverBonusRule $rule, string $phone, int $count): User
    {
        $driver = $this->driverUser($phone, zoneIds: [$this->geo['zones'][0]->id]);
        DriverProfile::where('user_id', $driver->id)->update(['bonus_rule_id' => $rule->id]);

        $at = $this->lastMonth->addDays(5);

        foreach (range(1, max($count, 0)) as $ignored) {
            if ($count < 1) {
                break;
            }

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

            OrderTask::create([
                'order_id' => $order->id,
                'type' => TaskType::DeliverToCustomer->value,
                'sequence' => 4,
                'status' => TaskStatus::Completed->value,
                'driver_id' => $driver->id,
                'due_at' => $at,
                'completed_at' => $at->subMinutes(5),
            ]);
        }

        return $driver;
    }

    // ------------------------------------------------------------------ closing

    #[Test]
    public function it_closes_last_month_by_default(): void
    {
        $driver = $this->driverWhoDelivered($this->ruleWithTier(), '+201033330041', 2);

        // Run on the 1st, «this month» would be a month one day old and every
        // figure in it wrong. The month that is over is the one to close.
        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        $award = DriverBonusAward::where('driver_id', $driver->id)->firstOrFail();

        $this->assertSame($this->lastMonth->format('Y-m'), $award->period);
        $this->assertSame(2, $award->orders_count);
        $this->assertEquals(500.0, (float) $award->amount);
    }

    #[Test]
    public function it_never_approves_anything(): void
    {
        $driver = $this->driverWhoDelivered($this->ruleWithTier(), '+201033330042', 3);
        $this->superAdmin();

        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        // The whole restraint of this command. A payout that ran on a schedule
        // is a wrong payment made in the month nobody was looking.
        $this->assertSame(
            DriverBonusAward::DUE,
            DriverBonusAward::where('driver_id', $driver->id)->value('status')
        );
        $this->assertSame('0.00', app(WalletService::class)->forUser($driver)->balance);
    }

    #[Test]
    public function a_named_period_is_honoured_and_a_nonsense_one_is_refused(): void
    {
        $this->driverWhoDelivered($this->ruleWithTier(), '+201033330043', 1);

        $this->artisan('drivers:close-bonus-month', ['--period' => $this->lastMonth->format('Y-m')])
            ->assertSuccessful();

        foreach (['0000-99', 'not-a-month', '2026-13', '1066-01'] as $bad) {
            $this->artisan('drivers:close-bonus-month', ['--period' => $bad])->assertFailed();
        }
    }

    // ------------------------------------------------------------- the reminder

    #[Test]
    public function it_tells_operations_that_a_month_is_waiting(): void
    {
        $this->driverWhoDelivered($this->ruleWithTier(), '+201033330044', 2);
        $operator = $this->superAdmin();

        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        $log = NotificationLog::where('event', NotificationEvent::DriverBonusReady->value)
            ->where('user_id', $operator->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString($this->lastMonth->format('Y-m'), (string) $log->body);
    }

    #[Test]
    public function it_raises_the_alert_once_per_period_and_not_once_per_run(): void
    {
        $this->driverWhoDelivered($this->ruleWithTier(), '+201033330045', 2);
        $this->superAdmin();

        // Scheduled monthly, but a catch-up run or a retry must not send again.
        // An alert that repeats teaches people to dismiss it.
        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        // Counted as a delta rather than against a literal: one message fans
        // out to a log row per channel — `NotificationEvent::channels()`
        // returns database AND push — so «one send» is not «one row», and
        // asserting a number here would be asserting the channel list.
        $afterFirstRun = NotificationLog::where('event', NotificationEvent::DriverBonusReady->value)->count();
        $this->assertGreaterThan(0, $afterFirstRun);

        $this->artisan('drivers:close-bonus-month')->assertSuccessful();
        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        $this->assertSame(
            $afterFirstRun,
            NotificationLog::where('event', NotificationEvent::DriverBonusReady->value)->count()
        );
    }

    #[Test]
    public function a_month_where_nobody_earned_anything_raises_nothing(): void
    {
        // Delivered, but not enough to reach the target. There is nothing to
        // decide, and an alert about it is noise.
        $rule = DriverBonusRule::create([
            'name' => json_encode(['en' => 'Hard', 'ar' => 'صعبة'], JSON_UNESCAPED_UNICODE),
            'basis' => BonusBasis::PerOrder->value,
            'amount' => 20,
            'status' => 'active',
        ]);
        DriverBonusTier::create(['driver_bonus_rule_id' => $rule->id, 'min_orders' => 100, 'amount' => 500]);

        $this->driverWhoDelivered($rule, '+201033330046', 1);
        $this->superAdmin();

        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        $this->assertSame(1, DriverBonusAward::count());
        $this->assertSame(0, NotificationLog::where('event', NotificationEvent::DriverBonusReady->value)->count());
    }

    #[Test]
    public function a_platform_with_no_drivers_on_a_rule_does_nothing_at_all(): void
    {
        $this->driverUser('+201033330047', zoneIds: [$this->geo['zones'][0]->id]);
        $this->superAdmin();

        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        $this->assertSame(0, DriverBonusAward::count());
        $this->assertSame(0, NotificationLog::where('event', NotificationEvent::DriverBonusReady->value)->count());
    }

    #[Test]
    public function quiet_notify_computes_without_telling_anybody(): void
    {
        $this->driverWhoDelivered($this->ruleWithTier(), '+201033330048', 2);
        $this->superAdmin();

        $this->artisan('drivers:close-bonus-month', ['--quiet-notify' => true])->assertSuccessful();

        $this->assertSame(1, DriverBonusAward::count());
        $this->assertSame(0, NotificationLog::where('event', NotificationEvent::DriverBonusReady->value)->count());
    }

    // ----------------------------------------------------------------- the badge

    #[Test]
    public function the_sidebar_counts_what_is_waiting_and_nothing_else(): void
    {
        $rule = $this->ruleWithTier();

        $this->driverWhoDelivered($rule, '+201033330051', 2);   // reaches the target
        $this->driverWhoDelivered($rule, '+201033330052', 0);   // reaches nothing

        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        // Two awards exist; one of them is work. A badge that counted rows
        // would say 2 and send somebody to a screen with one thing on it.
        $this->assertSame(2, DriverBonusAward::count());
        $this->assertSame(1, MenuBadges::for('driver_bonus_award'));
    }

    #[Test]
    public function an_empty_queue_draws_no_badge(): void
    {
        // Zero is not a badge — an empty queue should look like every other
        // finished thing on the list, not like a queue reporting itself empty.
        $this->assertNull(MenuBadges::for('driver_bonus_award'));
    }

    #[Test]
    public function an_approved_month_stops_being_counted(): void
    {
        $this->driverWhoDelivered($this->ruleWithTier(), '+201033330053', 2);
        $this->artisan('drivers:close-bonus-month')->assertSuccessful();

        $this->assertSame(1, MenuBadges::for('driver_bonus_award'));

        app(MonthlyBonusService::class)
            ->approve(DriverBonusAward::firstOrFail(), $this->superAdmin());

        $this->assertNull(MenuBadges::for('driver_bonus_award'));
    }
}
