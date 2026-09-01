<?php

namespace App\Modules\Banner\Services;

use App\Modules\Banner\Enums\BannerTarget;
use App\Modules\Banner\Repositories\BannerRepository;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Service\Models\Service;
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
        $data = $this->normaliseTarget($data);
        $data['image'] = uploadOrUpdateImage($data['image'] ?? null, 'images/banners/image');
        $data['name'] = json_encode($data['name'], JSON_UNESCAPED_UNICODE);
        $data['description'] = json_encode($data['description'], JSON_UNESCAPED_UNICODE);

        return DB::transaction(fn () => $this->bannerRepository->create($data));
    }

    public function updateBanner(array $data)
    {
        $data = $this->normaliseTarget($data);

        // The filter below drops nulls, so `normaliseTarget` clears a target with
        // an empty string. Without that, switching a banner back to "no action"
        // would leave the old service id behind in the column.
        $filteredData = array_filter($data, fn ($v) => ! is_null($v));

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
        $data = [
            // The target form needs something to choose from. Active only: a
            // banner pointing at a disabled service is a button that goes nowhere.
            'targetTypes' => BannerTarget::cases(),
            'services' => Service::where('status', 'active')->get(['id', 'name']),
            'coupons' => Coupon::where('status', 'active')->get(['id', 'code']),
        ];

        if ($id) {
            $data['row'] = $this->bannerRepository->find($id);
        }

        return $data;
    }

    /**
     * Keep the target pair coherent before it reaches the database.
     *
     * `none` must not keep a stale value, and a missing kind means none — a row
     * with a value but no kind is unreadable by the app.
     */
    private function normaliseTarget(array $data): array
    {
        $type = BannerTarget::tryFrom((string) ($data['target_type'] ?? '')) ?? BannerTarget::None;

        $data['target_type'] = $type->value;
        // Empty string, not null: the update path filters nulls out.
        $data['target_value'] = $type->needsValue() ? ($data['target_value'] ?? '') : '';

        return $data;
    }
}
