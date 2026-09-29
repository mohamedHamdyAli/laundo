<?php

namespace App\Modules\Notification\Services;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who in the panel hears about something — one definition, for every notifier
 * that rings the panel's bell.
 *
 * Two audiences, and the line between them is `users.laundry_id`: the
 * platform is everybody with no laundry, and a laundry is its own owner and
 * staff. A notifier picks them explicitly rather than through the tenant
 * scope, because the request that raised the notice — a driver's handover, a
 * customer's order — belongs to nobody in the panel.
 */
final class PanelAudience
{
    /**
     * The super admin, and anybody at the platform — not inside a laundry —
     * holding the permission. Active accounts only: a switched-off moderator
     * has nobody to read the bell.
     *
     * @return Collection<int, User>
     */
    public static function platform(string $permission): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->whereNull('laundry_id')
            ->whereHas('role', fn ($role) => $role
                ->where('slug', 'super_admin')
                ->orWhereHas('permissions', fn ($query) => $query->where('slug', $permission)))
            ->get();
    }

    /**
     * A laundry's own owner and staff holding the permission.
     *
     * @return Collection<int, User>
     */
    public static function laundry(?int $laundryId, string $permission): Collection
    {
        if ($laundryId === null) {
            return new Collection;
        }

        return User::query()
            ->where('status', 'active')
            ->where('laundry_id', $laundryId)
            ->whereHas('role', fn ($role) => $role
                ->whereHas('permissions', fn ($query) => $query->where('slug', $permission)))
            ->get();
    }
}
