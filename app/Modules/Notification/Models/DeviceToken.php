<?php

namespace App\Modules\Notification\Models;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One handset.
 *
 * @property int $user_id
 * @property string $token
 * @property string|null $platform
 * @property string|null $app
 * @property Carbon|null $last_used_at
 */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'app', 'locale', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
