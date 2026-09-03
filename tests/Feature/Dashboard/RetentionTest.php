<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Notification\Models\DeviceToken;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Order\Services\OrderService;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\Payment;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Retention — `laundo:prune`.
 *
 * Three tables had no bound at all, each for a defensible reason: the notification
 * log answers "I never got it", the payment payload settles a dispute, and a
 * device token is only removed when Firebase rejects it.
 *
 * What is asserted hardest here is what the command must **not** do. A retention
 * job that removes something still needed is far worse than a large table, and
 * the mistake is invisible until the day somebody goes looking for the row.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->user = $this->customer('+201055550001');
    }

    private function log(string $status, int $daysAgo): NotificationLog
    {
        $log = NotificationLog::create([
            'user_id' => $this->user->id,
            'event' => 'order_placed',
            'channel' => 'push',
            'status' => $status,
            'title' => 'A notification',
            'body' => 'Something happened',
        ]);

        $log->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $log;
    }

    /**
     * A payment against a real order.
     *
     * `payments.order_id` is NOT NULL — a payment with no order is not a thing
     * this system has, so the fixture has to place one.
     */
    private function payment(int $daysAgo, array $payload = ['gateway' => 'response']): Payment
    {
        $geo = $this->seedGeo();
        $catalog = $this->seedCatalog();
        $address = $this->addressFor($this->user, $geo['zones'][0]);

        $order = app(OrderService::class)->place($this->user, [
            'service_id' => $catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'method' => 'card',
            'provider' => 'fake',
            'provider_reference' => 'REF-'.uniqid(),
            'amount' => 100,
            'status' => 'captured',
            'payload' => $payload,
        ]);

        $payment->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $payment;
    }

    private function token(?int $lastUsedDaysAgo, int $createdDaysAgo): DeviceToken
    {
        $token = DeviceToken::create([
            'user_id' => $this->user->id,
            'token' => 'tok-'.uniqid(),
            'platform' => 'android',
            'app' => 'customer',
            'last_used_at' => $lastUsedDaysAgo === null ? null : now()->subDays($lastUsedDaysAgo),
        ]);

        $token->forceFill(['created_at' => now()->subDays($createdDaysAgo)])->save();

        return $token;
    }

    // ------------------------------------------------------------- dry run

    #[Test]
    public function a_dry_run_reports_and_changes_nothing(): void
    {
        $this->log(NotificationLog::SENT, 200);
        $this->log(NotificationLog::FAILED, 500);

        $this->artisan('laundo:prune --dry-run')->assertSuccessful();

        // The first run in production has to be readable before it is trusted.
        $this->assertSame(2, NotificationLog::count());
    }

    // ------------------------------------------------- notification logs

    #[Test]
    public function a_delivered_log_goes_and_a_recent_one_stays(): void
    {
        $old = $this->log(NotificationLog::SENT, 200);
        $recent = $this->log(NotificationLog::SENT, 10);

        $this->artisan('laundo:prune')->assertSuccessful();

        $this->assertNull(NotificationLog::find($old->id));
        $this->assertNotNull(NotificationLog::find($recent->id));
    }

    #[Test]
    public function a_failed_log_is_kept_far_longer_than_a_delivered_one(): void
    {
        // Same age, different status. A failure is what somebody investigates
        // months later when a customer says they never heard from us.
        $sent = $this->log(NotificationLog::SENT, 200);
        $failed = $this->log(NotificationLog::FAILED, 200);

        $this->artisan('laundo:prune')->assertSuccessful();

        $this->assertNull(NotificationLog::find($sent->id));
        $this->assertNotNull(NotificationLog::find($failed->id));
    }

    #[Test]
    public function a_failed_log_does_eventually_go(): void
    {
        $ancient = $this->log(NotificationLog::FAILED, 500);

        $this->artisan('laundo:prune')->assertSuccessful();

        $this->assertNull(NotificationLog::find($ancient->id));
    }

    #[Test]
    public function a_skipped_log_is_treated_as_settled_not_as_a_failure(): void
    {
        // A skip is a deliberate non-send — a muted channel, or a user with no
        // device. It is a receipt, not a problem, so it follows the shorter window.
        $skipped = $this->log(NotificationLog::SKIPPED, 200);

        $this->artisan('laundo:prune')->assertSuccessful();

        $this->assertNull(NotificationLog::find($skipped->id));
    }

    #[Test]
    public function the_retention_windows_are_adjustable(): void
    {
        $log = $this->log(NotificationLog::SENT, 30);

        // Nothing to do at the default 90 days.
        $this->artisan('laundo:prune')->assertSuccessful();
        $this->assertNotNull(NotificationLog::find($log->id));

        $this->artisan('laundo:prune --logs=7')->assertSuccessful();
        $this->assertNull(NotificationLog::find($log->id));
    }

    // ------------------------------------------------- payment payloads

    #[Test]
    public function an_old_payload_is_cleared_and_the_payment_row_survives(): void
    {
        $payment = $this->payment(400, ['gateway' => 'said something long']);

        $this->artisan('laundo:prune')->assertSuccessful();

        $fresh = Payment::find($payment->id);

        // The row is the money — what was charged, when, against which order.
        // That is accounting and it is never deleted.
        $this->assertNotNull($fresh);
        // The cast returns the enum, not the string.
        $this->assertSame(PaymentStatus::Captured, $fresh->status);
        $this->assertEquals(100, $fresh->amount);
        // Only the provider's JSON around it goes.
        $this->assertNull($fresh->payload);
    }

    #[Test]
    public function a_recent_payload_is_left_alone(): void
    {
        $payment = $this->payment(1, ['gateway' => 'still needed for a dispute']);

        $this->artisan('laundo:prune')->assertSuccessful();

        $this->assertNotNull(Payment::find($payment->id)->payload);
    }

    // --------------------------------------------------- device tokens

    #[Test]
    public function a_token_nobody_has_used_in_months_is_removed(): void
    {
        $stale = $this->token(lastUsedDaysAgo: 300, createdDaysAgo: 400);
        $active = $this->token(lastUsedDaysAgo: 3, createdDaysAgo: 400);

        $this->artisan('laundo:prune')->assertSuccessful();

        // Firebase only rejects a token when we try to send to it, so a device
        // that stopped being used is never rejected — it just goes quiet.
        $this->assertNull(DeviceToken::find($stale->id));
        $this->assertNotNull(DeviceToken::find($active->id));
    }

    #[Test]
    public function a_token_never_used_falls_back_to_when_it_was_registered(): void
    {
        $oldNeverUsed = $this->token(lastUsedDaysAgo: null, createdDaysAgo: 400);
        $newNeverUsed = $this->token(lastUsedDaysAgo: null, createdDaysAgo: 5);

        $this->artisan('laundo:prune')->assertSuccessful();

        $this->assertNull(DeviceToken::find($oldNeverUsed->id));
        // Registered last week and not yet sent to. Not stale — just new.
        $this->assertNotNull(DeviceToken::find($newNeverUsed->id));
    }

    // ------------------------------------------------------------ safety

    #[Test]
    public function the_command_reports_what_it_affected(): void
    {
        $this->log(NotificationLog::SENT, 200);
        $this->log(NotificationLog::SENT, 200);

        $this->artisan('laundo:prune')
            ->expectsOutputToContain('Affected 2 row(s).')
            ->assertSuccessful();
    }

    #[Test]
    public function running_it_twice_is_harmless(): void
    {
        $this->log(NotificationLog::SENT, 200);

        $this->artisan('laundo:prune')->assertSuccessful();
        $this->artisan('laundo:prune')->assertSuccessful();

        $this->assertSame(0, NotificationLog::count());
    }
}
