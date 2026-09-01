<?php

namespace App\Modules\Zone\Services;

use App\Modules\City\Models\City;
use App\Modules\Zone\Repositories\ZoneRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class zoneCrudService
{
    protected $zoneRepository;

    protected $responseService;

    public function __construct(ZoneRepository $zoneRepository, ResponseService $responseService)
    {
        $this->zoneRepository = $zoneRepository;
        $this->responseService = $responseService;
    }

    public function addNew(array $request)
    {
        return DB::transaction(fn () => $this->zoneRepository->create($this->payload($request)));
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(fn () => $this->zoneRepository->update($request['id'], $this->payload($request)));
    }

    public function deleteRecord($id)
    {
        // laundry_zones rows cascade at the database level.
        return DB::transaction(fn () => $this->zoneRepository->delete($id));
    }

    public function shredData($id = null)
    {
        $data = [
            'zones' => $this->zoneRepository->getAllPaginated(),
            'cities' => City::where('status', 'active')->get(),
        ];

        if ($id) {
            $data['row'] = $this->zoneRepository->findById($id);
        }

        return $data;
    }

    public function search($query, $perPage = 15)
    {
        return $this->zoneRepository->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        return $this->responseService->toggleStatus($this->zoneRepository->findById($id), $status);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function payload(array $request): array
    {
        $data = array_filter([
            'city_id' => $request['city_id'] ?? null,
            'sort_order' => $request['sort_order'] ?? null,
            'status' => $request['status'] ?? null,
        ], fn ($value) => ! is_null($value));

        // Kept out of the array_filter above: these are meant to be clearable
        // back to null, and array_filter would silently drop the emptied value
        // so a rate could be set but never unset.
        foreach (['price_per_km', 'min_delivery_fee'] as $rate) {
            if (array_key_exists($rate, $request)) {
                $data[$rate] = $request[$rate] === '' ? null : $request[$rate];
            }
        }

        if (isset($request['name'])) {
            $data['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
        }

        return $data;
    }
}
