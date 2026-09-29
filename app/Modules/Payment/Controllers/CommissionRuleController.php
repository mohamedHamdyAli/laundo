<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Modules\Payment\Models\CommissionRule;
use App\Modules\Payment\Requests\CommissionRuleRequest;
use App\Modules\Payment\Services\commissionRuleCrudService;
use Illuminate\Http\Request;

/**
 * HTTP only — every rule lives in the service.
 */
class CommissionRuleController extends Controller
{
    public function __construct(private readonly commissionRuleCrudService $ruleService) {}

    public function index(Request $request)
    {
        $rules = $this->ruleService->getAllRules();
        $view = view('admin.commission_rule.index', compact('rules'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            // `$request->get('query')`, never `$request->query` — that is
            // Symfony's own ParameterBag property.
            $rules = $this->ruleService->searchRules($request->get('query'));

            return response()->json([
                'table' => view('admin.commission_rule.partials._commission_rule_table_body', compact('rules'))->render(),
                'pagination' => (string) $rules->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.commission_rule.create', $this->ruleService->shredData());
    }

    public function store(CommissionRuleRequest $request)
    {
        $this->ruleService->addRule($request->validated());

        return redirect()->route('admin.commission_rule.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.commission_rule.show', $this->ruleService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.commission_rule.edit', $this->ruleService->shredData($id));
    }

    public function update(CommissionRuleRequest $request, $id)
    {
        $this->ruleService->updateRule($request->validated() + ['id' => $id]);

        return redirect()->route('admin.commission_rule.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->ruleService->deleteRule($id);

        return redirect()->route('admin.commission_rule.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        if ($request->status === 'active' && ($refusal = $this->ruleService->activationRefusal($id)) !== null) {
            return response()->json(['success' => false, 'message' => $refusal], 422);
        }

        $rule = $this->ruleService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $rule->status]);
    }

    /**
     * The shares an operator can put a laundry on, for the laundry dialog.
     *
     * Active percentage rules only: putting a laundry on a switched-off rule
     * would look like terms and pay on none, and a fixed rule is retired.
     */
    public static function attachableRules()
    {
        return CommissionRule::active()
            ->where('basis', CommissionBasis::Percent->value)
            ->orderBy('id')
            ->get();
    }
}
