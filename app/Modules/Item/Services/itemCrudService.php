<?php

namespace App\Modules\Item\Services;

use App\Modules\Item\Repositories\ItemRepository;
use App\Modules\ItemCategory\Repositories\ItemCategoryRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class itemCrudService
{
    protected $itemRepository;

    protected $itemCategoryRepository;

    protected $responseService;

    public function __construct(
        ItemRepository $itemRepository,
        ItemCategoryRepository $itemCategoryRepository,
        ResponseService $responseService
    ) {
        $this->itemRepository = $itemRepository;
        $this->itemCategoryRepository = $itemCategoryRepository;
        $this->responseService = $responseService;
    }

    public function addNew(array $request)
    {
        return DB::transaction(fn () => $this->itemRepository->create($this->payload($request)));
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(function () use ($request) {
            $item = $this->itemRepository->findById($request['id']);

            return $this->itemRepository->update($request['id'], $this->payload($request, $item->image));
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(function () use ($id) {
            $item = $this->itemRepository->findById($id);
            DeleteImage($item->image);

            // item_prices rows cascade at the database level.
            return $this->itemRepository->delete($id);
        });
    }

    public function shredData($id = null)
    {
        $data = [
            'items' => $this->itemRepository->getAllPaginated(),
            'itemCategories' => $this->itemCategoryRepository->allActive(),
        ];

        if ($id) {
            $data['row'] = $this->itemRepository->findById($id);
        }

        return $data;
    }

    public function search($query, $perPage = 10)
    {
        return $this->itemRepository->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        return $this->responseService->toggleStatus($this->itemRepository->findById($id), $status);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function payload(array $request, ?string $existingImage = null): array
    {
        $data = array_filter([
            'item_category_id' => $request['item_category_id'] ?? null,
            'sort_order' => $request['sort_order'] ?? null,
            'status' => $request['status'] ?? null,
        ], fn ($value) => ! is_null($value));

        if (isset($request['name'])) {
            $data['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($request['image'])) {
            $data['image'] = uploadOrUpdateImage($request['image'], 'images/items/image', $existingImage);
        }

        return $data;
    }
}
