<?php

namespace App\Modules\Driver\Models;

use App\Models\Role;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\Zone;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A driver: a user holding the `driver` role.
 *
 * Same shape as Moderator and LaundryStaff — a User subclass over the same table
 * with a global scope narrowing it to one audience. Not tenant-scoped: drivers
 * belong to Laundo and move between laundries, which is the whole point of the
 * four-leg journey.
 *
 * `use DashboardModel` is repeated rather than inherited because class_uses()
 * does not report a parent's traits, and PermissionGenerator relies on it.
 *
 * @property-read DriverProfile|null $profile
 * @property-read Collection<int, Zone> $zones
 *
 * @method static Builder<static>|Driver newModelQuery()
 * @method static Builder<static>|Driver newQuery()
 * @method static Builder<static>|Driver query()
 *
 * @mixin \Eloquent
 */
class Driver extends User
{
    use DashboardModel;

    protected $table = 'users';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('drivers', function (Builder $query): void {
            $query->whereHas('role', fn ($q) => $q->where('slug', Role::DRIVER));
        });
    }

    /**
     * The legs this driver is carrying.
     *
     * The isolation boundary for the driver API: every task lookup starts here,
     * so another driver's id is simply not found.
     *
     * @return HasMany<OrderTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OrderTask::class, 'driver_id');
    }

    /**
     * @return HasOne<DriverProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(DriverProfile::class, 'user_id');
    }

    /**
     * «المناطق التي أخدمها» — the zones this driver covers.
     *
     * @return BelongsToMany<Zone, $this>
     */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'driver_zones', 'driver_id', 'zone_id')
            ->withTimestamps();
    }

    /**
     * Whether this driver can be handed work right now.
     *
     * Availability alone is not enough — a suspended account must not receive
     * tasks however its switch is set. Shift and zone matching belong to the P8
     * dispatcher, which has an order to compare against.
     */
    public function isDispatchable(): bool
    {
        return $this->isActive() && (bool) $this->profile?->is_available;
    }
}
