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
        $data = $this->zoneCrudService->shredData();
        // Every drawn zone, for the map above the list.
        $view = view('admin.zone.index', ['zones' => $data['zones'], 'drawnZones' => $data['otherZones']]);

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

        return redirect()->route('admin.zone.index')->with('success', $this->saved(__('Added Successfully')));
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

        return redirect()->route('admin.zone.index')->with('success', $this->saved(__('Updated Successfully')));
    }

    /**
     * The flash after a save, with what the drawing did to addresses — a
     * redraw that quietly moved three hundred customers, or left some where
     * they were because an order on them is still under way, is something the
     * person who drew it should be told.
     */
    private function saved(string $message): string
    {
        $relocated = $this->zoneCrudService->relocated();

        if ($relocated['moved'] > 0) {
            $message .= ' '.__(':count addresses moved to the zone their pin is in.', ['count' => $relocated['moved']]);
        }

        if ($relocated['held'] > 0) {
            $message .= ' '.__(':count addresses kept their zone until their orders in progress are done.', ['count' => $relocated['held']]);
        }

        return $message;
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
