<?php

namespace App\Modules\Driver\Repositories;

use App\Modules\Driver\Models\Driver;

/**
 * All queries go through the Driver model, so its role scope always applies —
 * which is what stops /admin/driver/show/{id} rendering a customer or a laundry
 * owner given their id.
 */
class DriverRepository
{
    public function getAllPaginated($perPage = 15)
    {
        return Driver::with(['profile', 'zones'])->latest()->paginate($perPage);
    }

    public function search($query, $perPage = 15)
    {
        return Driver::with(['profile', 'zones'])
            ->search($query, ['name', 'phone', 'email'])
            ->latest()
            ->paginate($perPage);
    }

    public function findById($id)
    {
        return Driver::with(['profile', 'zones'])->findOrFail($id);
    }

    public function create(array $data)
    {
        return Driver::create($data);
    }

    public function update($id, array $data)
    {
        $driver = $this->findById($id);
        $driver->update($data);

        return $driver;
    }

    public function delete($id)
    {
        return $this->findById($id)->delete();
    }
}
