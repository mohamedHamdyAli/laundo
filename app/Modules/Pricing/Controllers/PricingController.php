<?php

namespace App\Modules\Pricing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pricing\Models\PriceChange;
use App\Modules\Pricing\Services\PriceIncrease;
use App\Modules\Pricing\Services\pricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

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

    /**
     * Raise every piece price — for a period, for good, or take a period rise
     * off. The arithmetic and the writes are `PriceIncrease`'s; this reads the
     * form.
     */
    public function increase(Request $request, PriceIncrease $increase)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:period,permanent,remove'],
            'rate' => ['nullable', 'required_unless:mode,remove', 'numeric', 'min:0.01', 'max:100'],
            'ends_at' => ['nullable', 'date'],
        ]);

        if ($data['mode'] === 'remove') {
            $increase->applyForPeriod(0, null, $request->user());

            return redirect()->route('admin.pricing.index')->with('success', __('The price increase is off. Prices are back to the list.'));
        }

        $rate = (float) $data['rate'];

        if ($data['mode'] === 'permanent') {
            $count = $increase->applyPermanently($rate, $request->user());

            return redirect()->route('admin.pricing.index')
                ->with('success', __(':count prices raised by :rate% for good.', ['count' => $count, 'rate' => $rate]));
        }

        // Typed in the operator's own clock and stored in UTC, like every
        // timestamp here — `displayTimezone()` is the one conversion.
        $endsAt = empty($data['ends_at'])
            ? null
            : Carbon::parse($data['ends_at'], displayTimezone())->utc();

        if ($endsAt !== null && $endsAt->isPast()) {
            return back()->withInput()->withErrors(['ends_at' => __('The end date must be in the future.')]);
        }

        $increase->applyForPeriod($rate, $endsAt, $request->user());

        return redirect()->route('admin.pricing.index')
            ->with('success', __('Prices are raised by :rate% until you take it off.', ['rate' => $rate]));
    }

    /**
     * Undo a permanent rise from the history — the latest one still standing.
     */
    public function undoIncrease(Request $request, PriceIncrease $increase, $id)
    {
        try {
            $result = $increase->undo(PriceChange::findOrFail($id), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'newer_first' => __('Undo the later increase first — this one is underneath it.'),
                default => __('This increase cannot be undone.'),
            });
        }

        return back()->with('success', $result['kept'] > 0
            ? __(':restored prices put back. :kept were changed by hand since and were left as they are.', $result)
            : __(':restored prices put back.', $result));
    }
}
