<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Complaint\Models\Complaint;
use App\Modules\Complaint\Services\ComplaintService;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The operator's own inbox, and the complaint alert that fills it.
 *
 * Worth stating what these two things are, because their route names sat one letter
 * apart until now and I described them wrongly once:
 *
 *   - `admin.myNotifications.*` reads Laravel's `notifications` table — the inbox
 *     `NotificationDispatcher::toDatabase()` writes to, and what the topbar bell
 *     shows.
 *   - `admin.notification.*` reads `notification_logs` — an audit record of every
 *     delivery attempt on every channel to customers and drivers.
 *
 * Different tables, different audiences, both correct. Neither was abandoned.
 */
class OperatorInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->customer = $this->customer('01055550001');
    }

    private function complain(string $body = 'something went wrong here'): Complaint
    {
        return app(ComplaintService::class)->submit($this->customer, [
            'category' => 'other',
            'body' => $body,
        ]);
    }

    // ------------------------------------------------------- the complaint alert

    #[Test]
    public function a_new_complaint_reaches_the_operators_inbox(): void
    {
        $admin = $this->superAdmin();

        $complaint = $this->complain();

        // The «waiting over a day» counter measures complaints nobody looked at.
        // This is what stops there being any.
        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());

        $data = $admin->fresh()->notifications()->first()->data;

        // The reference and the category, so the alert is actionable without
        // opening anything.
        $this->assertStringContainsString($complaint->reference, $data['message']);
        $this->assertSame('/admin/complaint/show/'.$complaint->id, $data['url']);
    }

    #[Test]
    public function the_alert_is_logged_per_channel_like_every_other_notification(): void
    {
        $this->superAdmin();

        $this->complain();

        $logs = NotificationLog::where('event', NotificationEvent::ComplaintReceived->value)->get();

        // One row per channel, not per notification — the log records every
        // delivery *attempt*, which is the whole point of it existing.
        $channels = $logs->pluck('channel')->sort()->values()->all();
        $this->assertSame(['database', 'push'], $channels);

        // The inbox write succeeded.
        $this->assertSame(
            NotificationLog::SENT,
            $logs->firstWhere('channel', 'database')->status
        );

        // The push did not, and that is not a failure: an operator who has never
        // opened a mobile app has no device token. It is logged as a skip so a
        // token Firebase invalidated months ago is still distinguishable from one
        // that never existed.
        $this->assertNotSame(
            NotificationLog::FAILED,
            $logs->firstWhere('channel', 'push')->status
        );
    }

    #[Test]
    public function a_complaint_still_lands_when_there_is_nobody_to_alert(): void
    {
        // No super admin exists. The complaint has already been accepted by the
        // time the alert is attempted, and losing it would be far worse than a
        // missed notification.
        $complaint = $this->complain();

        $this->assertNotNull($complaint->reference);
        $this->assertSame(1, Complaint::count());
    }

    #[Test]
    public function a_muted_operator_is_still_told_about_a_complaint(): void
    {
        $admin = $this->superAdmin();

        // ComplaintReceived is transactional: a muted operator is still the person
        // who has to answer. The mute switch silences noise, not the work.
        $this->assertTrue(NotificationEvent::ComplaintReceived->isTransactional());

        $this->complain();

        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
    }

    // -------------------------------------------------------------- the inbox

    #[Test]
    public function an_operator_can_open_their_inbox(): void
    {
        $admin = $this->superAdmin();
        $this->complain();

        $response = $this->actingAs($admin)->get('/admin/my-notifications')->assertOk();

        $this->assertSame(1, $response->viewData('unread'));
        $this->assertCount(1, $response->viewData('notifications'));
    }

    #[Test]
    public function unread_notifications_come_before_read_ones(): void
    {
        $admin = $this->superAdmin();

        $this->complain('the first complaint about something');
        $this->complain('the second complaint about something');

        // Distinct timestamps, deliberately. Both complaints land inside the same
        // second, so `created_at` ties and `first()` picks either one — the test
        // then passed or failed depending on which. It measured ordering it had
        // not actually established.
        $notifications = $admin->fresh()->notifications()->orderBy('id')->get();
        $notifications[0]->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $notifications[1]->forceFill(['created_at' => now()])->save();

        // Read the newest, so "newest first" alone would put it at the top.
        $notifications[1]->markAsRead();

        $notifications = $this->actingAs($admin)
            ->get('/admin/my-notifications')->assertOk()->viewData('notifications');

        // An alert from Tuesday nobody has opened matters more than one from an
        // hour ago that has been dealt with.
        $this->assertNull($notifications->first()->read_at);
        $this->assertNotNull($notifications->last()->read_at);
    }

    #[Test]
    public function an_operator_sees_only_their_own_notifications(): void
    {
        $admin = $this->superAdmin();
        $this->complain();

        // A second dashboard user who was not a recipient.
        $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');

        $this->assertCount(
            0,
            $this->actingAs($tenant['owner'])
                ->get('/admin/my-notifications')->assertOk()->viewData('notifications')
        );

        $this->assertCount(
            1,
            $this->actingAs($admin)
                ->get('/admin/my-notifications')->assertOk()->viewData('notifications')
        );
    }

    #[Test]
    public function marking_one_read_from_the_page_returns_to_the_page(): void
    {
        $admin = $this->superAdmin();
        $this->complain();

        $id = $admin->fresh()->notifications()->first()->id;

        // A form post, not AJAX. Returning JSON here would show the operator a
        // page of raw JSON — a working endpoint that looks broken.
        $this->actingAs($admin)
            ->post('/admin/my-notifications/'.$id.'/read')
            ->assertRedirect();

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function marking_one_read_by_ajax_still_returns_json(): void
    {
        $admin = $this->superAdmin();
        $this->complain();

        $id = $admin->fresh()->notifications()->first()->id;

        // The bell needs JSON. Both callers hit the same action.
        $this->actingAs($admin)
            ->postJson('/admin/my-notifications/'.$id.'/read')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    #[Test]
    public function marking_all_read_clears_the_badge(): void
    {
        $admin = $this->superAdmin();

        $this->complain('the first complaint about something');
        $this->complain('the second complaint about something');

        $this->assertSame(2, $admin->fresh()->unreadNotifications()->count());

        $this->actingAs($admin)
            ->post('/admin/my-notifications/read-all')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function one_operator_cannot_mark_anothers_notification_read(): void
    {
        $admin = $this->superAdmin();
        $this->complain();

        $id = $admin->fresh()->notifications()->first()->id;

        $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');

        $this->actingAs($tenant['owner'])
            ->post('/admin/my-notifications/'.$id.'/read')
            ->assertNotFound();

        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function the_bell_endpoint_reports_the_unread_count(): void
    {
        $admin = $this->superAdmin();
        $this->complain();

        $this->actingAs($admin)
            ->getJson('/admin/my-notifications/unread')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    #[Test]
    public function the_inbox_and_the_delivery_log_are_not_the_same_screen(): void
    {
        // They read different tables and answer different questions. The route
        // names sat one letter apart until this phase, which is the reason this
        // test exists at all.
        $admin = $this->superAdmin();
        $this->complain();

        $inbox = $this->actingAs($admin)->get('/admin/my-notifications')->assertOk();
        $log = $this->actingAs($admin)->get('/admin/notification')->assertOk();

        $this->assertNotNull($inbox->viewData('notifications'));
        // The log's own view data, which the inbox does not have.
        $this->assertNotNull($log->viewData('logs'));
    }
}
