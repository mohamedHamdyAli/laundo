<?php

namespace App\Modules\City\Services;

use App\Modules\City\Repositories\CityRepository;
use App\Modules\Country\Models\Country;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class cityCrudService
{
    protected $cityRepository;
    protected $responseService;

    public function __construct(CityRepository $cityRepository, ResponseService $responseService)
    {
        $this->cityRepository = $cityRepository;
        $this->responseService = $responseService;
    }
    public function getAllPaginated($perPage)
    {
        return $this->cityRepository->getAllPaginated($perPage);
    }

    public function search($query, $perPage)
    {
        return $this->cityRepository->search($query, $perPage);
    }

    public function addNew($request)
    {
        return DB::transaction(function () use ($request) {
            $request['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
            return $this->cityRepository->create($request);
        });
    }

    public function updateRecord($request)
    {
        return DB::transaction(function () use ($request) {
            $request['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
            return $this->cityRepository->update($request['id'], $request);
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(function () use ($id) {
            return $this->cityRepository->delete($id);
        });
    }

    public function shredData($id = null)
    {
        $data = [];
        if ($id) {
            $data['row'] = $this->cityRepository->findById($id);
        }
        $data['countries'] = Country::all();
        return $data;
    }

    public function toggleStatus($id, $status)
    {
        $city = $this->cityRepository->findById($id);
        return $this->responseService->toggleStatus($city, $status);
    }
}
