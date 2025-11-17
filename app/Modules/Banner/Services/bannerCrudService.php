<?php

namespace App\Modules\Banner\Services;

use App\Modules\Banner\Repositories\BannerRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class bannerCrudService
{
    protected $bannerRepository;
    protected $responseService;

    public function __construct(BannerRepository $bannerRepository, ResponseService $responseService)
    {
        $this->bannerRepository = $bannerRepository;
        $this->responseService = $responseService;
    }

    public function getAllBanners($perPage = 10)
    {
        return $this->bannerRepository->getAll($perPage);
    }

    public function searchBanners($query, $perPage = 10)
    {
        return $this->bannerRepository->search($query, $perPage);
    }

    public function addBanner(array $data)
    {
        $data['image'] = uploadOrUpdateImage($data['image'] ?? null, 'images/banners/image');
        $data['name'] = json_encode($data['name'], JSON_UNESCAPED_UNICODE);
        $data['description'] = json_encode($data['description'], JSON_UNESCAPED_UNICODE);

        return DB::transaction(fn () => $this->bannerRepository->create($data));
    }

    public function updateBanner(array $data)
    {
        $filteredData = array_filter($data, fn ($v) => !is_null($v));

        return DB::transaction(function () use ($filteredData) {
            $existingBanner = $this->bannerRepository->find($filteredData['id']);

            if (isset($filteredData['image'])) {
                $existingPath = $existingBanner->image;
                $filteredData['image'] = uploadOrUpdateImage($filteredData['image'], 'images/banners/image', $existingPath);
            }

            $filteredData['name'] = json_encode($filteredData['name'], JSON_UNESCAPED_UNICODE);
            $filteredData['description'] = json_encode($filteredData['description'], JSON_UNESCAPED_UNICODE);

            return $this->bannerRepository->update($filteredData['id'], $filteredData);
        });
    }

    public function deleteBanner($id)
    {
        return DB::transaction(fn () => $this->bannerRepository->delete($id));
    }

    public function toggleStatus($id, $status)
    {
        $banner = $this->bannerRepository->find($id);
        return $this->responseService->toggleStatus($banner, $status);
    }
    public function shredData($id = null)
    {
        $data = [];
        if ($id) {
            $data['row'] = $this->bannerRepository->find($id);
        }
        return $data;
    }
}
