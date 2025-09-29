<?php

namespace App\Modules\Country\Repositories;

use App\Modules\Country\Models\Country;

class CountryRepository
{
    public function getAll($perPage = 10)
    {
        return Country::paginate($perPage);
    }
    public function search($query, $perPage = 10)
    {
        return Country::search($query, ['name'])->paginate($perPage);
    }

    public function findById($id)
    {
        return Country::findOrFail($id);
    }

    public function create(array $data)
    {
        $data['name'] = json_encode($data['name'], JSON_UNESCAPED_UNICODE);
        return Country::create($data);
    }

    public function update($id, array $data)
    {
        $country = $this->findById($id);
        $data['name'] = json_encode($data['name'], JSON_UNESCAPED_UNICODE);
        $country->update($data);
        return $country;
    }

    public function delete($id)
    {
        $country = $this->findById($id);
        $country->delete();
        return true;
    }
}
