<?php

namespace App\Modules\Moderator\Services;

use App\Models\Role;
use App\Modules\Moderator\Repositories\ModeratorRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class moderatorCrudService
{
    protected $moderatorRepository;
    protected $responseService;

    public function __construct(ModeratorRepository $moderatorRepository, ResponseService $responseService)
    {
        $this->moderatorRepository = $moderatorRepository;
        $this->responseService = $responseService;
    }

    public function getAllPaginated($perPage)
    {
        return $this->moderatorRepository->getAll($perPage);
    }

    public function search($query, $perPage)
    {
        return $this->moderatorRepository->search($query, $perPage);
    }

    public function addNew(array $data)
    {
        $data['image_profile'] = uploadOrUpdateImage($data['image_profile'] ?? null, 'images/moderators/image');

        return DB::transaction(fn () => $this->moderatorRepository->create($data));
    }

    public function updateRecord(array $data)
    {
        $filteredData = array_filter($data, fn ($value) => !is_null($value));

        return DB::transaction(function () use ($filteredData) {
            if (isset($filteredData['image_profile'])) {
                $existingModerator = $this->moderatorRepository->findById($filteredData['id']);
                $filteredData['image_profile'] = uploadOrUpdateImage(
                    $filteredData['image_profile'],
                    'images/moderators/image',
                    $existingModerator->image_profile
                );
            }

            return $this->moderatorRepository->update($filteredData['id'], $filteredData);
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(fn () => $this->moderatorRepository->delete($id));
    }

    public function shredData($id = null)
    {
        $data = [
            'roles' => Role::where('type', 'dashboard')->where('slug', '!=', 'super_admin')->get(),
        ];

        if ($id) {
            $data['row'] = $this->moderatorRepository->findById($id);
        }

        return $data;
    }

    public function toggleStatus($id, $status)
    {
        $moderator = $this->moderatorRepository->findById($id);
        return $this->responseService->toggleStatus($moderator, $status);
    }
}
