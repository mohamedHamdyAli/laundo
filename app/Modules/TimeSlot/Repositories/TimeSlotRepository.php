<?php

namespace App\Modules\TimeSlot\Repositories;

use App\Modules\TimeSlot\Models\TimeSlot;

class TimeSlotRepository
{
    public function getAllPaginated($perPage = 15)
    {
        return TimeSlot::orderBy('sort_order')->orderBy('start_time')->paginate($perPage);
    }

    public function findById($id)
    {
        return TimeSlot::findOrFail($id);
    }

    public function allActive()
    {
        return TimeSlot::where('status', 'active')->orderBy('sort_order')->orderBy('start_time')->get();
    }

    /**
     * Active windows usable for the given purpose. A `both` window always qualifies.
     */
    public function activeFor(string $type)
    {
        return TimeSlot::where('status', 'active')
            ->whereIn('applies_to', [$type, 'both'])
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get();
    }

    public function create(array $data)
    {
        return TimeSlot::create($data);
    }

    public function update($id, array $data)
    {
        $slot = $this->findById($id);
        $slot->update($data);

        return $slot;
    }

    public function delete($id)
    {
        return $this->findById($id)->delete();
    }
}
