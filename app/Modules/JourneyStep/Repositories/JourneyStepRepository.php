<?php

namespace App\Modules\JourneyStep\Repositories;

use App\Modules\JourneyStep\Models\JourneyStep;

/**
 * The only place raw Eloquent for journey steps lives.
 */
class JourneyStepRepository
{
    public function getAll($perPage = 10)
    {
        return JourneyStep::orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return JourneyStep::search($query, ['title', 'description'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find($id)
    {
        return JourneyStep::findOrFail($id);
    }

    public function create(array $data)
    {
        return JourneyStep::create($data);
    }

    public function update($id, array $data)
    {
        $step = JourneyStep::findOrFail($id);
        $step->update($data);

        return $step;
    }

    public function delete($id)
    {
        $step = JourneyStep::findOrFail($id);

        // The file goes with the row, or it is an orphan nothing will reference
        // or clean up again.
        DeleteImage($step->image);

        $step->delete();

        return true;
    }
}
