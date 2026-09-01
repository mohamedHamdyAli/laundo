<?php

namespace App\Modules\ItemCategory\Services;

use App\Modules\ItemCategory\Repositories\ItemCategoryRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class itemCategoryCrudService
{
    protected $itemCategoryRepository;

    protected $responseService;

    public function __construct(ItemCategoryRepository $itemCategoryRepository, ResponseService $responseService)
    {
        $this->itemCategoryRepository = $itemCategoryRepository;
        $this->responseService = $responseService;
    }

    public function addNew(array $request)
    {
        return DB::transaction(fn () => $this->itemCategoryRepository->create($this->payload($request)));
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(function () use ($request) {
            $category = $this->itemCategoryRepository->findById($request['id']);

            return $this->itemCategoryRepository->update(
                $request['id'],
                $this->payload($request, $category->image)
            );
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(function () use ($id) {
            $category = $this->itemCategoryRepository->findById($id);
            DeleteImage($category->image);

            // items cascade on delete, and their prices cascade in turn.
            return $this->itemCategoryRepository->delete($id);
        });
    }

    public function shredData($id = null)
    {
        $data = ['itemCategories' => $this->itemCategoryRepository->getAllPaginated()];

        if ($id) {
            $data['row'] = $this->itemCategoryRepository->findById($id);
        }

        return $data;
    }

    public function search($query, $perPage = 10)
    {
        return $this->itemCategoryRepository->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        return $this->responseService->toggleStatus($this->itemCategoryRepository->findById($id), $status);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function payload(array $request, ?string $existingImage = null): array
    {
        $data = array_filter([
            'sort_order' => $request['sort_order'] ?? null,
            'status' => $request['status'] ?? null,
        ], fn ($value) => ! is_null($value));

        if (isset($request['name'])) {
            $data['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($request['image'])) {
            $data['image'] = uploadOrUpdateImage($request['image'], 'images/item-categories/image', $existingImage);
        }

        return $data;
    }
}
