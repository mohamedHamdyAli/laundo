<?php

namespace App\Modules\Notification\Services;

use App\Modules\Driver\Models\DriverRecordSubmission;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\User\Models\User;
use App\Notifications\AdminNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * A driver has sent a licence photograph, or changed what their car is.
 *
 * Nothing about it takes effect until somebody looks, so a submission nobody is
 * told about is a driver waiting on a screen that will never move. This is the
 * whole reason the queue is worth having rather than writing the values
 * straight through.
 *
 * **Not only the super admins.** Whoever the install has put on driver work
 * holds `driver_record_submission.update`, and they are the people who would
 * act on this — telling only the owners means the queue is read by whoever
 * happens to open the dashboard rather than by whoever the permission says.
 */
class DriverRecordNotifier
{
    public function submitted(DriverRecordSubmission $submission): void
    {
        $driver = $submission->driver;

        if (! $driver) {
            return;
        }

        $this->send(
            $this->reviewers(),
            __('A driver sent their papers'),
            __(':name updated their vehicle or licence details.', ['name' => $driver->name]),
            // A path, not `route()`. The stored value is rendered in the bell
            // and clicked later, so an absolute URL bakes in whichever host
            // generated it — and that is not always the one the reader is on:
            // anything raised from the console or from tinker gets `APP_URL` or
            // `localhost`, and one written by a request behind Cloudflare gets
            // whatever scheme survived the edge. A live notification already
            // shipped pointing at `http://localhost/admin/...`.
            //
            // `OrderNotifier` has always stored paths. This is the rest of the
            // codebase catching up with it.
            '/admin/driver-record-submission',
            ['driver_record_submission_id' => (string) $submission->id],
        );
    }

    /**
     * Tell the driver what was decided.
     *
     * Only on a refusal. An approval shows up as the record simply being right
     * on their own screen, and a notification saying «your car is still a
     * Corolla» is noise — but a driver whose upload was refused and who was not
     * told sends the same photograph again, and it is refused for the same
     * unstated reason.
     */
    public function decided(DriverRecordSubmission $submission): void
    {
        $driver = $submission->driver;

        if (! $driver || $submission->status !== DriverRecordSubmission::REJECTED) {
            return;
        }

        // **Through the dispatcher, not `Notification::send()`.** The reviewers
        // above are panel users already looking at the bell, so a database row
        // reaches them; this one is addressed to a phone. `AdminNotification`
        // declares `via() = ['database']` only, so sending a driver through it
        // writes a row nobody is going to open and no push at all — which is
        // exactly the silence the mandatory rejection note exists to prevent.
        try {
            app(NotificationDispatcher::class)->send($driver, new NotificationMessage(
                event: NotificationEvent::DriverRecordRejected,
                title: __('Your details were not accepted'),
                body: $submission->note ?: __('Please check what you sent and try again.'),
                url: '/driver/profile',
                data: ['driver_record_submission_id' => (string) $submission->id],
                subject: $submission,
            ));
        } catch (\Throwable $e) {
            Log::warning('[notifications] driver record decision failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Everyone whose job this is.
     *
     * @return Collection<int, User>
     */
    private function reviewers()
    {
        return User::whereHas('role', function ($query) {
            $query->where('slug', 'super_admin')
                ->orWhereHas(
                    'permissions',
                    fn ($permission) => $permission->where('slug', 'driver_record_submission.update')
                );
        })->get();
    }

    /**
     * Deliberately never throws.
     *
     * A notification that fails must not roll back the submission it was
     * describing — a driver told their upload did not work when it did will
     * send it again, and the queue grows a duplicate nobody asked for.
     *
     * @param  Collection<int, User>  $recipients
     * @param  array<string, string>  $data
     */
    private function send($recipients, string $title, string $body, ?string $url, array $data): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new AdminNotification($title, $body, $url, $data));
        } catch (\Throwable $e) {
            Log::warning('[notifications] driver record notice failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
