<?php

namespace App\Services\user;

use App\Models\User;
use App\Models\UserRole;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class userCrudService
{
    protected $responseService;

    public function __construct(ResponseService $responseService)
    {
        $this->responseService = $responseService;
    }
    public function addNew($request)
    {
        $user = DB::transaction(function () use ($request) {
            $request['image_profile'] = uploadOrUpdateImage($request['image_profile'] ?? null, 'images/users/image');
            $user = User::create($request);
            return $user;
        });

        return $user;
    }



    public function updateRecord($request)
    {
        $filteredRequest = array_filter($request, function ($value) {
            return !is_null($value);
        });

        $user = DB::transaction(function () use ($filteredRequest) {
            $existingUser = User::findOrFail($filteredRequest['id']);

            if (isset($filteredRequest['image_profile'])) {
                $existingPath = $existingUser?->image_profile;
                $filteredRequest['image_profile'] = uploadOrUpdateImage($filteredRequest['image_profile'], 'images/users/image', $existingPath);
            }

            $existingUser->update($filteredRequest);
            return $existingUser;
        });

        return $user;
    }

    public function deleteRecord($id)
    {
        DB::transaction(function () use ($id) {
            $user = User::findOrFail($id);
            $user->delete();
        });

        return true;
    }


    public function shredData($id = null)
    {
        $data = [];
        if ($id != null) {
            $data['row'] = User::findOrFail($id);
        }
        return $data;
    }
    public function toggleStatus($id, $status)
    {
        $user = User::findOrFail($id);
        return $this->responseService->toggleStatus($user, $status);
    }

}
