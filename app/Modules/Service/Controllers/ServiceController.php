<?php

namespace App\Modules\Service\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Service\Requests\ServiceRequest;
use App\Modules\Service\Services\serviceCrudService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(private readonly serviceCrudService $serviceCrudService) {}

    public function index(Request $request)
    {
        $services = $this->serviceCrudService->shredData()['services'];
        $view = view('admin.service.index', compact('services'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $services = $this->serviceCrudService->search($request->get('query'));

            return response()->json([
                'table' => view('admin.service.partials._service_table_body', compact('services'))->render(),
                'pagination' => (string) $services->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.service.create', $this->serviceCrudService->shredData());
    }

    public function store(ServiceRequest $request)
    {
        $this->serviceCrudService->addNew($request->validated());

        return redirect()->route('admin.service.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.service.show', $this->serviceCrudService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.service.edit', $this->serviceCrudService->shredData($id));
    }

    public function update(ServiceRequest $request, $id)
    {
        $this->serviceCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.service.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->serviceCrudService->deleteRecord($id);

        return redirect()->route('admin.service.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $service = $this->serviceCrudService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $service->status]);
    }
}
