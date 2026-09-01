<?php

namespace App\Modules\Driver\Services;

use App\Models\Role;
use App\Modules\City\Models\City;
use App\Modules\Driver\Repositories\DriverRepository;
use App\Modules\Zone\Repositories\ZoneRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class driverCrudService
{
    protected $driverRepository;

    protected $zoneRepository;

    protected $responseService;

    public function __construct(
        DriverRepository $driverRepository,
        ZoneRepository $zoneRepository,
        ResponseService $responseService
    ) {
        $this->driverRepository = $driverRepository;
        $this->zoneRepository = $zoneRepository;
        $this->responseService = $responseService;
    }

    /**
     * Creates the account, its profile and its zones together.
     *
     * The account is created already phone-verified: it was set up by an admin
     * who has the driver in front of them, so putting it through the customer OTP
     * flow would only lock the driver out of an account they never registered.
     */
    public function addNew(array $request)
    {
        return DB::transaction(function () use ($request) {
            $driver = $this->driverRepository->create([
                'name' => $request['name'],
                'phone' => $request['phone'],
                'email' => $request['email'] ?? null,
                'password' => $request['password'],
                'status' => $request['status'],
                'role_id' => Role::where('slug', Role::DRIVER)->value('id'),
                'image_profile' => uploadOrUpdateImage($request['image_profile'] ?? null, 'images/drivers/image'),
                'phone_verified_at' => now(),
            ]);

            $driver->profile()->create($this->profilePayload($request));
            $driver->zones()->sync($request['zones'] ?? []);

            return $driver;
        });
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(function () use ($request) {
            $driver = $this->driverRepository->findById($request['id']);

            $account = array_filter([
                'name' => $request['name'] ?? null,
                'phone' => $request['phone'] ?? null,
                'email' => $request['email'] ?? null,
                'status' => $request['status'] ?? null,
            ], fn ($value) => ! is_null($value));

            if (! empty($request['password'])) {
                $account['password'] = $request['password'];
            }

            if (isset($request['image_profile'])) {
                $account['image_profile'] = uploadOrUpdateImage(
                    $request['image_profile'], 'images/drivers/image', $driver->image_profile
                );
            }

            $driver->update($account);

            // updateOrCreate rather than update: a driver created before this
            // module existed would have no profile row.
            $driver->profile()->updateOrCreate(
                ['user_id' => $driver->id],
                $this->profilePayload($request, $driver->profile)
            );

            if (array_key_exists('zones', $request)) {
                $driver->zones()->sync($request['zones'] ?? []);
            }

            return $driver;
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(function () use ($id) {
            $driver = $this->driverRepository->findById($id);

            // Soft-deleted along with every other user, so task history survives.
            $driver->tokens()->delete();

            return $this->driverRepository->delete($id);
        });
    }

    public function shredData($id = null)
    {
        $data = [
            'drivers' => $this->driverRepository->getAllPaginated(),
            'cities' => City::where('status', 'active')->get(),
            'zonesByCity' => $this->zoneRepository->allActive()->groupBy(
                fn ($zone) => $zone->city ? getLocalizedValueDashboard($zone->city, 'name') : '-'
            ),
        ];

        if ($id) {
            $data['row'] = $this->driverRepository->findById($id);
        }

        return $data;
    }

    public function search($query, $perPage = 15)
    {
        return $this->driverRepository->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        return $this->responseService->toggleStatus($this->driverRepository->findById($id), $status);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function profilePayload(array $request, $existing = null): array
    {
        $data = array_filter([
            'vehicle_type' => $request['vehicle_type'] ?? null,
            'plate_number' => $request['plate_number'] ?? null,
            'license_number' => $request['license_number'] ?? null,
            'license_expiry' => $request['license_expiry'] ?? null,
            'vehicle_registration_expiry' => $request['vehicle_registration_expiry'] ?? null,
            'shift_start' => $request['shift_start'] ?? null,
            'shift_end' => $request['shift_end'] ?? null,
            'notes' => $request['notes'] ?? null,
        ], fn ($value) => ! is_null($value));

        // Outside the array_filter, because both must be clearable back to null:
        // a cap that can be set but never removed is a driver permanently
        // throttled by a typo.
        foreach (['max_concurrent_orders', 'city_id'] as $field) {
            if (array_key_exists($field, $request)) {
                $data[$field] = $request[$field] === '' ? null : $request[$field];
            }
        }

        // Handled apart from the rest: an unchecked checkbox is absent from the
        // payload entirely, and array_filter would drop a false, so the switch
        // could never be turned off.
        $data['is_available'] = (bool) ($request['is_available'] ?? false);

        foreach (['license_image', 'vehicle_registration_image', 'national_id_image'] as $field) {
            if (isset($request[$field])) {
                $data[$field] = uploadOrUpdateImage(
                    $request[$field], 'images/drivers/documents', $existing?->{$field}
                );
            }
        }

        return $data;
    }
}
