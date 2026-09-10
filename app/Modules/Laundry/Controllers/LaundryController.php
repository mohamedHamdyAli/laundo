<?php

namespace App\Modules\Laundry\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Requests\LaundryRequest;
use App\Modules\Laundry\Services\LaundryApplicationService;
use App\Modules\Laundry\Services\laundryCrudService;
use Illuminate\Http\Request;
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
            ->with(['city', 'owner'])
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

    public function toggleStatus(Request $request, $id)
    {
        $laundry = $this->laundryCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $laundry->status,
        ]);
    }
}
