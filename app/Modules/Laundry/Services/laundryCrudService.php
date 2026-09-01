<?php

namespace App\Modules\Laundry\Services;

use App\Models\Role;
use App\Modules\City\Models\City;
use App\Modules\Laundry\Repositories\LaundryRepository;
use App\Modules\User\Models\User;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class laundryCrudService
{
    protected $laundryRepository;

    protected $responseService;

    public function __construct(LaundryRepository $laundryRepository, ResponseService $responseService)
    {
        $this->laundryRepository = $laundryRepository;
        $this->responseService = $responseService;
    }

    /**
     * Creates the laundry and its owner account together.
     *
     * A laundry with no way to sign in is useless, so the two are one atomic
     * operation — if the owner cannot be created the laundry is rolled back too.
     */
    public function addNew(array $request)
    {
        return DB::transaction(function () use ($request) {
            $laundry = $this->laundryRepository->create([
                'name' => json_encode($request['name'], JSON_UNESCAPED_UNICODE),
                'phone' => $request['phone'],
                'email' => $request['email'] ?? null,
                'address' => $request['address'] ?? null,
                'city_id' => $request['city_id'] ?? null,
                'lat' => $request['lat'] ?? null,
                'lng' => $request['lng'] ?? null,
                'logo' => uploadOrUpdateImage($request['logo'] ?? null, 'images/laundries/logo'),
                'status' => $request['status'],
            ]);

            $ownerRole = Role::where('slug', 'laundry_owner')->firstOrFail();

            User::create([
                'name' => $request['owner_name'],
                'email' => $request['owner_email'],
                'phone' => $request['owner_phone'],
                'password' => $request['owner_password'],
                'role_id' => $ownerRole->id,
                'laundry_id' => $laundry->id,
                'status' => 'active',
            ]);

            return $laundry;
        });
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(function () use ($request) {
            $laundry = $this->laundryRepository->findById($request['id']);

            $data = array_filter([
                'phone' => $request['phone'] ?? null,
                'email' => $request['email'] ?? null,
                'address' => $request['address'] ?? null,
                'city_id' => $request['city_id'] ?? null,
                'status' => $request['status'] ?? null,
            ], fn ($value) => ! is_null($value));

            // Outside the array_filter: a pin must be clearable, and filtering
            // would drop an emptied value so it could be set but never unset.
            foreach (['lat', 'lng'] as $coordinate) {
                if (array_key_exists($coordinate, $request)) {
                    $data[$coordinate] = $request[$coordinate] === '' ? null : $request[$coordinate];
                }
            }

            if (isset($request['name'])) {
                $data['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
            }

            if (isset($request['logo'])) {
                $data['logo'] = uploadOrUpdateImage($request['logo'], 'images/laundries/logo', $laundry->logo);
            }

            return $this->laundryRepository->update($request['id'], $data);
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(function () use ($id) {
            $laundry = $this->laundryRepository->findById($id);

            // users.laundry_id is nullOnDelete, so staff accounts survive as
            // orphans. Deactivate them so an orphan cannot still sign in.
            $laundry->users()->update(['status' => 'inactive']);

            DeleteImage($laundry->logo);

            return $this->laundryRepository->delete($id);
        });
    }

    /**
     * The universal view-data assembler. Returns the list under `laundries` and,
     * when an id is given, the single record under `row`.
     */
    public function shredData($id = null)
    {
        $data = [
            'laundries' => $this->laundryRepository->getAllPaginated(),
            'cities' => City::where('status', 'active')->get(),
        ];

        if ($id) {
            $data['row'] = $this->laundryRepository->findById($id);
        }

        return $data;
    }

    public function search($query, $perPage = 10)
    {
        return $this->laundryRepository->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        $laundry = $this->laundryRepository->findById($id);

        return $this->responseService->toggleStatus($laundry, $status);
    }
}
