<?php

namespace App\Modules\User\Services;

use App\Modules\User\Repositories\UserRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class userCrudService
{
    protected $userRepository;
    protected $responseService;

    public function __construct(UserRepository $userRepository, ResponseService $responseService)
    {
        $this->userRepository = $userRepository;
        $this->responseService = $responseService;
    }

    public function getAllUsers($perPage = 10)
    {
        return $this->userRepository->getAll($perPage);
    }

    public function searchUsers($query, $perPage = 10)
    {
        return $this->userRepository->search($query, $perPage);
    }

    public function getUser($id)
    {
        return $this->userRepository->find($id);
    }

    public function addUser(array $data)
    {
        $data['image_profile'] = uploadOrUpdateImage($data['image_profile'] ?? null, 'images/users/image');
        return DB::transaction(function () use ($data) {
            return $this->userRepository->create($data);
        });
    }

    public function updateUser(array $data)
    {
        $filteredData = array_filter($data, fn($v) => !is_null($v));
        return DB::transaction(function () use ($filteredData) {
            if (isset($filteredData['image_profile'])) {
                $existingUser = $this->userRepository->find($filteredData['id']);
                $existingPath = $existingUser->image_profile;
                $filteredData['image_profile'] = uploadOrUpdateImage($filteredData['image_profile'], 'images/users/image', $existingPath);
            }
            return $this->userRepository->update($filteredData['id'], $filteredData);
        });
    }

    public function deleteUser($id)
    {
        return DB::transaction(fn() => $this->userRepository->delete($id));
    }

    public function toggleStatus($id, $status)
    {
        $user = $this->userRepository->find($id);
        return $this->responseService->toggleStatus($user, $status);
    }
}
