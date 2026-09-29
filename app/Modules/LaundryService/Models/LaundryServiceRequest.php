<?php

namespace App\Modules\LaundryService\Models;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Service\Models\Service;
use App\Modules\User\Models\User;
use App\Trait\BelongsToLaundry;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A laundry asking to open or close a service, and the answer.
 *
 * Tenant-scoped like `LaundryService`: a laundry reads its own requests and
 * nobody else's, and the creating hook overwrites `laundry_id` so a forged
 * payload cannot file a request on another laundry's behalf. The review screen
 * is the platform's and runs unscoped for anybody without a laundry.
 *
 * @property int $id
 * @property int $laundry_id
 * @property int $service_id
 * @property string $action
 * @property string $status
 * @property string|null $note
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Laundry|null $laundry
 * @property-read Service|null $service
 * @property-read User|null $requester
 * @property-read User|null $reviewer
 *
 * @method static Builder<static>|LaundryServiceRequest pending()
 * @method static Builder<static>|LaundryServiceRequest search(?string $search, array $columns = [])
 */
class LaundryServiceRequest extends Model
{
    use BelongsToLaundry;
    use DashboardModel;
    use Searchable;

    public const OPEN = 'open';

    public const CLOSE = 'close';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Replaced by a later request for the same service, or settled by an operator directly. */
    public const SUPERSEDED = 'superseded';

    protected $fillable = [
        'laundry_id', 'service_id', 'action', 'status', 'note',
        'requested_by', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Laundry, $this>
     */
    public function laundry(): BelongsTo
    {
        return $this->belongsTo(Laundry::class, 'laundry_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function opens(): bool
    {
        return $this->action === self::OPEN;
    }
}
