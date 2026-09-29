<?php

namespace App\Models;

use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One change to one record — who, from where, and each field before and after.
 *
 * Written only by App\Services\ActivityLogger. Never edited: a log somebody can
 * change is a log nobody can trust, so there is no update path anywhere.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $actor_name
 * @property string|null $actor_role
 * @property string $source
 * @property string $event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property int|null $order_id
 * @property array<string, array{old: mixed, new: mixed}>|null $diff
 * @property string|null $route
 * @property string|null $ip
 * @property Carbon|null $created_at
 * @property-read User|null $user
 *
 * @method static Builder<static>|ActivityLog search(?string $search, array $columns = [])
 */
class ActivityLog extends Model
{
    use DashboardModel;
    use Searchable;

    public const UPDATED_AT = null;

    public const DASHBOARD = 'dashboard';

    public const API = 'api';

    public const SYSTEM = 'system';

    /** The public site: a laundry applying, a driver applying. */
    public const SITE = 'site';

    protected $fillable = [
        'user_id', 'actor_name', 'actor_role', 'source', 'event',
        'subject_type', 'subject_id', 'subject_label', 'order_id',
        'diff', 'route', 'ip', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'diff' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Keep Arabic readable in the column instead of \uXXXX escapes.
     */
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the subject is called, one of it — «مدينة», «طلب», «قطعة» — from
     * `activity.nouns`, else its class name made readable.
     */
    public function subjectKind(): string
    {
        if (! $this->subject_type) {
            return __('Record');
        }

        $base = class_basename($this->subject_type);
        $noun = config("activity.nouns.$base");

        return is_string($noun) ? __($noun) : Str::headline($base);
    }

    /**
     * The record's name in the reader's language. A translatable name is kept
     * in every language (see ActivityLogger::label()); anything else as typed.
     */
    public function subjectName(): ?string
    {
        $label = $this->subject_label;

        if (is_string($label) && str_starts_with($label, '{')) {
            $decoded = json_decode($label);

            if (is_object($decoded)) {
                return pickTranslation($decoded, app()->getLocale(), '') ?: null;
            }
        }

        return $label;
    }

    /**
     * A field as the forms name it — the Arabic attribute names the dashboard's
     * validation already carries — or its key, readable, when it has none.
     */
    public static function fieldLabel(string $key): string
    {
        // A language's own validation wording, from «Edit Validation Messages»
        // — named by its key, since the value beside it is the wording itself.
        if (str_starts_with($key, 'validation.attributes.')) {
            return __('Field name').' — '.substr($key, strlen('validation.attributes.'));
        }

        if (str_starts_with($key, 'validation.')) {
            return __('Validation message').' — '.substr($key, strlen('validation.'));
        }

        $translated = trans('validation.attributes.'.$key);

        return $translated !== 'validation.attributes.'.$key ? $translated : Str::headline($key);
    }
}
