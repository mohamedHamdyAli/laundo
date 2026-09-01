<?php

namespace App\Modules\Faq\Models;

use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One question and its answer.
 *
 * @property int $id
 * @property string|null $question
 * @property string|null $answer
 * @property string $audience
 * @property int $order
 * @property string $status
 * @property Carbon|null $created_at
 *
 * @method static Builder<static>|Faq for(string $audience)
 */
class Faq extends Model
{
    use DashboardModel;
    use HasFactory;
    use Searchable;

    protected $fillable = [
        'question',
        'answer',
        'audience',
        'order',
        'status',
    ];

    /** Keeps Arabic as Arabic rather than \uXXXX escapes. */
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** Returns a stdClass, not an array — so `->question->ar`, never `['ar']`. */
    public function getQuestionAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function getAnswerAttribute($value)
    {
        return json_decode((string) $value);
    }

    /**
     * The list one app should see: its own entries plus the shared ones.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFor(Builder $query, string $audience): Builder
    {
        return $query->whereIn('audience', ['both', $audience]);
    }
}
