<?php

namespace App\Modules\Banner\Repositories;

use App\Modules\Banner\Models\banner;

class BannerRepository
{
    public function getAll($perPage = 10)
    {
        return banner::paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return banner::search($query, ['name', 'description'])->paginate($perPage);
    }

    public function find($id)
    {
        return banner::findOrFail($id);
    }

    public function create(array $data)
    {
        return banner::create($data);
    }

    public function update($id, array $data)
    {
        $banner = $this->find($id);
        $banner->update($data);

        return $banner;
    }

    public function delete($id)
    {
        $banner = $this->find($id);
        DeleteImage($banner->image);
        $banner->delete();

        return true;
    }

    public function shredData($id = null)
    {
        $data = [];
        if ($id != null) {
            $data['row'] = banner::findOrFail($id);
        }

        return $data;
    }
}
