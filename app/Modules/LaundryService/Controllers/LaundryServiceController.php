<?php

namespace App\Modules\LaundryService\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LaundryService\Services\laundryServiceCrudService;
use App\Support\LaundryContext;
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
            $validated['services'] ?? [],
            $request->user(),
        );

        // From inside a laundry the save asked rather than wrote, and says so:
        // «updated» over a list that still shows the old ticks reads as a
        // save that failed.
        $message = LaundryContext::currentId() !== null
            ? ($count > 0
                ? trans_choice(':count change sent for approval.|:count changes sent for approval.', $count, ['count' => $count])
                : __('Nothing to change.'))
            : __('Updated Successfully')." ({$count})";

        return redirect()
            ->route('admin.laundry_service.index', array_filter(['laundry_id' => $validated['laundry_id'] ?? null]))
            ->with('success', $message);
    }
}
