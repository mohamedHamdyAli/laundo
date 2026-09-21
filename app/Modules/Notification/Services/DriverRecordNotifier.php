<?php

namespace App\Modules\Notification\Services;

use App\Modules\Driver\Models\DriverRecordSubmission;
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
            route('admin.driver_record_submission.index'),
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

        $this->send(
            collect([$driver]),
            __('Your details were not accepted'),
            $submission->note ?: __('Please check what you sent and try again.'),
            null,
            ['driver_record_submission_id' => (string) $submission->id],
        );
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
