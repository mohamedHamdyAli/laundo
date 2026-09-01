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
 * @property Carbon|null $license_expiry
 * @property Carbon|null $vehicle_registration_expiry
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
        'license_number',
        'license_expiry',
        'license_image',
        'vehicle_registration_image',
        'vehicle_registration_expiry',
        'national_id_image',
        'shift_start',
        'shift_end',
        'is_available',
        'max_concurrent_orders',
        'city_id',
        'notes',
        // Deliberately not fillable from any request payload: DriverController
        // writes them with forceFill after checking there is a live task, and a
        // profile update must not be able to move the driver on the map.
    ];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date',
            'vehicle_registration_expiry' => 'date',
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

        foreach (['license_expiry', 'vehicle_registration_expiry'] as $field) {
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
