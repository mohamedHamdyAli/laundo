<?php

namespace App\Modules\Notification\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryServiceRequest;
use App\Modules\User\Models\User;
use App\Notifications\AdminNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about a laundry asking to open or close a service, and about the
 * answer.
 *
 * Both audiences are panel users — the reviewers and the laundry's owner — so
 * both hear in the panel's own bell, through `AdminNotification`. There is no
 * push leg: push goes to the phone apps, and a laundry owner has none.
 *
 * Failures are swallowed and logged. A request that was filed and not announced
 * is a row with a badge beside it in the sidebar; a request that failed to file
 * because a notification did is a laundry that has to ask again.
 */
class LaundryServiceRequestNotifier
{
    /**
     * A laundry asked. Tell the people who decide.
     *
     * @param  array<int, LaundryServiceRequest>  $requests
     */
    public function submitted(Laundry $laundry, array $requests): void
    {
        if ($requests === []) {
            return;
        }

        $this->send(
            $this->reviewers(),
            __('A laundry asked to change its services'),
            __(':laundry asked to :changes.', [
                'laundry' => getLocalizedValueDashboard($laundry, 'name'),
                'changes' => collect($requests)->map(fn (LaundryServiceRequest $request) => ($request->opens() ? __('open') : __('close'))
                    .' '.($request->service ? getLocalizedValueDashboard($request->service, 'name') : '#'.$request->service_id))
                    ->implode('، '),
            ]),
            // A path, never `route()`: the stored value is clicked later,
            // somewhere else, and an absolute URL bakes in whichever host built
            // it. NotificationUrlTest reads this file for exactly that.
            '/admin/laundry-service-request',
            ['laundry_id' => (string) $laundry->id],
        );
    }

    /**
     * Somebody decided. Tell the laundry, with the note when it was a refusal —
     * told only «rejected», it sends the same request again.
     */
    public function decided(LaundryServiceRequest $request): void
    {
        $laundry = $request->laundry()->withoutGlobalScopes()->first();
        $owner = $laundry?->owner;

        if (! $owner) {
            return;
        }

        $service = $request->service ? getLocalizedValueDashboard($request->service, 'name') : '#'.$request->service_id;
        $approved = $request->status === LaundryServiceRequest::APPROVED;

        $title = $approved
            ? ($request->opens() ? __(':service is now open for your laundry', ['service' => $service]) : __(':service is now closed for your laundry', ['service' => $service]))
            : ($request->opens() ? __('Your request to open :service was not approved', ['service' => $service]) : __('Your request to close :service was not approved', ['service' => $service]));

        $this->send(
            collect([$owner]),
            $title,
            $approved ? __('The change applies from now on.') : (string) $request->note,
            '/admin/laundry-service',
            ['laundry_service_request_id' => (string) $request->id],
        );
    }

    /**
     * The super admin, and anybody handed the review permission.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function reviewers()
    {
        return User::whereHas('role', function ($query) {
            $query->where('slug', 'super_admin')
                ->orWhereHas(
                    'permissions',
                    fn ($permission) => $permission->where('slug', 'laundry_service_request.update')
                );
        })
            // Only people who see every laundry. A laundry's own staff who were
            // somehow granted the permission are not the ones to ask.
            ->whereNull('laundry_id')
            ->get();
    }

    /**
     * @param  Collection<int, User>|\Illuminate\Database\Eloquent\Collection<int, User>  $recipients
     * @param  array<string, string>  $data
     */
    private function send($recipients, string $title, string $body, string $url, array $data): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new AdminNotification($title, $body, $url, $data));
        } catch (\Throwable $e) {
            Log::warning('[notifications] laundry service request notice failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
