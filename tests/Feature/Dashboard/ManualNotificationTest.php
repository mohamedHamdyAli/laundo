<?php

namespace Tests\Feature\Dashboard;

use App\Jobs\SendManualNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationAudience;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\DeviceToken;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\Notification\Services\ManualNotifier;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saying something the system has no event for.
 *
 * Every notification this panel could raise was tied to a moment it recognised —
 * an order placed, a leg assigned, an application received. «اتأخرنا عليكم» and
 * «الخدمة موقوفة النهارده» are not moments and never will be, so the only way to
 * say either was to telephone.
 *
 * The screen adds no delivery machinery of its own: it resolves *who* and hands
 * the message to `NotificationDispatcher`, so a hand-written message obeys the
 * same mute, writes the same log and survives the same push failure as every
 * automatic one. A second send path would be a second set of rules to keep in
 * step, and they would not stay in step.
 */
class ManualNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
    }

    /**
     * A moderator — a panel account that is not a super admin and carries no
     * laundry, so it exercises the permission rather than the bypass.
     */
    private function moderator(array $slugs = ['notification_log.view', 'notification_log.create']): User
    {
        $this->grant('admin', $slugs);

        return User::create([
            'name' => 'Mod', 'email' => 'mod@test.local', 'phone' => '+201000000009',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'phone_verified_at' => now(),
        ]);
    }

    private function buyer(string $phone, string $status = 'active'): User
    {
        return User::create([
            'name' => 'Buyer '.substr($phone, -3), 'phone' => $phone,
            'password' => 'password', 'status' => $status,
            'role_id' => Role::where('slug', Role::USER)->value('id'),
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'audience' => 'customer',
            'target' => 'all',
            'title' => 'We are running late',
            'body' => 'Today is busier than usual. Every collection is about an hour behind.',
        ];
    }

    // ------------------------------------------------------------- who may open it

    #[Test]
    public function the_screen_needs_the_create_permission(): void
    {
        // `.view` opens the log. Causing a notification is a different act from
        // reading one, and the permission set already had a slug for it.
        $this->actingAs($this->moderator(['notification_log.view']))
            ->get(route('admin.notification.compose'))
            ->assertForbidden();
    }

    #[Test]
    public function a_moderator_with_the_permission_can_open_it(): void
    {
        $this->actingAs($this->moderator())
            ->get(route('admin.notification.compose'))
            ->assertOk();
    }

    #[Test]
    public function a_laundry_account_is_refused_even_holding_the_permission(): void
    {
        /*
         * The guard that the permission system cannot express. A tick grants a
         * *screen*; it has no vocabulary for «to whom», and the tenant scope that
         * confines a laundry everywhere else does not reach a recipient list
         * assembled from a role slug. So an owner given this tick could write to
         * every customer on the platform, which is not what the tick looks like.
         */
        $owner = $this->laundryWithOwner('A', '+201011110001', '+201011110002')['owner'];

        Role::where('slug', 'laundry_owner')->firstOrFail()
            ->permissions()
            ->syncWithoutDetaching(Permission::where('slug', 'notification_log.create')->pluck('id'));

        $this->actingAs($owner)
            ->get(route('admin.notification.compose'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('admin.notification.send'), $this->payload())
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ sending

    #[Test]
    public function one_customer_gets_the_message_and_it_is_logged_under_its_author(): void
    {
        $buyer = $this->buyer('+201099880001');
        $sender = $this->moderator();

        $this->actingAs($sender)
            ->post(route('admin.notification.send'), $this->payload(['target' => (string) $buyer->id]))
            ->assertRedirect(route('admin.notification.index'));

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $buyer->id)->count());

        $log = NotificationLog::where('user_id', $buyer->id)->where('channel', 'database')->firstOrFail();

        $this->assertSame(NotificationEvent::ManualMessage, $log->event);
        $this->assertSame(NotificationLog::SENT, $log->status);
        // The whole reason the column exists: a hand-written message to a
        // customer with no author is the one row in an audit log that cannot be
        // audited.
        $this->assertSame($sender->id, $log->sent_by);
    }

    #[Test]
    public function everyone_means_every_active_account_in_that_audience(): void
    {
        $first = $this->buyer('+201099880002');
        $second = $this->buyer('+201099880003');
        $driver = $this->driverUser('+201033330777');

        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload())
            ->assertRedirect();

        foreach ([$first, $second] as $buyer) {
            $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $buyer->id)->count());
        }

        // A different audience is a different message. Choosing «every customer»
        // and reaching the drivers as well would be the worst possible way to
        // learn that the filter did not work.
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $driver->id)->count());
    }

    #[Test]
    public function an_inactive_account_is_not_offered_and_not_reachable(): void
    {
        $active = $this->buyer('+201099880004');
        $suspended = $this->buyer('+201099880005', 'inactive');

        $notifier = app(ManualNotifier::class);

        // Left out of the list rather than shown and refused: a name in a
        // recipient picker is a promise that choosing it does something.
        $this->assertArrayHasKey($active->id, $notifier->targets(NotificationAudience::Customer));
        $this->assertArrayNotHasKey($suspended->id, $notifier->targets(NotificationAudience::Customer));

        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload(['target' => (string) $suspended->id]))
            ->assertSessionHasErrors('target');

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $suspended->id)->count());
    }

    #[Test]
    public function a_laundry_resolves_to_its_own_people(): void
    {
        /*
         * A laundry is not a user, which is why the audience is an enum and not a
         * role slug. It reaches the owner **and** the staff, as
         * `orderAssignedToLaundry` already does: on a shop with shifts the owner
         * is the least likely of them to be looking.
         */
        $pair = $this->laundryWithOwner('B', '+201011110003', '+201011110004');

        $staff = User::create([
            'name' => 'Staff', 'email' => 'staffb@test.local', 'phone' => '+201011110005',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'laundry_staff')->value('id'),
            'laundry_id' => $pair['laundry']->id,
            'phone_verified_at' => now(),
        ]);

        $other = $this->laundryWithOwner('C', '+201011110006', '+201011110007')['owner'];

        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload([
                'audience' => 'laundry',
                'target' => (string) $pair['laundry']->id,
            ]))
            ->assertRedirect();

        foreach ([$pair['owner'], $staff] as $person) {
            $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $person->id)->count());
        }

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $other->id)->count());
    }

    #[Test]
    public function a_laundry_awaiting_approval_is_not_an_audience(): void
    {
        // An applicant is not yet a shop. Announcing a price change to somebody
        // who was rejected last week is a message that cannot be taken back.
        $pending = $this->laundryWithOwner('D', '+201011110008', '+201011110009');
        Laundry::withoutGlobalScopes()->where('id', $pending['laundry']->id)->update(['approved_at' => null]);

        $notifier = app(ManualNotifier::class);

        $this->assertArrayNotHasKey($pending['laundry']->id, $notifier->targets(NotificationAudience::Laundry));
        $this->assertSame(0, $notifier->recipients(NotificationAudience::Laundry, null)->count());
    }

    // ------------------------------------------------------------------ refusals

    #[Test]
    public function a_recipient_from_another_audience_is_refused(): void
    {
        /*
         * Only reachable by a hand-made request, and that is the point: without
         * it the id could name a customer while the form claimed to be writing to
         * drivers, and the message would reach a person the operator never saw.
         */
        $buyer = $this->buyer('+201099880006');

        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload([
                'audience' => 'driver',
                'target' => (string) $buyer->id,
            ]))
            ->assertSessionHasErrors('target');

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $buyer->id)->count());
    }

    #[Test]
    public function a_broadcast_bigger_than_the_inline_limit_goes_to_the_queue(): void
    {
        /*
         * The cap that used to live here is gone. It existed because every
         * message was an FCM round-trip inside the request that started it, and
         * an uncapped blast was a 504 nobody could tell apart from a bug. A
         * worker takes the crowd now — so the limit is no longer about how many
         * people may be told, only about how many are told before the page
         * answers.
         */
        config(['push.manual_inline_limit' => 1]);
        Queue::fake();

        $this->buyer('+201099880007');
        $this->buyer('+201099880008');

        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload())
            ->assertRedirect(route('admin.notification.index'));

        // One job per recipient, never one per chunk: a chunk that failed at the
        // sixtieth of a hundred would, on retry, reach the first fifty-nine a
        // second time — and a duplicate notification cannot be taken back.
        Queue::assertPushed(SendManualNotification::class, 2);

        // Nothing written inline. The worker has it.
        $this->assertSame(0, DB::table('notifications')->count());
    }

    #[Test]
    public function a_handful_is_still_written_before_the_page_answers(): void
    {
        // The common case, and the reason the inline path survives at all: one
        // person should be seen to arrive rather than promised.
        Queue::fake();

        $buyer = $this->buyer('+201099880012');

        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload(['target' => (string) $buyer->id]));

        Queue::assertNothingPushed();
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $buyer->id)->count());
    }

    #[Test]
    public function the_queued_job_delivers_exactly_what_the_inline_path_does(): void
    {
        /*
         * The thing that makes the split safe. Two send paths that drift apart
         * are two sets of rules about muting, logging and failure — so the job
         * resolves the person and hands the rest to the same dispatcher.
         */
        $buyer = $this->buyer('+201099880013');
        $sender = $this->moderator();

        (new SendManualNotification($buyer->id, 'Late today', 'An hour behind.', $sender->id, 'customer'))
            ->handle(app(NotificationDispatcher::class));

        $log = NotificationLog::where('user_id', $buyer->id)->where('channel', 'database')->firstOrFail();

        $this->assertSame(NotificationEvent::ManualMessage, $log->event);
        $this->assertSame(NotificationLog::SENT, $log->status);
        $this->assertSame($sender->id, $log->sent_by);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $buyer->id)->count());
    }

    #[Test]
    public function a_job_for_an_account_that_has_since_gone_is_not_a_failure(): void
    {
        // Deleted between the send and the worker reaching it. Retrying would
        // never succeed, and the person it was for is gone.
        $sender = $this->moderator();

        (new SendManualNotification(999999, 'Late today', 'An hour behind.', $sender->id, 'customer'))
            ->handle(app(NotificationDispatcher::class));

        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, NotificationLog::count());
    }

    #[Test]
    public function a_broadcast_says_it_is_sending_rather_than_sent(): void
    {
        /*
         * «Sending», not «sent». Telling somebody a message has gone while it is
         * still on a queue is exactly how a stopped worker becomes invisible.
         */
        config(['push.manual_inline_limit' => 1]);
        Queue::fake();

        $this->buyer('+201099880014');
        $this->buyer('+201099880015');

        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload())
            ->assertSessionHas('success', fn (string $message) => str_contains($message, __('Sending to :count people. They will appear in the log as they go out.', ['count' => 2])));
    }

    #[Test]
    public function an_empty_audience_is_refused_rather_than_reported_as_sent(): void
    {
        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload())
            ->assertSessionHasErrors('target');
    }

    #[Test]
    public function the_message_is_required(): void
    {
        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload(['title' => '', 'body' => '']))
            ->assertSessionHasErrors(['title', 'body']);
    }

    // ------------------------------------------------- it obeys the same rules

    #[Test]
    public function a_muted_phone_stays_muted_and_the_record_is_still_written(): void
    {
        /*
         * Deliberately **not** transactional. A hand-written message is the kind
         * most likely to be noise, and a mute the panel can talk over is not a
         * mute. `database` is written regardless, because the in-app list is a
         * record and was never what anybody asked to silence.
         */
        $buyer = $this->buyer('+201099880009');
        DeviceToken::create(['user_id' => $buyer->id, 'token' => str_repeat('t', 40), 'platform' => 'android']);
        NotificationPreference::create(['user_id' => $buyer->id, 'channel' => 'push', 'enabled' => false]);

        $this->actingAs($this->moderator())
            ->post(route('admin.notification.send'), $this->payload(['target' => (string) $buyer->id]))
            ->assertRedirect();

        $this->assertSame(
            NotificationLog::SKIPPED,
            NotificationLog::where('user_id', $buyer->id)->where('channel', 'push')->value('status')
        );

        $this->assertSame(
            NotificationLog::SENT,
            NotificationLog::where('user_id', $buyer->id)->where('channel', 'database')->value('status')
        );

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $buyer->id)->count());
    }

    #[Test]
    public function the_log_screen_names_the_person_who_sent_it(): void
    {
        $buyer = $this->buyer('+201099880010');
        $sender = $this->moderator();

        $this->actingAs($sender)->post(route('admin.notification.send'), $this->payload([
            'target' => (string) $buyer->id,
        ]));

        $this->actingAs($sender)
            ->get(route('admin.notification.index'))
            ->assertOk()
            ->assertSee(__('Sent by :name', ['name' => $sender->name]));
    }

    #[Test]
    public function an_automatic_message_has_no_author(): void
    {
        // Null is the honest value on every row written by the system. It is not
        // missing data — nobody sent those.
        $buyer = $this->buyer('+201099880011');

        app(NotificationDispatcher::class)->send(
            $buyer,
            new NotificationMessage(
                event: NotificationEvent::OrderPlaced,
                title: 'Placed',
                body: 'Placed',
            )
        );

        $this->assertNull(NotificationLog::where('user_id', $buyer->id)->value('sent_by'));
    }
}
