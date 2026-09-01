<?php

namespace App\Modules\User\Models;

use App\Models\Role;
use App\Modules\Address\Models\Address;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\User\Services\CustomerReference;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int|null $role_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $customer_reference
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
 * @property-read Role|null $role
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User availableUsers()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User search(?string $search, array $columns = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGender($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereImageProfile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereOtp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereOtpExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use DashboardModel;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use Searchable;

    // Account closure keeps orders and invoices attached for accounting and
    // disputes. Note the unique indexes on phone/email still cover trashed rows,
    // so a closed account's number cannot be registered again.
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'role_id',
        'laundry_id',
        'image_profile',
        'gender',
        // `otp`, `otp_expires_at` and `otp_attempts` are deliberately absent:
        // OtpService writes them with forceFill, so no request payload can ever
        // set or clear a verification code by mass assignment.
        'status',
        'password',
        'phone_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Even hashed, a live verification code has no business appearing in a
        // serialized model.
        'otp',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Phone is the primary identity here, so this is what gates signing in.
     */
    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'user_id');
    }

    /**
     * The customer's own orders.
     *
     * Every customer-facing order lookup starts here rather than at
     * Order::find(), so an id belonging to somebody else is simply absent. The
     * Order model's tenant scope does not help on these routes — a customer is
     * not a tenant — which makes this relation the isolation boundary.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    /**
     * @return HasMany<OrderRecurrence, $this>
     */
    public function recurrences(): HasMany
    {
        return $this->hasMany(OrderRecurrence::class, 'user_id');
    }

    public function scopeAvailableUsers($query)
    {
        return $query->whereHas(
            'role',
            fn ($q) => $q->where('slug', Role::USER)
        )->latest();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * «مرجع العميل» is handed out here rather than at each registration path.
     *
     * There are four ways a customer row comes into being — the app, the
     * dashboard, the seeders and the tests — and a path that forgets to ask for a
     * reference produces a bag with nothing printed on it, which is discovered by
     * a person holding the bag rather than by anything that fails.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            CustomerReference::assign($user);
        });
    }
}
