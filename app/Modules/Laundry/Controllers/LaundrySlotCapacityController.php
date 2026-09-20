<?php

namespace App\Modules\Laundry\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Laundry\Services\laundrySlotCapacityCrudService;
use Illuminate\Http\Request;

/**
 * The capacity grid, and the save both capacity editors post to.
 *
 * There is no `create`, `edit` or `destroy`: a capacity is a number in a cell,
 * not a record somebody opens. The grid is the whole CRUD.
 */
class LaundrySlotCapacityController extends Controller
{
    public function __construct(private readonly laundrySlotCapacityCrudService $service) {}

    public function index(Request $request)
    {
        $data = $this->service->shredData($request->query('laundry_id'));

        $data['bookedToday'] = $this->service->bookedToday(
            $data['laundries']->pluck('id')->all(),
            $data['slots']->pluck('id')->all(),
        );

        return view('admin.laundry_slot_capacity.index', $data);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'capacities' => ['array'],
            'capacities.*' => ['array'],
            // Nullable is «uncapped» and 0 is «closed» — both legitimate, and
            // the service tells them apart. The ceiling is here because a
            // window that claims to take ten thousand orders is a typo, and an
            // unbounded number would make the balancing rule meaningless.
            'capacities.*.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // Which screen posted, as a laundry id rather than a URL. A
            // `redirect_to` taken from the request is an open redirect, and
            // «it is behind a permission» is not a reason to build one.
            'return_to_laundry' => ['nullable', 'integer', 'exists:laundries,id'],
        ]);

        $count = $this->service->sync($validated['capacities'] ?? []);

        $backTo = $validated['return_to_laundry'] ?? null;

        $redirect = $backTo
            ? redirect()->route('admin.laundry.edit', $backTo)
            : redirect()->route('admin.laundry_slot_capacity.index');

        return $redirect->with('success', __('Updated Successfully')." ({$count})");
    }
}
