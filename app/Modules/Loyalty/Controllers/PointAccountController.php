<?php

namespace App\Modules\Loyalty\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Loyalty\Services\pointAccountCrudService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * «نقاط العملاء» — every customer's points, their ledger, and a correction by
 * hand. HTTP only: the service does the rest.
 */
class PointAccountController extends Controller
{
    public function __construct(private readonly pointAccountCrudService $service) {}

    public function index(Request $request)
    {
        $data = $this->guard(fn () => $this->service->shredData());

        $view = view('admin.point_account.index', $data);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $data = $this->guard(fn () => $this->service->shredData(null, $request->get('query')));

            return response()->json([
                'table' => view('admin.point_account.partials._point_account_table_body', $data)->render(),
                'pagination' => $data['accounts']->withQueryString()->links()->toHtml(),
            ]);
        }
    }

    /**
     * One customer's points, by their user id — so a customer who has never
     * earned a point can still be given some.
     */
    public function show($id)
    {
        return view('admin.point_account.show', $this->guard(fn () => $this->service->shredData($id)));
    }

    /**
     * «تسوية» — add or take points, with a reason.
     */
    public function adjust(Request $request, $id)
    {
        $request->validate([
            'direction' => ['required', Rule::in([PointTransaction::CREDIT, PointTransaction::DEBIT])],
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            // Required: a correction nobody explained is one nobody can defend.
            'note' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->service->adjust(
                $id,
                (string) $request->get('direction'),
                (int) $request->get('points'),
                (string) $request->get('note'),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'not_a_customer' => abort(404),
                'not_for_a_laundry' => abort(403),
                'insufficient_points' => back()->with('error', __('The customer does not have that many points.')),
                default => back()->with('error', __('Could not adjust these points.')),
            };
        }

        return back()->with('success', __('Adjustment recorded.'));
    }

    /**
     * The service's two refusals as HTTP: somebody who is not a customer is
     * not found, and a laundry is forbidden.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function guard(callable $call)
    {
        try {
            return $call();
        } catch (RuntimeException $e) {
            abort($e->getMessage() === 'not_for_a_laundry' ? 403 : 404);
        }
    }
}
