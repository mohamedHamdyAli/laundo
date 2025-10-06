<?php

namespace App\Modules\Intro\Services;

use App\Modules\Intro\Repositories\IntroRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class introCrudService
{
    protected $introRepository;
    protected $responseService;

    public function __construct(IntroRepository $introRepository, ResponseService $responseService)
    {
        $this->introRepository = $introRepository;
        $this->responseService = $responseService;
    }

    public function getAllIntros($perPage = 10)
    {
        return $this->introRepository->getAll($perPage);
    }

    public function searchIntros($query, $perPage = 10)
    {
        return $this->introRepository->search($query, $perPage);
    }
    public function addIntro(array $data)
    {
        $data['image'] = uploadOrUpdateImage($data['image'] ?? null, 'images/intros/image');
        $data['title'] = json_encode($data['title'], JSON_UNESCAPED_UNICODE);
        $data['description'] = json_encode($data['description'], JSON_UNESCAPED_UNICODE);

        return DB::transaction(fn() => $this->introRepository->create($data));
    }

    public function updateIntro(array $data)
    {
        $filteredData = array_filter($data, fn($v) => !is_null($v));

        return DB::transaction(function () use ($filteredData) {
            $existingIntro = $this->introRepository->find($filteredData['id']);

            if (isset($filteredData['image'])) {
                $existingPath = $existingIntro->image;
                $filteredData['image'] = uploadOrUpdateImage($filteredData['image'], 'images/intros/image', $existingPath);
            }

            $filteredData['title'] = json_encode($filteredData['title'], JSON_UNESCAPED_UNICODE);
            $filteredData['description'] = json_encode($filteredData['description'], JSON_UNESCAPED_UNICODE);

            return $this->introRepository->update($filteredData['id'], $filteredData);
        });
    }

    public function deleteIntro($id)
    {
        return DB::transaction(fn() => $this->introRepository->delete($id));
    }

    public function toggleStatus($id, $status)
    {
        $intro = $this->introRepository->find($id);
        return $this->responseService->toggleStatus($intro, $status);
    }
    public function shredData($id = null)
    {
        $data = [];
        if ($id) {
            $data['row'] = $this->introRepository->find($id);
        }
        return $data;
    }
}
