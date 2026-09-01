<?php

namespace App\Modules\LaundryService\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LaundryService\Services\laundryServiceCrudService;
use Illuminate\Http\Request;

class LaundryServiceController extends Controller
{
    public function __construct(private readonly laundryServiceCrudService $laundryServiceCrudService) {}

    public function index(Request $request)
    {
        return view(
            'admin.laundry_service.index',
            $this->laundryServiceCrudService->shredData($request->query('laundry_id'))
        );
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'laundry_id' => 'nullable|exists:laundries,id',
            'services' => 'array',
            'services.*' => 'integer|exists:services,id',
        ]);

        $count = $this->laundryServiceCrudService->sync(
            $validated['laundry_id'] ?? null,
            $validated['services'] ?? []
        );

        return redirect()
            ->route('admin.laundry_service.index', array_filter(['laundry_id' => $validated['laundry_id'] ?? null]))
            ->with('success', __('Updated Successfully')." ({$count})");
    }
}
