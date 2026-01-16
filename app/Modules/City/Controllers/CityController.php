<?php

namespace App\Modules\City\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\City\Requests\CityRequest;
use App\Modules\City\Services\cityCrudService;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function __construct(private readonly cityCrudService $cityCrudService)
    {
    }

    public function index(Request $request)
    {
        $cities = $this->cityCrudService->getAllPaginated(10);
        $view = view('admin.city.index', compact('cities'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $searchQuery = $request->get('query');
            $cities = $this->cityCrudService->search($searchQuery, 10);
            $table = view('admin.city.partials._city_table_body', compact('cities'))->render();

            return response()->json([
                'table' => $table,
                'pagination' => (string) $cities->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        $data = $this->cityCrudService->shredData();
        return view('admin.city.create', $data);
    }

    public function store(CityRequest $request)
    {
        $this->cityCrudService->addNew($request->validated());
        return redirect()->route('admin.city.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        $data = $this->cityCrudService->shredData($id);
        return view('admin.city.show', $data);
    }

    public function edit(string $id)
    {
        $data = $this->cityCrudService->shredData($id);
        return view('admin.city.edit', $data);
    }

    public function update(CityRequest $request, $id)
    {
        $this->cityCrudService->updateRecord($request->validated() + ['id' => $id]);
        return redirect()->route('admin.city.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->cityCrudService->deleteRecord($id);
        return redirect()->route('admin.city.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $city = $this->cityCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $city->status,
        ]);
    }
}
