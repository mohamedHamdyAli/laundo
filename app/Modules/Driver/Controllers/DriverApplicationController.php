<?php

namespace App\Modules\Driver\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Driver\Models\DriverApplication;
use App\Modules\Driver\Requests\DriverApplicationRequest;
use App\Modules\Driver\Services\driverApplicationCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver leads — «عايز تشتغل مندوب؟».
 *
 * Two audiences in one controller, and the split is the `store` action: it is
 * the only one outside `/admin`, called by a guest from the landing page's
 * modal, and it is the only one that answers JSON. Everything else is an
 * operator working the queue.
 *
 * There is no create or edit screen. A row cannot be authored in the panel —
 * it comes from the form or it does not exist — so the module has an index, a
 * show, a toggle and a delete, and nothing else.
 */
class DriverApplicationController extends Controller
{
    public function __construct(private readonly driverApplicationCrudService $applications) {}

    /**
     * The public form's target. Guest, throttled, JSON.
     *
     * JSON rather than a redirect because the form is a modal on a page the
     * visitor is reading: sending them to a thank-you page would throw away
     * the page they were on, and this is a three-box ask, not a checkout.
     */
    public function store(DriverApplicationRequest $request): JsonResponse
    {
        $this->applications->receive($request->validated());

        return response()->json([
            'message' => __('Thanks — we have your number and will call you.'),
        ], 201);
    }

    public function index(Request $request)
    {
        $data = $this->applications->shredData();
        $view = view('admin.driver_application.index', $data);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $applications = $this->applications->search($request->get('query'));

            return response()->json([
                'table' => view('admin.driver_application.partials._driver_application_table_body',
                    compact('applications'))->render(),
                'pagination' => (string) $applications->withQueryString()->links(),
            ]);
        }
    }

    public function show($id)
    {
        return view('admin.driver_application.show', $this->applications->shredData($id));
    }

    /**
     * «كلمناه» — and back again.
     */
    public function toggleHandled(Request $request, $id)
    {
        $request->validate(['admin_note' => ['nullable', 'string', 'max:1000']]);

        $application = DriverApplication::findOrFail($id);
        $this->applications->toggleHandled($application, $request->input('admin_note'));

        return back()->with('success', $application->fresh()->isWaiting()
            ? __('Put back in the queue.')
            : __('Marked as handled.'));
    }

    public function destroy($id)
    {
        $this->applications->deleteRecord((int) $id);

        // The list, not `back()`. Every other module in this panel redirects to
        // its own index after a delete, and for a reason this one proved:
        // `back()` returns the browser to the page the request came from, which
        // after deleting from a detail screen is that row's own URL — a
        // guaranteed 404 — and from the list is a page the browser may serve
        // from cache, still showing the row that has just gone.
        return redirect()->route('admin.driver_application.index')
            ->with('success', __('Deleted Successfully'));
    }
}
