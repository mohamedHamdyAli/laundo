<?php

namespace App\Services\banner;

use App\Models\Banner;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class bannerCrudService
{
    protected $responseService;

    public function __construct(ResponseService $responseService)
    {
        $this->responseService = $responseService;
    }
    public function addNew($request)
    {
        $banner = DB::transaction(function () use ($request) {
            $request['image'] = uploadOrUpdateImage($request['image'] ?? null, 'images/banners/image');
            $request['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
            $request['description'] = json_encode($request['description'], JSON_UNESCAPED_UNICODE);

            $banner = Banner::create($request);
            return $banner;
        });

        return $banner;
    }

    public function updateRecord($request)
    {
        $filteredRequest = array_filter($request, function ($value) {
            return !is_null($value);
        });

        $banner = DB::transaction(function () use ($filteredRequest) {
            $existingBanner = Banner::findOrFail($filteredRequest['id']);

            if (isset($filteredRequest['image'])) {
                $existingPath = $existingBanner?->image;
                $filteredRequest['image'] = uploadOrUpdateImage($filteredRequest['image'], 'images/banner/image', $existingPath);
            }

            $filteredRequest['name'] = json_encode($filteredRequest['name'], JSON_UNESCAPED_UNICODE);
            $filteredRequest['description'] = json_encode($filteredRequest['description'], JSON_UNESCAPED_UNICODE);
            $existingBanner->update($filteredRequest);


            return $existingBanner;
        });

        return $banner;
    }

    public function deleteRecord($id)
    {
        DB::transaction(callback: function () use ($id) {
            $banner = Banner::findOrFail($id);
            DeleteImage($banner->image);

            $banner->delete();
        });

        return true;
    }


    public function shredData($id = null)
    {
        $data = [];
        if ($id != null) {
            $data['row'] = Banner::findOrFail($id);
        }
        return $data;
    }
    public function toggleStatus($id, $status)
    {
        $banner = Banner::findOrFail($id);
        return $this->responseService->toggleStatus($banner, $status);
    }
}
