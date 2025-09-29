<?php

namespace App\Modules\User\Repositories;

use App\Modules\User\Models\User;

class UserRepository
{
    /**
     * Create a new class instance.
     */
    public function getAll($perPage = 10)
    {
        return User::availableUsers()->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return User::availableUsers()->search($query, ['name', 'phone'])->paginate($perPage);
    }

    public function find($id)
    {
        return User::findOrFail($id);
    }

    public function create(array $data)
    {
        return User::create($data);
    }

    public function update($id, array $data)
    {
        $user = $this->find($id);
        $user->update($data);
        return $user;
    }

    public function delete($id)
    {
        $user = $this->find($id);
        $user->delete();
        return true;
    }
}
