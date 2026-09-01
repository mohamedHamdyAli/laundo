<?php

namespace App\Modules\Category\Repositories;

use App\Modules\Category\Models\Category;

class CategoryRepository
{
    public function getAllPaginated($perPage = 10)
    {
        return Category::paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return Category::search($query, ['name'])->paginate($perPage);
    }

    public function findById($id)
    {
        return Category::findOrFail($id);
    }

    public function create(array $data)
    {
        return Category::create($data);
    }

    public function update($id, array $data)
    {
        $city = Category::findOrFail($id);
        $city->update($data);

        return $city;
    }

    public function delete($id)
    {
        $city = Category::findOrFail($id);

        return $city->delete();
    }

    public function getSubCategories($id, $perPage = 10)
    {
        $parentCategory = Category::with('children')->findOrFail($id);
        $subcategories = $parentCategory->children()->paginate($perPage);

        return compact('parentCategory', 'subcategories');
    }

    public function getAllWithChildren($perPage = 10)
    {
        return Category::with('children')->whereNull('parent_id')->paginate($perPage);
    }
}
