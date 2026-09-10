<?php

namespace App\Modules\Notification\Services;

use App\Modules\Driver\Models\DriverApplication;
use App\Modules\User\Models\User;
use App\Notifications\AdminNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Somebody asked to drive. Tell the people who ring them.
 *
 * The panel's own bell only, and no email: the audience is already signed in
 * to the panel, and the applicant is expecting a phone call rather than a
 * confirmation message. A recruitment lead that sits unseen for a week is a
 * courier who took another job, so this is the whole point of taking the form
 * rather than printing a phone number on the page.
 */
class DriverApplicationNotifier
{
    public function received(DriverApplication $application): void
    {
        $recipients = User::whereHas(
            'role',
            fn ($query) => $query->whereIn('slug', ['super_admin', 'admin'])
        )->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AdminNotification(
            __('Someone wants to drive with us'),
            __(':name left a number — :phone.', [
                'name' => $application->name,
                'phone' => $application->phone,
            ]),
            route('admin.driver_application.index'),
            ['driver_application_id' => (string) $application->id],
        ));
    }
}
