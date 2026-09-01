<?php

namespace App\Modules\LaundryStaff\Models;

use App\Modules\User\Models\User;
use App\Trait\BelongsToLaundry;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard users belonging to a laundry.
 *
 * Same shape as the Moderator model: a User subclass over the same table with a
 * global scope narrowing it to one audience. Two scopes stack here:
 *
 *   - this class's own scope keeps the set to users holding a `laundry` role
 *   - BelongsToLaundry narrows it further to the acting laundry, and forces
 *     `laundry_id` on create so a laundry owner cannot plant staff in another
 *     tenant by forging the field
 *
 * A super admin has no laundry context, so they see every laundry's staff and
 * must pass `laundry_id` explicitly.
 *
 * `use DashboardModel` is repeated rather than inherited because class_uses()
 * does not report a parent's traits, and PermissionGenerator relies on it.
 *
 * @method static Builder<static>|LaundryStaff newModelQuery()
 * @method static Builder<static>|LaundryStaff newQuery()
 * @method static Builder<static>|LaundryStaff query()
 *
 * @mixin \Eloquent
 */
class LaundryStaff extends User
{
    use BelongsToLaundry;
    use DashboardModel;

    protected $table = 'users';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('laundry_staff', function (Builder $query): void {
            $query->whereHas('role', fn ($q) => $q->where('type', 'laundry'));
        });
    }
}
