<?php

namespace App\Models;

use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property int $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Role extends Model
{
    use DashboardModel;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_system',
    ];

    public const USER = 'user';

    public const ADMIN = 'admin';

    // Renamed from `employee`, which the design never used: it calls this
    // person مندوب / السائق throughout.
    public const DRIVER = 'driver';

    // The laundry's own account. Spelt out in a constant because it is now read
    // from the console as well as the dashboard, and a typo'd slug in a query
    // returns an empty set rather than an error.
    public const LAUNDRY_OWNER = 'laundry_owner';

    public const SUPER_ADMIN = 'super_admin';

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}
