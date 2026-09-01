<?php

namespace App\Modules\Notification\Models;

use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * What was sent, and what happened to it.
 *
 * @property int|null $user_id
 * @property NotificationEvent $event
 * @property string $channel
 * @property string $status
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 *
 * @method static Builder<static>|NotificationLog failures()
 */
class NotificationLog extends Model
{
    use DashboardModel;

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'user_id', 'event', 'channel', 'status', 'destination',
        'title', 'body', 'failure_reason', 'subject_type', 'subject_id',
    ];

    protected function casts(): array
    {
        return ['event' => NotificationEvent::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeFailures(Builder $query): Builder
    {
        return $query->where('status', self::FAILED);
    }
}
