<?php

namespace App\Modules\Category\Services;

use App\Modules\Category\Repositories\CategoryRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class categoryCrudService
{
    protected $categoryRepository;

    protected $responseService;

    public function __construct(CategoryRepository $categoryRepository, ResponseService $responseService)
    {
        $this->categoryRepository = $categoryRepository;
        $this->responseService = $responseService;
    }

    public function addNew(array $request)
    {
        return DB::transaction(function () use ($request) {
            $request['image'] = uploadOrUpdateImage($request['image'] ?? null, 'images/categories/image');
            $request['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);

            return $this->categoryRepository->create($request);
        });
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(function () use ($request) {
            $category = $this->categoryRepository->findById($request['id']);

            if (isset($request['image'])) {
                $existingPath = $category->image;
                $request['image'] = uploadOrUpdateImage($request['image'], 'images/categories/image', $existingPath);
            }

            $request['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);

            return $this->categoryRepository->update($request['id'], $request);
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(function () use ($id) {
            $category = $this->categoryRepository->findById($id);
            $category->children()->update(['parent_id' => null]);
            DeleteImage($category->image);

            return $this->categoryRepository->delete($id);
        });
    }

    public function showSubCategories($id, $perPage = 10)
    {
        return $this->categoryRepository->getSubCategories($id, $perPage);
    }

    /**
     * The repository has had a search() all along, but the controller called
     * shredData() instead, so typing in the search box re-rendered the full list
     * unchanged.
     */
    public function search($query, $perPage = 10)
    {
        return $this->categoryRepository->search($query, $perPage);
    }

    public function shredData($id = null)
    {
        $data = [];
        if ($id) {
            $data['row'] = $this->categoryRepository->findById($id);
        }
        $data['categories'] = $this->categoryRepository->getAllWithChildren();

        return $data;
    }

    public function toggleStatus($id, $status)
    {
        $category = $this->categoryRepository->findById($id);

        return $this->responseService->toggleStatus($category, $status);
    }
}
