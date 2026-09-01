<?php

namespace App\Modules\ItemCategory\Repositories;

use App\Modules\ItemCategory\Models\ItemCategory;

class ItemCategoryRepository
{
    public function getAllPaginated($perPage = 10)
    {
        return ItemCategory::withCount('items')->orderBy('sort_order')->orderBy('id')->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return ItemCategory::withCount('items')->search($query, ['name'])->orderBy('sort_order')->paginate($perPage);
    }

    public function findById($id)
    {
        return ItemCategory::findOrFail($id);
    }

    public function allActive()
    {
        return ItemCategory::where('status', 'active')->orderBy('sort_order')->get();
    }

    /**
     * Active categories with their active items — the rows of the price grid and
     * the shape the public catalogue endpoint returns.
     */
    public function activeWithItems()
    {
        return ItemCategory::where('status', 'active')
            ->with(['items' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('sort_order')
            ->get();
    }

    public function create(array $data)
    {
        return ItemCategory::create($data);
    }

    public function update($id, array $data)
    {
        $category = $this->findById($id);
        $category->update($data);

        return $category;
    }

    public function delete($id)
    {
        return $this->findById($id)->delete();
    }
}
