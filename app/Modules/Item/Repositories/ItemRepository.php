<?php

namespace App\Modules\Item\Repositories;

use App\Modules\Item\Models\Item;

class ItemRepository
{
    public function getAllPaginated($perPage = 10)
    {
        return Item::with('category')->orderBy('item_category_id')->orderBy('sort_order')->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return Item::with('category')->search($query, ['name'])->orderBy('sort_order')->paginate($perPage);
    }

    public function findById($id)
    {
        return Item::with('category')->findOrFail($id);
    }

    public function create(array $data)
    {
        return Item::create($data);
    }

    public function update($id, array $data)
    {
        $item = Item::findOrFail($id);
        $item->update($data);

        return $item;
    }

    public function delete($id)
    {
        return Item::findOrFail($id)->delete();
    }
}
