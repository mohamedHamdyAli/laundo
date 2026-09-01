<?php

namespace App\Modules\Notification\Models;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A channel somebody turned off.
 *
 * Only exceptions are stored: no row means enabled. A new channel is therefore
 * on for everybody without a backfill, and "never expressed a preference" is
 * represented honestly as the absence of a row rather than as a guess.
 *
 * @property int $user_id
 * @property string $channel
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'channel', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
