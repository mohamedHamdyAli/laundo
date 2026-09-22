<?php

namespace App\Modules\Driver\Models;

use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A driver's own correction to their vehicle or their papers, awaiting a person.
 *
 * @property int $id
 * @property int $driver_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $note
 * @property Carbon|null $created_at
 *
 * @method static Builder<static>|DriverRecordSubmission pending()
 */
class DriverRecordSubmission extends Model
{
    use DashboardModel;
    use Searchable;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /**
     * The fields a driver may send, and how each is read.
     *
     * One list because three things need it to agree: what the API accepts, what
     * the review screen renders, and what approval writes. A field in two of the
     * three is a field that either cannot be submitted, cannot be read, or is
     * approved without anybody having seen it.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'vehicle_type' => 'text',
        'plate_number' => 'text',
        'vehicle_brand' => 'text',
        'vehicle_model' => 'text',
        'vehicle_year' => 'text',
        'vehicle_color' => 'text',
        'license_number' => 'text',
        'license_type' => 'text',
        'license_issued_at' => 'date',
        'license_expiry' => 'date',
        'vehicle_registration_expiry' => 'date',
        'vehicle_insurance_expiry' => 'date',
        'vehicle_inspection_expiry' => 'date',
        'license_image' => 'image',
        'vehicle_registration_image' => 'image',
        'vehicle_insurance_image' => 'image',
        'vehicle_inspection_image' => 'image',
        'national_id_image' => 'image',
        'other_document_image' => 'image',
    ];

    protected $fillable = [
        'driver_id', 'payload', 'status', 'reviewed_by', 'reviewed_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Anything a list screen shows can be searched — the owner's rule.
     *
     * @var array<int, string>
     */
    public array $searchable = ['driver.name', 'driver.phone', 'status', 'note'];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * What this submission proposes, against what the driver's record says now.
     *
     * Computed at **review time**, not at submit time, and that is the point. An
     * operator may have corrected the same driver in between, and a diff worked
     * out hours ago would show the operator's own correction as the thing being
     * replaced without saying so. Reading the record now means the «Now» column
     * is what is actually there when the decision is made.
     *
     * `changed` is `current !== proposed` and nothing cleverer — it marks the
     * rows worth looking at, not a concurrent edit. Whether somebody else has
     * touched this driver since the submission is a separate question, and
     * `recordMovedSinceSubmitted()` is the one that answers it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function diff(): array
    {
        $profile = $this->driver?->profile;
        $rows = [];

        foreach ($this->payload as $field => $proposed) {
            if (! array_key_exists($field, self::FIELDS)) {
                continue;
            }

            $current = $profile?->{$field};

            if ($current instanceof Carbon) {
                $current = $current->toDateString();
            }

            $rows[] = [
                'field' => $field,
                'type' => self::FIELDS[$field],
                'current' => $current,
                'proposed' => $proposed,
                'changed' => (string) $current !== (string) $proposed,
            ];
        }

        return $rows;
    }

    /**
     * Has somebody edited this driver since the submission arrived?
     *
     * The diff shows *what* the record says now; this says whether «now» is
     * still «when they sent it». An operator who corrected a plate number an
     * hour ago and then approves the driver's own value for the same field is
     * undoing their own work, and the two columns alone read identically
     * whether that happened or not.
     *
     * Row-level rather than per-field, because the prior values are deliberately
     * not stored: a per-field answer would need a snapshot taken at submit time,
     * which is the very thing that goes stale. «Something here moved, look
     * before you approve» is the honest resolution available.
     */
    public function recordMovedSinceSubmitted(): bool
    {
        $profile = $this->driver?->profile;

        return $profile?->updated_at !== null
            && $this->created_at !== null
            && $profile->updated_at->gt($this->created_at);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            default => 'Awaiting review',
        };
    }
}
