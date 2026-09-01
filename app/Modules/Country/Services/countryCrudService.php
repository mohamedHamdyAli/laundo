<?php

namespace App\Modules\Country\Services;

use App\Modules\Country\Repositories\CountryRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class countryCrudService
{
    protected $countryRepository;

    protected $responseService;

    public function __construct(CountryRepository $countryRepository, ResponseService $responseService)
    {
        $this->countryRepository = $countryRepository;
        $this->responseService = $responseService;
    }

    public function getAllPaginated($perPage)
    {
        return $this->countryRepository->getAll($perPage);
    }

    public function search($query, $perPage)
    {
        return $this->countryRepository->search($query, $perPage);
    }

    public function addNew(array $data)
    {
        return DB::transaction(fn () => $this->countryRepository->create($data));
    }

    public function updateRecord(array $data)
    {
        return DB::transaction(fn () => $this->countryRepository->update($data['id'], $data));
    }

    public function deleteRecord($id)
    {
        return DB::transaction(fn () => $this->countryRepository->delete($id));
    }

    public function shredData($id = null)
    {
        return $id ? ['row' => $this->countryRepository->findById($id)] : [];
    }

    public function toggleStatus($id, $status)
    {
        $country = $this->countryRepository->findById($id);

        return $this->responseService->toggleStatus($country, $status);
    }
}
