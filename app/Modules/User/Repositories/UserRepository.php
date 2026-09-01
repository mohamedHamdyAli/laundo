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
        // «مرجع العميل» is searchable because it is the identifier written on the
        // physical bag — somebody at the counter holding a parcel has that and
        // nothing else.
        return User::availableUsers()
            ->search($query, ['name', 'phone', 'customer_reference'])
            ->paginate($perPage);
    }

    /**
     * Scoped to customers, matching getAll() and search().
     *
     * It used to be a bare findOrFail, so /admin/user/show/{id} would happily
     * render a moderator or a laundry owner given their id — the "Customers"
     * page showing a staff account. The scope makes the module honest about what
     * it manages.
     */
    public function find($id)
    {
        return User::availableUsers()->findOrFail($id);
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
