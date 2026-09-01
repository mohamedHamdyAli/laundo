<?php

namespace App\Modules\Zone\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Zone\Requests\ZoneRequest;
use App\Modules\Zone\Services\zoneCrudService;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function __construct(private readonly zoneCrudService $zoneCrudService) {}

    public function index(Request $request)
    {
        $zones = $this->zoneCrudService->shredData()['zones'];
        $view = view('admin.zone.index', compact('zones'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $zones = $this->zoneCrudService->search($request->get('query'));

            return response()->json([
                'table' => view('admin.zone.partials._zone_table_body', compact('zones'))->render(),
                'pagination' => (string) $zones->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.zone.create', $this->zoneCrudService->shredData());
    }

    public function store(ZoneRequest $request)
    {
        $this->zoneCrudService->addNew($request->validated());

        return redirect()->route('admin.zone.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.zone.show', $this->zoneCrudService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.zone.edit', $this->zoneCrudService->shredData($id));
    }

    public function update(ZoneRequest $request, $id)
    {
        $this->zoneCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.zone.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->zoneCrudService->deleteRecord($id);

        return redirect()->route('admin.zone.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $zone = $this->zoneCrudService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $zone->status]);
    }
}
