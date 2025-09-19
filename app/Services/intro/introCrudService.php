<?php

namespace App\Services\intro;

use App\Models\Intro;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class introCrudService
{
    protected $responseService;

    public function __construct(ResponseService $responseService)
    {
        $this->responseService = $responseService;
    }
    public function addNew($request)
    {
        $intro = DB::transaction(function () use ($request) {
            $request['image'] = uploadOrUpdateImage($request['image'] ?? null, 'images/intros/image');
            $request['title'] = json_encode($request['title'], JSON_UNESCAPED_UNICODE);
            $request['description'] = json_encode($request['description'], JSON_UNESCAPED_UNICODE);

            $intro = Intro::create($request);
            return $intro;
        });

        return $intro;
    }

    public function updateRecord($request)
    {
        $filteredRequest = array_filter($request, function ($value) {
            return !is_null($value);
        });

        $intro = DB::transaction(function () use ($filteredRequest) {
            $existingIntro = Intro::findOrFail($filteredRequest['id']);

            if (isset($filteredRequest['image'])) {
                $existingPath = $existingIntro?->image;
                $filteredRequest['image'] = uploadOrUpdateImage($filteredRequest['image'], 'images/intros/image', $existingPath);
            }

            $filteredRequest['title'] = json_encode($filteredRequest['title'], JSON_UNESCAPED_UNICODE);
            $filteredRequest['description'] = json_encode($filteredRequest['description'], JSON_UNESCAPED_UNICODE);
            $existingIntro->update($filteredRequest);


            return $existingIntro;
        });

        return $intro;
    }

    public function deleteRecord($id)
    {
        DB::transaction(function () use ($id) {
            $intro = Intro::findOrFail($id);
            DeleteImage($intro->image);

            $intro->delete();
        });

        return true;
    }


    public function shredData($id = null)
    {
        $data = [];
        if ($id != null) {
            $data['row'] = Intro::findOrFail($id);
        }
        return $data;
    }
    public function toggleStatus($id, $status)
    {
        $category = Intro::findOrFail($id);
        return $this->responseService->toggleStatus($category, $status);
    }
}
