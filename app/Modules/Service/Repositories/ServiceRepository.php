<?php

namespace App\Modules\Service\Repositories;

use App\Modules\Service\Models\Service;

class ServiceRepository
{
    public function getAllPaginated($perPage = 10)
    {
        return Service::orderBy('sort_order')->orderBy('id')->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return Service::search($query, ['name'])->orderBy('sort_order')->paginate($perPage);
    }

    public function findById($id)
    {
        return Service::findOrFail($id);
    }

    /**
     * Active, per-item services in display order — the columns of the price grid,
     * and the set the customer app lists in wizard step 2.
     */
    public function activePerItem()
    {
        return Service::where('status', 'active')
            ->where('pricing_mode', 'per_item')
            ->orderBy('sort_order')
            ->get();
    }

    public function allActive()
    {
        return Service::where('status', 'active')->orderBy('sort_order')->get();
    }

    public function create(array $data)
    {
        return Service::create($data);
    }

    public function update($id, array $data)
    {
        $service = $this->findById($id);
        $service->update($data);

        return $service;
    }

    public function delete($id)
    {
        return $this->findById($id)->delete();
    }
}
