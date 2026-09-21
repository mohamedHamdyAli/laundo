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
            // Keyed by city id, not by the city's translated name: the form
            // hides the groups that are not the chosen city, and matching a
            // <select> value against a display string breaks the moment the
            // panel language changes.
            'zonesByCity' => $this->zoneRepository->allActive()->groupBy(
                fn ($zone) => (string) ($zone->city_id ?? '')
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
        $data = [];

        /*
         * Written when the request carried the key, whatever it carried.
         *
         * This used to be an `array_filter` that dropped nulls, with two fields
         * lifted out of it because they «must be clearable back to null». That
         * exception was the rule: **every** one of these is optional, so every
         * one of them can be emptied. An operator correcting a licence number
         * typed into the wrong driver could set it and never unset it — the form
         * posted a blank, the filter dropped it, and the screen came back showing
         * the old value as though the save had not happened.
         *
         * `array_key_exists`, not `isset`: a cleared field arrives as an empty
         * string (or null once ConvertEmptyStringsToNull has run) and `isset`
         * would call that «absent» and skip it, which is the whole bug.
         */
        $fields = [
            'vehicle_type', 'plate_number', 'vehicle_brand', 'vehicle_model',
            'vehicle_year', 'vehicle_color',
            'license_number', 'license_type', 'license_issued_at', 'license_expiry',
            'vehicle_registration_expiry', 'vehicle_insurance_expiry',
            'vehicle_inspection_expiry',
            'shift_start', 'shift_end', 'notes',
            'max_concurrent_orders', 'city_id',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $request)) {
                $data[$field] = $request[$field] === '' ? null : $request[$field];
            }
        }

        // Handled apart from the rest: an unchecked checkbox is absent from the
        // payload entirely, and array_filter would drop a false, so the switch
        // could never be turned off.
        $data['is_available'] = (bool) ($request['is_available'] ?? false);

        foreach ([
            'license_image', 'vehicle_registration_image', 'vehicle_insurance_image',
            'vehicle_inspection_image', 'national_id_image', 'other_document_image',
        ] as $field) {
            if (isset($request[$field])) {
                $data[$field] = uploadOrUpdateImage(
                    $request[$field], 'images/drivers/documents', $existing?->{$field}
                );
            }
        }

        return $data;
    }
}
