<?php

namespace App\Http\Controllers;

use App\Modules\City\Models\City;
use App\Modules\Laundry\Requests\LaundryApplicationRequest;
use App\Modules\Laundry\Services\LaundryApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * «سجّل مغسلتك» — the public application form.
 *
 * Outside `app/Modules/Laundry/Controllers/` on purpose: everything in there is
 * an operator acting inside `/admin` behind `permission:laundry.*`, and this is
 * a guest with no account at all. Putting it beside them would invite somebody
 * to add the module's middleware to the group and lock the public out of it.
 *
 * It delegates to `LaundryApplicationService`, which is the same shape as every
 * other controller here: HTTP in, service, view out.
 */
class LaundryApplicationController extends Controller
{
    public function __construct(private readonly LaundryApplicationService $applications) {}

    public function create(): View
    {
        return view('auth.laundry-register', [
            // Only the cities the platform actually serves. A form offering a
            // city with no laundries and no zones takes an application that can
            // never be given an order.
            'cities' => City::where('status', 'active')->orderBy('id')->get(),
        ]);
    }

    public function store(LaundryApplicationRequest $request): RedirectResponse
    {
        $this->applications->apply($request->validated() + ['logo' => $request->file('logo')]);

        // Post/redirect/get, and the thank-you page is its own address so a
        // refresh does not re-submit and the applicant can be sent back to it.
        return redirect()->route('laundry.applied');
    }

    /**
     * «طلبك تحت المراجعة».
     */
    public function submitted(): View
    {
        return view('auth.laundry-applied');
    }
}
