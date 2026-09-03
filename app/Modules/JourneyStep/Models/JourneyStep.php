<?php

namespace App\Modules\JourneyStep\Models;

use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One card in «رحلتك معنا بسيطة» on the customer's home screen.
 *
 * @property int $id
 * @property string|null $image
 * @property int $sort_order
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read mixed $title
 * @property-read mixed $description
 *
 * @method static Builder<static>|JourneyStep active()
 */
class JourneyStep extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'image',
        'title',
        'description',
        'sort_order',
        'status',
    ];

    /**
     * Without this Arabic is stored as `\uXXXX` escapes — unreadable in the
     * database and in any dump of it.
     */
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Decoded without `true`, so this is a **stdClass**: `$step->title->ar`,
     * never `$step->title['ar']` — the convention every translatable model here
     * follows, and what the Blade partials rely on.
     */
    public function getTitleAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function getDescriptionAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
