<?php

namespace App\Modules\Pricing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pricing\Services\pricingService;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function __construct(private readonly pricingService $pricingService) {}

    /**
     * The price grid: items down the side, per-item services across the top.
     */
    public function index()
    {
        return view('admin.pricing.index', $this->pricingService->shredData());
    }

    /**
     * Bulk save. The whole grid posts at once so a single transaction either
     * takes every edit or none of them.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'prices' => 'array',
            // Two levels of wildcard: prices[item_id][service_id].
            'prices.*.*' => 'nullable|numeric|min:0|max:999999.99',
        ], [
            'prices.*.*.numeric' => __('Prices must be numbers.'),
            'prices.*.*.min' => __('Prices cannot be negative.'),
        ]);

        $count = $this->pricingService->saveGrid($validated['prices'] ?? []);

        return redirect()
            ->route('admin.pricing.index')
            ->with('success', __('Updated Successfully')." ({$count})");
    }
}
