<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\Payment;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money in and money out.
 *
 * Both were only ever reachable one order at a time: payments could be seen inside
 * an order and nowhere else, so a day's takings could not be reconciled against the
 * gateway without querying the database; driver earnings existed solely in the
 * driver's own app, so operations could not answer "what is this driver owed".
 *
 * The figures these screens lead with are chosen for what nobody could get before —
 * not what succeeded, but what is **stuck**; not what has been paid, but what is
 * **owed**. Those two are what most of these tests pin down.
 */
class PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $tenant;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    private ?User $defaultDriver = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->customer = $this->customer('01055550001');
    }

    private function order(): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $order->forceFill(['laundry_id' => $this->tenant['laundry']->id])->save();

        return $order->fresh();
    }

    private function payment(PaymentStatus $status, float $amount = 100, ?int $ageMinutes = null): Payment
    {
        $payment = Payment::create([
            'order_id' => $this->order()->id,
            'user_id' => $this->customer->id,
            'method' => 'card',
            'provider' => 'fake',
            'provider_reference' => 'REF-'.uniqid(),
            'amount' => $amount,
            'status' => $status->value,
        ]);

        if ($ageMinutes !== null) {
            $payment->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();
        }

        return $payment->fresh();
    }

    /**
     * A default driver, created once.
     *
     * `driverUser()` inserts, and `users.phone` is unique — calling it per earning
     * fails the second time. The helper is about the earning, not about making
     * people.
     */
    private function defaultDriver(): User
    {
        return $this->defaultDriver ??= $this->driverUser('01066660001');
    }

    private function earning(string $status, float $amount, ?User $driver = null): DriverEarning
    {
        $driver ??= $this->defaultDriver();
        $order = $this->order();
        $task = $order->tasks()->orderBy('sequence')->firstOrFail();

        return DriverEarning::create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'order_task_id' => $task->id,
            'amount' => $amount,
            'basis' => $amount * 4,
            'rate' => 0.25,
            'status' => $status,
            'released_at' => $status === DriverEarning::RELEASED ? now() : null,
        ]);
    }

    // ------------------------------------------------------------- reaching them

    #[Test]
    public function only_the_super_admin_reaches_either_screen(): void
    {
        // A payment is the platform collecting money and an earning is the platform
        // owing it. A laundry is party to neither, and neither model is tenant
        // scoped — so the permission is the whole protection.
        $this->actingAs($this->superAdmin())->get('/admin/payment')->assertOk();
        $this->actingAs($this->superAdmin())->get('/admin/earning')->assertOk();

        $this->actingAs($this->tenant['owner'])->get('/admin/payment')->assertForbidden();
        $this->actingAs($this->tenant['owner'])->get('/admin/earning')->assertForbidden();

        $this->actingAs($this->customer)->get('/admin/payment')->assertForbidden();
    }

    // ------------------------------------------------------------------ payments

    #[Test]
    public function the_summary_leads_with_what_is_stuck_not_what_worked(): void
    {
        $this->payment(PaymentStatus::Captured, 100);
        $this->payment(PaymentStatus::Authorised, 50);
        // Pending for two hours: an abandoned redirect, or a webhook that never came.
        $this->payment(PaymentStatus::Pending, 30, 120);
        // Pending for a minute is a payment in flight, not a problem.
        $this->payment(PaymentStatus::Pending, 20, 1);
        $this->payment(PaymentStatus::Failed, 10);

        $summary = $this->actingAs($this->superAdmin())
            ->get('/admin/payment')->assertOk()->viewData('summary');

        $this->assertEquals(100.0, $summary['captured_today']);
        // The most expensive state to leave unnoticed — the customer believes they
        // paid, we have not taken it, and the hold expires.
        $this->assertSame(1, $summary['authorised_uncaptured']);
        $this->assertSame(1, $summary['stuck']);
        $this->assertSame(1, $summary['failed_today']);
    }

    #[Test]
    public function a_stuck_payment_is_listed_before_a_successful_one(): void
    {
        $captured = $this->payment(PaymentStatus::Captured, 100);
        $pending = $this->payment(PaymentStatus::Pending, 30, 120);

        $payments = $this->actingAs($this->superAdmin())
            ->get('/admin/payment')->assertOk()->viewData('payments');

        // Newest-first would bury the one that needs acting on.
        $this->assertSame($pending->id, $payments->first()->id);
        $this->assertSame($captured->id, $payments->last()->id);
    }

    #[Test]
    public function the_status_filter_narrows_the_list(): void
    {
        $this->payment(PaymentStatus::Captured);
        $this->payment(PaymentStatus::Failed);

        $failed = $this->actingAs($this->superAdmin())
            ->get('/admin/payment?status=failed')->assertOk()->viewData('payments');

        $this->assertCount(1, $failed);
        $this->assertSame(PaymentStatus::Failed, $failed->first()->status);
    }

    #[Test]
    public function search_finds_a_payment_by_its_provider_reference(): void
    {
        $payment = $this->payment(PaymentStatus::Captured);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/admin/payment/search?status=all&query='.$payment->provider_reference, [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->assertOk();

        $this->assertStringContainsString($payment->provider_reference, $response->json('table'));
    }

    #[Test]
    public function todays_takings_ignore_yesterday(): void
    {
        // Mid-month, deliberately. "Two days ago" on the 1st is last month, so
        // without this the month figure is right 28 days out of 30 and wrong on
        // the two that would have made somebody doubt the screen.
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));

        $this->payment(PaymentStatus::Captured, 100);
        $old = $this->payment(PaymentStatus::Captured, 500);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        $summary = $this->actingAs($this->superAdmin())
            ->get('/admin/payment')->assertOk()->viewData('summary');

        $this->assertEquals(100.0, $summary['captured_today']);
        // But the month still has both, since both fell inside it.
        $this->assertEquals(600.0, $summary['captured_month']);
    }

    // ------------------------------------------------------------------ earnings

    #[Test]
    public function the_summary_leads_with_what_is_owed_not_what_was_paid(): void
    {
        $this->earning(DriverEarning::PENDING, 12);
        $this->earning(DriverEarning::PENDING, 8);
        $this->earning(DriverEarning::RELEASED, 20);

        $summary = $this->actingAs($this->superAdmin())
            ->get('/admin/earning')->assertOk()->viewData('summary');

        // «الرصيد المعلق» — earned on a completed leg, held because the order can
        // still be returned.
        $this->assertEquals(20.0, $summary['pending']);
        $this->assertEquals(20.0, $summary['released']);
        $this->assertSame(1, $summary['drivers_owed']);
    }

    #[Test]
    public function what_each_driver_is_owed_is_grouped_and_ordered(): void
    {
        $small = $this->defaultDriver();
        $large = $this->driverUser('01066660002');

        $this->earning(DriverEarning::PENDING, 5, $small);
        $this->earning(DriverEarning::PENDING, 40, $large);
        $this->earning(DriverEarning::PENDING, 10, $large);
        // Released money is not owed.
        $this->earning(DriverEarning::RELEASED, 999, $small);

        $byDriver = $this->actingAs($this->superAdmin())
            ->get('/admin/earning')->assertOk()->viewData('byDriver');

        $this->assertCount(2, $byDriver);

        // Most owed first — the question is who to pay, not who exists.
        $this->assertEquals(50.0, $byDriver[0]['owed']);
        $this->assertSame(2, $byDriver[0]['legs']);
        $this->assertEquals(5.0, $byDriver[1]['owed']);
    }

    #[Test]
    public function a_driver_with_nothing_held_is_not_listed_as_owed(): void
    {
        $driver = $this->defaultDriver();
        $this->earning(DriverEarning::RELEASED, 30, $driver);

        $response = $this->actingAs($this->superAdmin())->get('/admin/earning')->assertOk();

        $this->assertSame([], $response->viewData('byDriver'));
        $this->assertSame(0, $response->viewData('summary')['drivers_owed']);
    }

    #[Test]
    public function the_list_defaults_to_what_is_still_held(): void
    {
        $this->earning(DriverEarning::PENDING, 12);
        $this->earning(DriverEarning::RELEASED, 20);

        // Held is what needs a decision; released is history.
        $earnings = $this->actingAs($this->superAdmin())
            ->get('/admin/earning')->assertOk()->viewData('earnings');

        $this->assertCount(1, $earnings);
        $this->assertSame(DriverEarning::PENDING, $earnings->first()->status);
    }

    #[Test]
    public function released_this_month_ignores_an_older_release(): void
    {
        $recent = $this->earning(DriverEarning::RELEASED, 20);
        $old = $this->earning(DriverEarning::RELEASED, 500);
        $old->forceFill(['released_at' => now()->subMonths(2)])->save();

        $summary = $this->actingAs($this->superAdmin())
            ->get('/admin/earning')->assertOk()->viewData('summary');

        $this->assertEquals(20.0, $summary['released_month']);
        // All time keeps both.
        $this->assertEquals(520.0, $summary['released']);
        $this->assertNotNull($recent->id);
    }

    #[Test]
    public function money_owed_survives_the_driver_no_longer_being_a_driver(): void
    {
        $driver = $this->defaultDriver();
        $this->earning(DriverEarning::PENDING, 45, $driver);

        // They stop driving — moved to the office, or suspended. The debt does not
        // stop existing, and the `Driver` model's role scope would have hidden it
        // from the one screen that decides who gets paid.
        $driver->forceFill(['role_id' => Role::where('slug', 'customer')->value('id')])->save();

        $response = $this->actingAs($this->superAdmin())->get('/admin/earning')->assertOk();

        $byDriver = $response->viewData('byDriver');
        $this->assertCount(1, $byDriver);
        $this->assertEquals(45.0, $byDriver[0]['owed']);
        $this->assertSame($driver->name, $byDriver[0]['driver']);
        $this->assertEquals(45.0, $response->viewData('summary')['pending']);
    }

    #[Test]
    public function search_finds_earnings_by_driver_phone(): void
    {
        $driver = $this->driverUser('01066669999');
        $this->earning(DriverEarning::PENDING, 12, $driver);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/admin/earning/search?status=pending&query=01066669999', [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->assertOk();

        $this->assertStringContainsString('01066669999', $response->json('table'));
    }
}
