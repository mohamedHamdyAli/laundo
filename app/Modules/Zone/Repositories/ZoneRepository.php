<?php

namespace App\Modules\Zone\Repositories;

use App\Modules\Zone\Models\Zone;

class ZoneRepository
{
    public function getAllPaginated($perPage = 15)
    {
        return Zone::with('city')->orderBy('city_id')->orderBy('sort_order')->paginate($perPage);
    }

    public function search($query, $perPage = 15)
    {
        return Zone::with('city')->search($query, ['name'])->orderBy('sort_order')->paginate($perPage);
    }

    public function findById($id)
    {
        return Zone::with('city')->findOrFail($id);
    }

    public function allActive()
    {
        return Zone::with('city')->where('status', 'active')->orderBy('city_id')->orderBy('sort_order')->get();
    }

    public function create(array $data)
    {
        return Zone::create($data);
    }

    public function update($id, array $data)
    {
        $zone = Zone::findOrFail($id);
        $zone->update($data);

        return $zone;
    }

    public function delete($id)
    {
        return Zone::findOrFail($id)->delete();
    }
}
