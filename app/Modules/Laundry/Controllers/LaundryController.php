<?php

namespace App\Modules\Laundry\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Requests\LaundryRequest;
use App\Modules\Laundry\Services\LaundryApplicationService;
use App\Modules\Laundry\Services\laundryCrudService;
use App\Modules\Payment\Enums\CommissionBasis;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class LaundryController extends Controller
{
    public function __construct(private readonly laundryCrudService $laundryCrudService) {}

    public function index(Request $request)
    {
        $laundries = $this->laundryCrudService->shredData()['laundries'];
        $view = view('admin.laundry.index', compact('laundries'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $laundries = $this->laundryCrudService->search($request->get('query'));
            $table = view('admin.laundry.partials._laundry_table_body', compact('laundries'))->render();

            return response()->json([
                'table' => $table,
                'pagination' => (string) $laundries->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        $data = $this->laundryCrudService->shredData();

        return view('admin.laundry.create', $data);
    }

    public function store(LaundryRequest $request)
    {
        $this->laundryCrudService->addNew($request->validated());

        return redirect()->route('admin.laundry.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        $data = $this->laundryCrudService->shredData($id);

        return view('admin.laundry.show', $data);
    }

    public function edit($id)
    {
        $data = $this->laundryCrudService->shredData($id);

        return view('admin.laundry.edit', $data);
    }

    public function update(LaundryRequest $request, $id)
    {
        $this->laundryCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.laundry.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->laundryCrudService->deleteRecord($id);

        return redirect()->route('admin.laundry.index')->with('success', __('Deleted Successfully'));
    }

    /**
     * «مستنية موافقة» — the applications nobody has decided on.
     *
     * Its own action rather than a query string on `index`, because the two
     * screens do different things: this one draws approve and reject buttons,
     * and the list does not. A filter that changed which buttons a row has
     * would be a second screen pretending to be one.
     */
    public function pending(Request $request)
    {
        $laundries = Laundry::withoutGlobalScopes()
            ->pending()
            // The services it applied to offer: approving the application
            // approves them, so the reviewer has to see them here.
            ->with(['city', 'owner', 'services' => fn ($q) => $q->withoutGlobalScopes()->with('service:id,name')])
            ->paginate(10);

        $view = view('admin.laundry.pending', compact('laundries'));

        return $request->ajax() ? response($view) : $view;
    }

    public function approve(Request $request, $id)
    {
        $laundry = Laundry::withoutGlobalScopes()->findOrFail($id);

        try {
            app(LaundryApplicationService::class)->approve($laundry);
        } catch (RuntimeException) {
            return back()->with('error', __('This laundry has already been approved.'));
        }

        return back()->with('success', __('Laundry approved. They can sign in now.'));
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            // Optional, but capped: it is emailed to the applicant verbatim.
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $laundry = Laundry::withoutGlobalScopes()->findOrFail($id);

        app(LaundryApplicationService::class)->reject($laundry, $request->input('rejection_reason'));

        return back()->with('success', __('Application rejected.'));
    }

    /**
     * «العمولة» — choose which charges this laundry pays.
     *
     * Its own action, and **gated on `setting.update` rather than
     * `laundry.update`**. A laundry owner holds `laundry.update` so they can
     * edit their own record; routing the commission through the same permission
     * would let the party paying it decide what it is. What a laundry is charged
     * is platform configuration, so it sits behind the permission that governs
     * the general rate beside it.
     *
     * **One share, or none.** The number on a rule is what the *laundry*
     * receives from the washing — the platform keeps the rest — and a laundry
     * is on one share at a time; they stopped stacking when the percentage
     * changed sides. Choosing none puts the laundry on the general share in
     * Settings, and with that unset too its settlements wait rather than pay
     * the platform the whole of the laundry's work.
     *
     * `sync()` with the one id, so whatever it was on before comes off.
     */
    public function commission(Request $request, $id)
    {
        $data = $request->validate([
            'commission_rule_id' => [
                'nullable', 'integer',
                // Only a live share. Putting a laundry on a switched-off rule,
                // or a retired fixed one, would look like terms and pay on none.
                Rule::exists('commission_rules', 'id')
                    ->where('status', 'active')
                    ->where('basis', CommissionBasis::Percent->value),
            ],
        ]);

        $laundry = Laundry::withoutGlobalScopes()->findOrFail($id);

        $ruleId = isset($data['commission_rule_id']) ? (int) $data['commission_rule_id'] : null;

        $laundry->commissionRules()->sync($ruleId === null ? [] : [$ruleId]);

        return back()->with('success', $ruleId === null
            ? __('This laundry now follows the general laundry share in Settings.')
            : __('Laundry share saved.'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $laundry = $this->laundryCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $laundry->status,
        ]);
    }
}
