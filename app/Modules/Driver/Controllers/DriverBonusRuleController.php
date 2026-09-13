<?php

namespace App\Modules\Driver\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Driver\Models\DriverBonusRule;
use App\Modules\Driver\Models\DriverProfile;
use App\Modules\Driver\Requests\DriverBonusRuleRequest;
use App\Modules\Driver\Services\driverBonusRuleCrudService;
use Illuminate\Http\Request;

/**
 * HTTP only — every rule lives in the service.
 */
class DriverBonusRuleController extends Controller
{
    public function __construct(private readonly driverBonusRuleCrudService $ruleService) {}

    public function index(Request $request)
    {
        $rules = $this->ruleService->getAllRules();
        $view = view('admin.driver_bonus_rule.index', compact('rules'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            // `$request->get('query')`, never `$request->query` — that is
            // Symfony's own ParameterBag property.
            $rules = $this->ruleService->searchRules($request->get('query'));

            return response()->json([
                'table' => view('admin.driver_bonus_rule.partials._driver_bonus_rule_table_body', compact('rules'))->render(),
                'pagination' => (string) $rules->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.driver_bonus_rule.create', $this->ruleService->shredData());
    }

    public function store(DriverBonusRuleRequest $request)
    {
        $this->ruleService->addRule($request->validated());

        return redirect()->route('admin.driver_bonus_rule.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.driver_bonus_rule.show', $this->ruleService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.driver_bonus_rule.edit', $this->ruleService->shredData($id));
    }

    public function update(DriverBonusRuleRequest $request, $id)
    {
        $this->ruleService->updateRule($request->validated() + ['id' => $id]);

        return redirect()->route('admin.driver_bonus_rule.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->ruleService->deleteRule($id);

        return redirect()->route('admin.driver_bonus_rule.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $rule = $this->ruleService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $rule->status]);
    }

    /**
     * «البونس» — put one driver on a rule, or take them off it.
     *
     * Its own action, and **gated on `setting.update` rather than
     * `driver.update`**. An operator holds `driver.update` to keep licences and
     * shift times current; what a driver is paid is a money term, and it sits
     * behind the same permission as the platform commission rather than beside
     * the vehicle registration.
     *
     * An empty selection clears the rule and the driver earns nothing — which is
     * why the column is nullable and why blank cannot mean «the standard rule».
     */
    public function assign(Request $request, $id)
    {
        $data = $request->validate([
            'bonus_rule_id' => ['nullable', 'exists:driver_bonus_rules,id'],
        ]);

        $profile = DriverProfile::where('user_id', $id)->firstOrFail();

        $ruleId = $data['bonus_rule_id'] ?? null;
        $ruleId = ($ruleId === null || $ruleId === '') ? null : (int) $ruleId;

        // forceFill because the column is deliberately not fillable — see the
        // note on DriverProfile. The guard is the point, not an oversight.
        $profile->forceFill(['bonus_rule_id' => $ruleId])->save();

        return back()->with('success', $ruleId === null
            ? __('Bonus cleared. This driver earns no bonus.')
            : __('Bonus rule assigned.'));
    }

    /**
     * The rules an operator can pick from, for the assign dialog.
     *
     * Active only: putting a driver on a switched-off rule would look like it
     * paid and pay nothing.
     */
    public static function assignableRules()
    {
        return DriverBonusRule::where('status', 'active')->orderBy('id')->get();
    }
}
