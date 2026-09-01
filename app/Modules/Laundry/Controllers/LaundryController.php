<?php

namespace App\Modules\Laundry\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Laundry\Requests\LaundryRequest;
use App\Modules\Laundry\Services\laundryCrudService;
use Illuminate\Http\Request;

class LaundryController extends Controller
{
    public function __construct(private readonly laundryCrudService $laundryCrudService) {}

    public function index(Request $request)
    {
        $laundries = $this->laundryCrudService->shredData()['laundries'];
        $view = view('admin.laundry.index', compact('laundries'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $laundries = $this->laundryCrudService->search($request->get('query'));
            $table = view('admin.laundry.partials._laundry_table_body', compact('laundries'))->render();

            return response()->json([
                'table' => $table,
                'pagination' => (string) $laundries->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        $data = $this->laundryCrudService->shredData();

        return view('admin.laundry.create', $data);
    }

    public function store(LaundryRequest $request)
    {
        $this->laundryCrudService->addNew($request->validated());

        return redirect()->route('admin.laundry.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        $data = $this->laundryCrudService->shredData($id);

        return view('admin.laundry.show', $data);
    }

    public function edit($id)
    {
        $data = $this->laundryCrudService->shredData($id);

        return view('admin.laundry.edit', $data);
    }

    public function update(LaundryRequest $request, $id)
    {
        $this->laundryCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.laundry.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->laundryCrudService->deleteRecord($id);

        return redirect()->route('admin.laundry.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $laundry = $this->laundryCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $laundry->status,
        ]);
    }
}
