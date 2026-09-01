<?php

namespace App\Modules\Banner\Models;

use App\Modules\Banner\Enums\BannerTarget;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $description
 * @property string|null $image
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner search(?string $search, array $columns = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|banner whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class banner extends Model
{
    use DashboardModel;
    use HasFactory;
    use Searchable;

    protected $fillable = [
        'image',
        'name',
        'description',
        'target_type',
        'target_value',
        'status',
    ];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getNameAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function getDescriptionAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * The banner's target as the enum, defaulting to none.
     *
     * A row written before the columns existed holds the string default, and a
     * value that is no longer a valid case (a renamed kind) must not throw on a
     * list page — so an unknown string reads as "no action".
     */
    public function target(): BannerTarget
    {
        return BannerTarget::tryFrom((string) $this->target_type) ?? BannerTarget::None;
    }
}
