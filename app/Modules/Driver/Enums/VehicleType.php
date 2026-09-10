<?php

namespace App\Modules\Driver\Enums;

/**
 * What a driver turns up on.
 *
 * `driver_profiles.vehicle_type` was free text, so the two rows on this install
 * hold «Motorcycle» and «Van» — typed by hand, and one typo away from a value
 * nothing can group, filter or count. A closed list is the point: fleet mix is
 * a question operations will ask, and it cannot be answered over free text.
 *
 * The column stays a string rather than becoming an enum column. A `varchar`
 * holding a known set is the same shape the rest of this application uses for
 * `status`, and it means an install that already has values keeps them until
 * somebody edits the row, instead of failing a migration on «Motorbike».
 *
 * The list is Cairo's, not a generic one: the tuk-tuk is on it because it is
 * how short deliveries actually move here, and the bicycle because a laundry
 * round inside one district does not need an engine.
 */
enum VehicleType: string
{
    case Motorcycle = 'motorcycle';
    case Bicycle = 'bicycle';
    case TukTuk = 'tuk_tuk';
    case Car = 'car';
    case Van = 'van';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Motorcycle => 'Motorcycle',
            self::Bicycle => 'Bicycle',
            self::TukTuk => 'Tuk-tuk',
            self::Car => 'Car',
            self::Van => 'Van',
        };
    }

    /**
     * The stored string as a case, or null when it is something older.
     *
     * Case-insensitive and tolerant of the spelling the free-text field
     * collected — «Motorcycle» and «Van» are already in the database with a
     * capital letter, and a strict `tryFrom()` would show both as blank on the
     * edit screen and quietly drop them on the next save.
     */
    public static function parse(?string $stored): ?self
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $normalised = str_replace([' ', '-'], '_', strtolower(trim($stored)));

        return self::tryFrom($normalised);
    }
}
