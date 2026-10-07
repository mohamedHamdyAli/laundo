<?php

namespace App\Modules\Zone\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Zone\Services\coverageRequestCrudService;
use Illuminate\Http\Request;

/**
 * «طلبات خارج التغطية» — customers we told «we will contact you as soon as we
 * reach your area».
 *
 * There is no create, edit or delete. A row is a refused order or quote, and
 * the only thing an operator does to one is ring the customer — so the screen
 * is a list, its search, and a «كلّمناه» toggle.
 */
class CoverageRequestController extends Controller
{
    public function __construct(private readonly coverageRequestCrudService $requests) {}

    public function index(Request $request)
    {
        $view = view('admin.coverage_request.index', $this->requests->shredData());

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $coverageRequests = $this->requests->search($request->get('query'));

            return response()->json([
                'table' => view('admin.coverage_request.partials._coverage_request_table_body',
                    compact('coverageRequests'))->render(),
                'pagination' => (string) $coverageRequests->withQueryString()->links(),
            ]);
        }
    }

    /**
     * «كلّمناه» — and back again.
     */
    public function toggleContacted($id)
    {
        $row = $this->requests->toggleContacted($this->requests->findById((int) $id));

        return back()->with('success', $row->isContacted()
            ? __('Marked as contacted.')
            : __('Put back in the queue.'));
    }
}
