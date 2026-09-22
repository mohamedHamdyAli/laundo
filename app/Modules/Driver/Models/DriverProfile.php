<?php

namespace App\Modules\Driver\Models;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The driver's vehicle, documents, shift and availability.
 *
 * @property int $user_id
 * @property string|null $vehicle_type
 * @property string|null $plate_number
 * @property string|null $license_number
 * @property Carbon|null $license_issued_at
 * @property Carbon|null $license_expiry
 * @property Carbon|null $vehicle_registration_expiry
 * @property Carbon|null $vehicle_insurance_expiry
 * @property Carbon|null $vehicle_inspection_expiry
 * @property float|null $last_lat
 * @property float|null $last_lng
 * @property Carbon|null $located_at
 * @property string|null $shift_start
 * @property string|null $shift_end
 * @property bool $is_available
 */
class DriverProfile extends Model
{
    protected $fillable = [
        'user_id',
        'vehicle_type',
        'plate_number',
        'vehicle_brand',
        'vehicle_model',
        'vehicle_year',
        'vehicle_color',
        'license_number',
        'license_type',
        'license_issued_at',
        'license_expiry',
        'license_image',
        'vehicle_registration_image',
        'vehicle_registration_expiry',
        'vehicle_insurance_image',
        'vehicle_insurance_expiry',
        'vehicle_inspection_image',
        'vehicle_inspection_expiry',
        'national_id_image',
        'other_document_image',
        'shift_start',
        'shift_end',
        'is_available',
        'max_concurrent_orders',
        'city_id',
        'notes',
        // Deliberately not fillable from any request payload: DriverController
        // writes them with forceFill after checking there is a live task, and a
        // profile update must not be able to move the driver on the map.
        //
        // `bonus_rule_id` is absent for two reasons of its own. It is a money
        // term, so it belongs behind `setting.update` rather than the
        // `driver.update` an operator holds to keep licences and shifts current
        // — the same boundary the laundry commission draws. And
        // driverCrudService::profilePayload() runs the payload through
        // array_filter, which drops nulls: a rule assigned through the form
        // could never be un-assigned again.
    ];

    protected function casts(): array
    {
        return [
            'license_issued_at' => 'date',
            'license_expiry' => 'date',
            'vehicle_registration_expiry' => 'date',
            'vehicle_insurance_expiry' => 'date',
            'vehicle_inspection_expiry' => 'date',
            'is_available' => 'boolean',
            'last_lat' => 'float',
            'last_lng' => 'float',
            'located_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The bonus terms this driver is on. **Null means no bonus.**
     *
     * @return BelongsTo<DriverBonusRule, $this>
     */
    public function bonusRule(): BelongsTo
    {
        return $this->belongsTo(DriverBonusRule::class, 'bonus_rule_id');
    }

    /**
     * Every dated document, so a new one cannot be added and quietly go unwatched.
     *
     * Listed once because two things read it: the dashboard's «Expired» badge and
     * the field-level warning beneath each date. A document with an expiry that
     * is not in here lapses in silence, which is the one thing recording the date
     * was for.
     *
     * «مستندات أخرى» has no expiry on purpose — nobody knows what it is, so
     * nothing about it can be said to have lapsed.
     *
     * @var array<int, string>
     */
    public const EXPIRY_FIELDS = [
        'license_expiry',
        'vehicle_registration_expiry',
        'vehicle_insurance_expiry',
        'vehicle_inspection_expiry',
    ];

    /**
     * Documents that have lapsed, keyed by field, for the dashboard warning.
     *
     * By decision an expired document does not stop assignment — it is surfaced
     * for a human to act on. Dates only, so `startOfDay` keeps a document valid
     * through its whole expiry day rather than from midnight.
     *
     * @return array<string, Carbon>
     */
    public function expiredDocuments(): array
    {
        $today = now()->startOfDay();
        $expired = [];

        foreach (self::EXPIRY_FIELDS as $field) {
            $date = $this->{$field};

            if ($date && $date->startOfDay()->lessThan($today)) {
                $expired[$field] = $date;
            }
        }

        return $expired;
    }

    public function hasExpiredDocuments(): bool
    {
        return $this->expiredDocuments() !== [];
    }

    /**
     * The shift as a person reads it: "09:00 – 21:00", or null when unset.
     */
    public function shiftLabel(): ?string
    {
        if (! $this->shift_start || ! $this->shift_end) {
            return null;
        }

        return substr((string) $this->shift_start, 0, 5).' – '.substr((string) $this->shift_end, 0, 5);
    }
}
