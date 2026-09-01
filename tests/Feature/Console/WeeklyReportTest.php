<?php

namespace Tests\Feature\Console;

use App\Mail\WeeklyReportMail;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Report\Services\WeeklyDigest;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «ملخص أسبوعي للسوبر أدمن + لكل مغسلة تقريرها».
 *
 * The reports have always been on the dashboard; what was missing is that
 * somebody has to remember to open them. Two properties carry the whole feature
 * and both are tested hard: a laundry must receive **its own** figures and nobody
 * else's, and a single bad address must not cost everybody else their report.
 */
class WeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $a;

    /** @var array<string, mixed> */
    private array $b;

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
        $this->a = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->b = $this->laundryWithOwner('B', '01011120001', '01011120002');
        $this->customer = $this->customer('01055550001');

        Mail::fake();
    }

    /**
     * An order placed, assigned and paid for inside last week.
     */
    private function paidOrder(int $laundryId, float $total): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $when = now()->subDays(3);

        $order->forceFill([
            'laundry_id' => $laundryId,
            'status' => OrderStatus::Completed->value,
            'final_total' => $total,
            'paid_at' => $when,
            'created_at' => $when,
        ])->save();

        return $order->fresh();
    }

    // ------------------------------------------------------------- the window

    #[Test]
    public function the_week_ends_yesterday(): void
    {
        $range = WeeklyDigest::lastWeek();

        // A half-finished day inside a weekly total is a number that changes
        // after it has been read.
        $this->assertSame(now()->subDay()->toDateString(), $range->to->toDateString());
        $this->assertSame(7, $range->days());
    }

    #[Test]
    public function what_happened_today_is_not_in_it(): void
    {
        $this->paidOrder($this->a['laundry']->id, 100);

        $today = $this->paidOrder($this->a['laundry']->id, 500);
        $today->forceFill(['paid_at' => now(), 'created_at' => now()])->save();

        $digest = app(WeeklyDigest::class)->platform(WeeklyDigest::lastWeek());

        $this->assertStringContainsString('100', (string) $digest['headline'][__('Net revenue')]);
        $this->assertStringNotContainsString('500', (string) $digest['headline'][__('Net revenue')]);
    }

    // ------------------------------------------------------------- the scoping

    #[Test]
    public function a_laundry_sees_its_own_figures_and_nobody_elses(): void
    {
        $this->paidOrder($this->a['laundry']->id, 100);
        $this->paidOrder($this->a['laundry']->id, 200);
        $this->paidOrder($this->b['laundry']->id, 900);

        $digests = app(WeeklyDigest::class);
        $range = WeeklyDigest::lastWeek();

        $forA = $digests->forLaundry($this->a['laundry'], $this->a['owner'], $range);
        $forB = $digests->forLaundry($this->b['laundry'], $this->b['owner'], $range);

        // Scoped by running the same report services as that laundry's owner —
        // the tenant scope on Order does the filtering, so the emailed number
        // cannot drift away from the one on the screen.
        $this->assertSame(2, $forA['headline'][__('Orders')]);
        $this->assertSame(1, $forB['headline'][__('Orders')]);
        $this->assertStringContainsString('300', (string) $forA['headline'][__('Revenue')]);
        $this->assertStringContainsString('900', (string) $forB['headline'][__('Revenue')]);
    }

    #[Test]
    public function the_platform_digest_sees_all_of_it(): void
    {
        $this->paidOrder($this->a['laundry']->id, 100);
        $this->paidOrder($this->b['laundry']->id, 900);

        $digest = app(WeeklyDigest::class)->platform(WeeklyDigest::lastWeek());

        $this->assertSame(2, $digest['headline'][__('Orders')]);
        $this->assertCount(2, $digest['rows']);
    }

    #[Test]
    public function borrowing_the_owners_identity_puts_the_guard_back(): void
    {
        $this->paidOrder($this->a['laundry']->id, 100);

        // The console has no authenticated user, which is exactly why every
        // tenant scope lets it see everything. Leaving the owner logged in after
        // one report would silently scope every command that runs after it.
        $this->assertFalse(Auth::hasUser());

        app(WeeklyDigest::class)->forLaundry($this->a['laundry'], $this->a['owner'], WeeklyDigest::lastWeek());

        $this->assertFalse(Auth::hasUser());
    }

    // ------------------------------------------------------------- the sending

    #[Test]
    public function the_super_admin_and_every_laundry_owner_are_written_to(): void
    {
        $this->paidOrder($this->a['laundry']->id, 100);
        $admin = $this->superAdmin();

        $this->artisan('laundo:weekly-reports')->assertSuccessful();

        Mail::assertSent(WeeklyReportMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertSent(WeeklyReportMail::class, fn ($mail) => $mail->hasTo($this->a['owner']->email));
        Mail::assertSent(WeeklyReportMail::class, fn ($mail) => $mail->hasTo($this->b['owner']->email));
    }

    #[Test]
    public function a_dry_run_sends_nothing(): void
    {
        $this->superAdmin();

        $this->artisan('laundo:weekly-reports --dry-run')->assertSuccessful();

        // The first production run of a scheduled command should be readable
        // before it is trusted.
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function a_laundry_with_no_reachable_owner_is_skipped_not_failed(): void
    {
        $this->superAdmin();
        $this->b['owner']->forceFill(['email' => null])->save();

        $this->artisan('laundo:weekly-reports')->assertSuccessful();

        // A laundry run from a phone with no email address is a normal state,
        // and the report is on the dashboard either way.
        Mail::assertSent(WeeklyReportMail::class, fn ($mail) => $mail->hasTo($this->a['owner']->email));
        Mail::assertSent(WeeklyReportMail::class, 2);
    }

    #[Test]
    public function a_suspended_owner_is_not_written_to(): void
    {
        $this->superAdmin();
        $this->b['owner']->forceFill(['status' => 'inactive'])->save();

        $this->artisan('laundo:weekly-reports')->assertSuccessful();

        Mail::assertNotSent(WeeklyReportMail::class, fn ($mail) => $mail->hasTo($this->b['owner']->email));
    }

    #[Test]
    public function only_platform_leaves_the_laundries_alone(): void
    {
        $admin = $this->superAdmin();

        $this->artisan('laundo:weekly-reports --only=platform')->assertSuccessful();

        Mail::assertSent(WeeklyReportMail::class, 1);
        Mail::assertSent(WeeklyReportMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    // ------------------------------------------------------------- the content

    #[Test]
    public function the_csv_carries_the_same_numbers_as_the_body(): void
    {
        $this->paidOrder($this->a['laundry']->id, 100);

        $digests = app(WeeklyDigest::class);
        $digest = $digests->platform(WeeklyDigest::lastWeek());
        $csv = $digests->csv($digest);

        $flat = implode('|', array_map(fn ($row) => implode(',', $row), $csv));

        foreach ($digest['headline'] as $label => $value) {
            $this->assertStringContainsString((string) $label, $flat);
            $this->assertStringContainsString((string) $value, $flat);
        }
    }

    #[Test]
    public function a_quiet_week_shows_no_queue_at_all(): void
    {
        $digest = app(WeeklyDigest::class)->platform(WeeklyDigest::lastWeek());

        // A column of noughts teaches people to stop reading the list, and then
        // the one that is not a nought is missed too.
        $this->assertSame([], $digest['waiting']);
    }
}
