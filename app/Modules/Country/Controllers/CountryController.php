<?php

namespace App\Modules\Country\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Country\Requests\CountryRequest;
use App\Modules\Country\Services\countryCrudService;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    private countryCrudService $countryCrudService;

    public function __construct(countryCrudService $countryCrudService)
    {
        $this->countryCrudService = $countryCrudService;
    }

    public function index(Request $request)
    {
        $countries = $this->countryCrudService->getAllPaginated(10);
        $view = view('admin.country.index', compact('countries'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $searchQuery = $request->get('query');
            $countries = $this->countryCrudService->search($searchQuery, 10);

            $table = view('admin.country.partials._country_table_body', compact('countries'))->render();

            return response()->json([
                'table' => $table,
                'pagination' => (string) $countries->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        $data = $this->countryCrudService->shredData();
        return view('admin.country.create', $data);
    }

    public function store(CountryRequest $request)
    {
        $this->countryCrudService->addNew($request->validated());
        return redirect()->route('admin.country.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        $data = $this->countryCrudService->shredData($id);
        return view('admin.country.show', $data);
    }

    public function edit($id)
    {
        $data = $this->countryCrudService->shredData($id);
        return view('admin.country.edit', $data);
    }

    public function update(CountryRequest $request, $id)
    {
        $this->countryCrudService->updateRecord($request->validated() + ['id' => $id]);
        return redirect()->route('admin.country.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->countryCrudService->deleteRecord($id);
        return redirect()->route('admin.country.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $category = $this->countryCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $category->status,
        ]);
    }
}
