<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\City\Models\City;
use App\Modules\Driver\Enums\VehicleType;
use App\Modules\Driver\Models\Driver;
use App\Modules\Driver\Requests\DriverRequest;
use App\Modules\Driver\Services\driverCrudService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «السائقين» — the driver accounts, with their vehicle, licence, shift and
 * areas.
 *
 * Through `driverCrudService`, as the panel's form is: the account, its profile
 * and its zones written together, the account created phone-verified. The
 * dashboard writes a driver's record directly — an operator editing a driver
 * *is* the approval — so this does not go through the driver's own review queue.
 *
 * - **No password ever leaves.** `password` is always empty on export; it is a
 *   column so a new driver can be given one, and a blank cell on an existing
 *   row leaves theirs alone.
 * - **Documents and the photograph are files** and never import.
 * - `zones` is the zone ids, comma-separated — the active ones, which is what
 *   the form offers — and `zone_names` beside it is for reading. A blank cell
 *   leaves the driver's areas as they are.
 * - The bonus rule is a money term with its own screen behind `setting.update`;
 *   it is printed here and never imported.
 */
class DriverSheet extends Sheet
{
    /** Validated by the form as strings, which Excel may hand back as numbers. */
    private const STRINGS = [
        'name', 'phone', 'email', 'password', 'plate_number', 'vehicle_brand', 'vehicle_model',
        'vehicle_year', 'vehicle_color', 'license_number', 'license_type', 'notes',
    ];

    /** @var array<int, string>|null */
    private ?array $cityNames = null;

    public function key(): string
    {
        return 'driver';
    }

    public function title(): string
    {
        return 'drivers';
    }

    public function query(): Builder
    {
        return Driver::query()->with(['profile.bonusRule', 'zones']);
    }

    public function searchColumns(): array
    {
        return ['name', 'phone', 'email', 'profile.vehicle_type', 'profile.plate_number', 'zones.name'];
    }

    public function columns(): array
    {
        $profile = fn (string $field) => fn (Driver $driver) => $driver->profile?->{$field};
        $date = fn (string $field) => fn (Driver $driver) => $driver->profile?->{$field}?->format('Y-m-d');

        return [
            Column::make('id'),
            Column::make('name'),
            Column::make('phone'),
            Column::make('email'),
            // Never the stored hash — always blank on the way out.
            Column::make('password')->value(fn () => null),
            Column::make('status'),

            // The form's own reading of a stored value: «Motorcycle», typed into
            // the free-text field this used to be, is the `motorcycle` case.
            Column::make('vehicle_type')->value(function (Driver $driver) {
                $stored = $driver->profile?->vehicle_type;

                return VehicleType::parse($stored)->value ?? $stored;
            }),
            Column::make('plate_number')->value($profile('plate_number')),
            Column::make('vehicle_brand')->value($profile('vehicle_brand')),
            Column::make('vehicle_model')->value($profile('vehicle_model')),
            Column::make('vehicle_year')->value($profile('vehicle_year')),
            Column::make('vehicle_color')->value($profile('vehicle_color')),
            Column::make('license_number')->value($profile('license_number')),
            Column::make('license_type')->value($profile('license_type')),
            Column::make('license_issued_at')->value($date('license_issued_at')),
            Column::make('license_expiry')->value($date('license_expiry')),
            Column::make('vehicle_registration_expiry')->value($date('vehicle_registration_expiry')),
            Column::make('vehicle_insurance_expiry')->value($date('vehicle_insurance_expiry')),
            Column::make('vehicle_inspection_expiry')->value($date('vehicle_inspection_expiry')),

            Column::make('max_concurrent_orders')->value($profile('max_concurrent_orders')),
            Column::make('city_id')->value($profile('city_id')),
            Column::readOnly('city', fn (Driver $driver) => $this->cityName($driver->profile?->city_id)),
            // `H:i`, which is what the form's rule accepts — the column stores seconds.
            Column::make('shift_start')->value(fn (Driver $driver) => self::clock($driver->profile?->shift_start)),
            Column::make('shift_end')->value(fn (Driver $driver) => self::clock($driver->profile?->shift_end)),
            Column::make('is_available')->value(fn (Driver $driver) => (bool) $driver->profile?->is_available),
            Column::make('notes')->value($profile('notes')),

            Column::make('zones')->value(
                fn (Driver $driver) => $driver->zones->where('status', 'active')->pluck('id')->implode(',') ?: null
            ),
            Column::readOnly('zone_names', fn (Driver $driver) => $driver->zones
                ->map(fn ($zone) => getLocalizedValueDashboard($zone, 'name'))
                ->implode(', ') ?: null),
            Column::readOnly('bonus_rule', fn (Driver $driver) => $driver->profile?->bonusRule
                ? getLocalizedValueDashboard($driver->profile->bonusRule, 'name')
                : null),
        ];
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return DriverRequest::class;
    }

    public function input(array $cells, ?Model $existing = null): array
    {
        $input = parent::input($cells, $existing);

        foreach (self::STRINGS as $field) {
            if (isset($input[$field]) && (is_int($input[$field]) || is_float($input[$field]))) {
                $input[$field] = (string) $input[$field];
            }
        }

        // One cell; the form's box and its repeat.
        if (isset($input['password'])) {
            $input['password_confirmation'] = $input['password'];
        }

        // Typed by hand, «Motorcycle» or «Tuk-tuk» is the case it names.
        if (isset($input['vehicle_type'])) {
            $input['vehicle_type'] = VehicleType::parse((string) $input['vehicle_type'])->value ?? $input['vehicle_type'];
        }

        foreach (['shift_start', 'shift_end'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = self::clock($input[$field]) ?? $input[$field];
            }
        }

        if (isset($input['zones'])) {
            $input['zones'] = array_values(array_filter(
                preg_split('/[\s,;]+/', (string) $input['zones']) ?: [],
                fn ($id) => $id !== ''
            ));
        }

        // The form posts the shift as a pair and checks the end against the
        // start; a row changing one is checked against the other as stored.
        if ($existing) {
            /** @var Driver $existing */
            $profile = $existing->profile;

            foreach ([['shift_start', 'shift_end'], ['shift_end', 'shift_start']] as [$given, $missing]) {
                if (isset($input[$given]) && ! isset($input[$missing]) && self::clock($profile?->{$missing}) !== null) {
                    $input[$missing] = self::clock($profile->{$missing});
                }
            }
        }

        return $input;
    }

    public function create(array $validated): void
    {
        app(driverCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        /** @var Driver $row */
        app(driverCrudService::class)->updateRecord($validated + [
            'id' => $row->getKey(),
            // The service reads an absent switch as «off», because that is what
            // an unchecked checkbox is. A blank cell keeps what the driver had.
            'is_available' => (bool) $row->profile?->is_available,
        ]);
    }

    /**
     * `08:00`, from `08:00:00`, a date-time Excel made of it, or a time cell.
     */
    private static function clock(mixed $value): ?string
    {
        if ($value instanceof \DateInterval) {
            return sprintf('%02d:%02d', $value->h, $value->i);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        if (is_string($value) && preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $m)) {
            return sprintf('%02d:%s', (int) $m[1], $m[2]);
        }

        return null;
    }

    private function cityName(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $this->cityNames ??= City::query()->get(['id', 'name'])
            ->mapWithKeys(fn (City $city) => [$city->id => getLocalizedValueDashboard($city, 'name')])
            ->all();

        return $this->cityNames[(int) $id] ?? null;
    }
}
