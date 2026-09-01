<?php

namespace App\Modules\Driver\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Driver\Requests\DriverRequest;
use App\Modules\Driver\Services\driverCrudService;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function __construct(private readonly driverCrudService $driverCrudService) {}

    public function index(Request $request)
    {
        $drivers = $this->driverCrudService->shredData()['drivers'];
        $view = view('admin.driver.index', compact('drivers'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $drivers = $this->driverCrudService->search($request->get('query'));

            return response()->json([
                'table' => view('admin.driver.partials._driver_table_body', compact('drivers'))->render(),
                'pagination' => (string) $drivers->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.driver.create', $this->driverCrudService->shredData());
    }

    public function store(DriverRequest $request)
    {
        $this->driverCrudService->addNew($request->validated());

        return redirect()->route('admin.driver.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.driver.show', $this->driverCrudService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.driver.edit', $this->driverCrudService->shredData($id));
    }

    public function update(DriverRequest $request, $id)
    {
        $this->driverCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.driver.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->driverCrudService->deleteRecord($id);

        return redirect()->route('admin.driver.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $driver = $this->driverCrudService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $driver->status]);
    }
}
