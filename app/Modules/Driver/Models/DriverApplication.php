<?php

namespace App\Modules\Driver\Models;

use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Somebody who asked to drive, from «عايز تشتغل مندوب؟» on the public page.
 *
 * A lead, not an account. Nobody here has been vetted, has a licence on file
 * or can sign in to anything — an operator reads the row, rings the number,
 * and creates a driver from it if the call goes well.
 *
 * `use DashboardModel` is what makes `PermissionGenerator` emit
 * `driver_application.{view,create,update,delete,toggle}` once the class is
 * listed in `config/dashboard.php`; without both it would be gated on
 * permissions that do not exist, which is a 403 nobody can grant their way
 * out of.
 *
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property string|null $note
 * @property Carbon|null $handled_at
 * @property int|null $handled_by
 * @property string|null $admin_note
 * @property-read User|null $handler
 *
 * @method static Builder<static>|DriverApplication waiting()
 */
class DriverApplication extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'name', 'phone', 'note', 'handled_at', 'handled_by', 'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    /**
     * What the sidebar counts and the screen opens on.
     *
     * Oldest first: a queue whose age nobody can see gets worked newest-first,
     * and the person who has waited longest is the one who has already found
     * another job.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('handled_at')->oldest('created_at');
    }

    public function isWaiting(): bool
    {
        return $this->handled_at === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
