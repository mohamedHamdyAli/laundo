<?php

namespace App\Modules\Intro\Repositories;

use App\Modules\Intro\Models\intro;

class IntroRepository
{
    public function getAll($perPage = 10)
    {
        return intro::paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return intro::search($query, ['title', 'description'])->paginate($perPage);
    }

    public function find($id)
    {
        return intro::findOrFail($id);
    }

    public function create(array $data)
    {
        return intro::create($data);
    }

    public function update($id, array $data)
    {
        $intro = $this->find($id);
        $intro->update($data);
        return $intro;
    }

    public function delete($id)
    {
        $intro = $this->find($id);
        DeleteImage($intro->image);
        $intro->delete();
        return true;
    }
    public function shredData($id = null)
    {
        $data = [];
        if ($id != null) {
            $data['row'] = intro::findOrFail($id);
        }
        return $data;
    }
}
