<?php

namespace App\Modules\LaundryZone\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LaundryZone\Services\laundryZoneCrudService;
use Illuminate\Http\Request;

class LaundryZoneController extends Controller
{
    public function __construct(private readonly laundryZoneCrudService $laundryZoneCrudService) {}

    public function index(Request $request)
    {
        return view(
            'admin.laundry_zone.index',
            $this->laundryZoneCrudService->shredData($request->query('laundry_id'))
        );
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'laundry_id' => 'nullable|exists:laundries,id',
            'zones' => 'array',
            'zones.*' => 'integer|exists:zones,id',
        ]);

        $count = $this->laundryZoneCrudService->sync(
            $validated['laundry_id'] ?? null,
            $validated['zones'] ?? []
        );

        return redirect()
            ->route('admin.laundry_zone.index', array_filter(['laundry_id' => $validated['laundry_id'] ?? null]))
            ->with('success', __('Updated Successfully')." ({$count})");
    }
}
