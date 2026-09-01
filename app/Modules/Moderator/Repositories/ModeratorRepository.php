<?php

namespace App\Modules\Moderator\Repositories;

use App\Modules\Moderator\Models\Moderator;

class ModeratorRepository
{
    public function getAll($perPage = 10)
    {
        return Moderator::latest()->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return Moderator::search($query, ['name', 'phone'])->latest()->paginate($perPage);
    }

    public function findById($id)
    {
        return Moderator::findOrFail($id);
    }

    public function create(array $data)
    {
        return Moderator::create($data);
    }

    public function update($id, array $data)
    {
        $moderator = $this->findById($id);
        $moderator->update($data);

        return $moderator;
    }

    public function delete($id)
    {
        $moderator = $this->findById($id);
        $moderator->delete();

        return true;
    }
}
