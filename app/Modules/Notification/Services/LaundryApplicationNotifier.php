<?php

namespace App\Modules\Notification\Services;

use App\Mail\LaundryApplicationDecided;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\User\Models\User;
use App\Notifications\AdminNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about a laundry applying, and about the answer.
 *
 * Two audiences with nothing in common, which is why this is not
 * `OrderNotifier`: operations hear in the panel's own bell, because they are
 * already in the panel; the applicant hears by email, because they are not —
 * they have no account they can sign in to yet.
 *
 * **The email is inert on this install.** `MAIL_MAILER=log`, so it is written
 * to `storage/logs` and delivered nowhere. That is a configuration decision and
 * not a reason to leave the code unwritten: the day SMTP is set the mail starts
 * arriving with no deploy. Until then a laundry finds out by trying to sign in,
 * which `LoginController` answers with «still being reviewed» rather than with
 * a wrong-password message.
 */
class LaundryApplicationNotifier
{
    /**
     * Somebody applied. Tell the people who decide.
     */
    public function received(Laundry $laundry): void
    {
        $recipients = $this->operators();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AdminNotification(
            __('A laundry has applied to join'),
            __(':name is waiting to be reviewed.', [
                'name' => getLocalizedValueDashboard($laundry, 'name'),
            ]),
            route('admin.laundry.show', $laundry->id),
            ['laundry_id' => (string) $laundry->id],
        ));
    }

    /**
     * A decision was made. Tell the applicant.
     */
    public function decided(Laundry $laundry, bool $approved): void
    {
        $owner = $laundry->owner;

        if (! $owner || blank($owner->email)) {
            return;
        }

        try {
            Mail::to($owner->email)->send(new LaundryApplicationDecided($laundry, $approved));
        } catch (\Throwable $e) {
            // A mail transport that is down must not roll back a decision an
            // operator has already made and can see on the screen.
            Log::warning('[mail] laundry decision', [
                'laundry' => $laundry->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The people who can act on an application.
     *
     * @return Collection<int, User>
     */
    private function operators()
    {
        return User::whereHas(
            'role',
            fn ($query) => $query->whereIn('slug', ['super_admin', 'admin'])
        )->get();
    }
}
