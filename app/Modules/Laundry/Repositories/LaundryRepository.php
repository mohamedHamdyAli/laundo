<?php

namespace App\Modules\Laundry\Repositories;

use App\Modules\Laundry\Models\Laundry;

/**
 * All queries run through the Laundry model, so the tenant global scope declared
 * there applies automatically — including to findById(), which is what stops a
 * laundry user reaching another tenant by putting its id in the URL.
 */
class LaundryRepository
{
    public function getAllPaginated($perPage = 10)
    {
        return Laundry::with('city')->latest()->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return Laundry::with('city')->search($query, ['name', 'phone', 'email'])->latest()->paginate($perPage);
    }

    public function findById($id)
    {
        return Laundry::with(['city', 'users'])->findOrFail($id);
    }

    public function create(array $data)
    {
        return Laundry::create($data);
    }

    public function update($id, array $data)
    {
        $laundry = Laundry::findOrFail($id);
        $laundry->update($data);

        return $laundry;
    }

    public function delete($id)
    {
        $laundry = Laundry::findOrFail($id);

        return $laundry->delete();
    }
}
