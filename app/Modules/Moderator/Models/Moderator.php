<?php

namespace App\Modules\Moderator\Models;

use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int|null $role_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $image_profile
 * @property string $gender
 * @property string|null $otp
 * @property string|null $otp_expires_at
 * @property string $status
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator availableUsers()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator search(?string $search, array $columns = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereGender($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereImageProfile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereOtp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereOtpExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Moderator whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Moderator extends User
{
    use DashboardModel;

    protected $table = 'users';

    protected static function booted(): void
    {
        static::addGlobalScope('moderators', function ($query) {
            $query->whereHas(
                'role',
                fn ($q) => $q->where('type', 'dashboard')->where('slug', '!=', 'super_admin')
            );
        });
    }
}
