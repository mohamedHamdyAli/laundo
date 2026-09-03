<?php

namespace App\Modules\JourneyStep\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\JourneyStep\Requests\JourneyStepRequest;
use App\Modules\JourneyStep\Services\journeyStepCrudService;
use Illuminate\Http\Request;

/**
 * HTTP only — every rule lives in the service.
 */
class JourneyStepController extends Controller
{
    public function __construct(private readonly journeyStepCrudService $journeyStepService) {}

    public function index(Request $request)
    {
        $journeySteps = $this->journeyStepService->getAllJourneySteps();
        $view = view('admin.journey_step.index', compact('journeySteps'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            // `$request->get('query')`, never `$request->query` — that is
            // Symfony's own ParameterBag property.
            $journeySteps = $this->journeyStepService->searchJourneySteps($request->get('query'));
            $table = view('admin.journey_step.partials._journey_step_table_body', compact('journeySteps'))->render();

            return response()->json([
                'table' => $table,
                'pagination' => (string) $journeySteps->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.journey_step.create', $this->journeyStepService->shredData());
    }

    public function store(JourneyStepRequest $request)
    {
        $this->journeyStepService->addJourneyStep($request->validated());

        return redirect()->route('admin.journey_step.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.journey_step.show', $this->journeyStepService->shredData($id));
    }

    public function edit(string $id)
    {
        return view('admin.journey_step.edit', $this->journeyStepService->shredData($id));
    }

    public function update(JourneyStepRequest $request, $id)
    {
        $this->journeyStepService->updateJourneyStep($request->validated() + ['id' => $id]);

        return redirect()->route('admin.journey_step.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->journeyStepService->deleteJourneyStep($id);

        return redirect()->route('admin.journey_step.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $step = $this->journeyStepService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $step->status,
        ]);
    }
}
