<?php

namespace App\Services\category;

use App\Models\Category;
use App\Models\User;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class categoryCrudService
{
    protected $responseService;

    public function __construct(ResponseService $responseService)
    {
        $this->responseService = $responseService;
    }
    public function addNew($request)
    {
        $category = DB::transaction(function () use ($request) {

            $request['image'] = uploadOrUpdateImage($request['image'] ?? null, 'images/categories/image');
            $request['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
            $category = Category::create($request);
            return $category;
        });

        return $category;
    }

    public function updateRecord($request)
    {
        $filteredRequest = array_filter($request, function ($value) {
            return !is_null($value);
        });

        $category = DB::transaction(function () use ($filteredRequest) {

            $existingCategory = Category::findOrFail($filteredRequest['id']);

            if (isset($filteredRequest['image'])) {
                $existingUser = User::find($filteredRequest['id']);
                $existingPath = $existingUser?->image;
                $filteredRequest['image'] = uploadOrUpdateImage($filteredRequest['image'], 'images/categories/image', $existingPath);
            }
            $filteredRequest['name'] = json_encode($filteredRequest['name'], JSON_UNESCAPED_UNICODE);

            $existingCategory->update($filteredRequest);

            return $existingCategory;
        });

        return $category;
    }
    public function showSubCategories($id)
    {
        $parentCategory = Category::with('children.children')->findOrFail($id);
        $subcategories = $parentCategory->children()->paginate(10);

        return compact('parentCategory', 'subcategories');
    }

    public function deleteRecord($id)
    {
        DB::transaction(function () use ($id) {
            Category::whereParentId($id)->update(['parent_id' => null]);
            $category = Category::findOrFail($id);
            DeleteImage($category->image);
            $category->delete();
        });

        return true;
    }


    public function shredData($id = null)
    {
        $data = [];
        if ($id != null) {
            $data['row'] = Category::findOrFail($id);
        }
        $data['Categories'] = Category::with('children')->whereNull('parent_id')->get();
        return $data;
    }

    public function toggleStatus($id, $status)
    {
        $category = Category::findOrFail($id);
        return $this->responseService->toggleStatus($category, $status);
    }
}
