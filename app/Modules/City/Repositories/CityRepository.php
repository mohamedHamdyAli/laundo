<?php

namespace App\Modules\City\Repositories;

use App\Modules\City\Models\City;

class CityRepository
{
    public function getAllPaginated($perPage = 10)
    {
        return City::paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return City::search($query, ['name'])->paginate($perPage);
    }

    public function findById($id)
    {
        return City::findOrFail($id);
    }

    public function create(array $data)
    {
        return City::create($data);
    }

    public function update($id, array $data)
    {
        $city = City::findOrFail($id);
        $city->update($data);
        return $city;
    }

    public function delete($id)
    {
        $city = City::findOrFail($id);
        return $city->delete();
    }
}
