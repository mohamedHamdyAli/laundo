<?php

namespace App\Modules\LaundryStaff\Repositories;

use App\Modules\LaundryStaff\Models\LaundryStaff;

class LaundryStaffRepository
{
    public function getAllPaginated($perPage = 10)
    {
        return LaundryStaff::with(['role', 'laundry'])->latest()->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return LaundryStaff::with(['role', 'laundry'])
            ->search($query, ['name', 'phone', 'email'])
            ->latest()
            ->paginate($perPage);
    }

    public function findById($id)
    {
        return LaundryStaff::with(['role', 'laundry'])->findOrFail($id);
    }

    public function create(array $data)
    {
        return LaundryStaff::create($data);
    }

    public function update($id, array $data)
    {
        $staff = $this->findById($id);
        $staff->update($data);

        return $staff;
    }

    public function delete($id)
    {
        $staff = $this->findById($id);

        return $staff->delete();
    }
}
