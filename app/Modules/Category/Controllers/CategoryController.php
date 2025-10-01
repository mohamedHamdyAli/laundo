<?php

namespace App\Modules\Category\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\category\Requests\CategoryRequest;
use App\Modules\category\Services\CategoryCrudService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private CategoryCrudService $categoryCrudService;

    public function __construct(CategoryCrudService $categoryCrudService)
    {
        $this->categoryCrudService = $categoryCrudService;
    }

    public function index(Request $request)
    {
        $categories = $this->categoryCrudService->shredData()['categories'];
        $view = view('admin.category.index', compact('categories'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $categories = $this->categoryCrudService->shredData()['categories'];
            $table = view('admin.category.partials._category_table_body', compact('categories'))->render();
            return response()->json([
                'table' => $table,
                'pagination' => (string) $categories->withQueryString()->links(),
            ]);
        }
    }

    public function showSubCategories($id)
    {
        $data = $this->categoryCrudService->showSubCategories($id);
        return view('admin.category.subcategories', $data);
    }

    public function create()
    {
        $data = $this->categoryCrudService->shredData();
        return view('admin.category.create', $data);
    }

    public function store(CategoryRequest $request)
    {
        $this->categoryCrudService->addNew($request->validated());
        return redirect()->route('admin.category.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        $data = $this->categoryCrudService->shredData($id);
        return view('admin.category.show', $data);
    }

    public function edit($id)
    {
        $data = $this->categoryCrudService->shredData($id);
        return view('admin.category.edit', $data);
    }

    public function update(CategoryRequest $request, $id)
    {
        $this->categoryCrudService->updateRecord($request->validated() + ['id' => $id]);
        return redirect()->route('admin.category.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->categoryCrudService->deleteRecord($id);
        return redirect()->route('admin.category.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $category = $this->categoryCrudService->toggleStatus($id, $request->status);
        return response()->json([
            'success' => true,
            'status' => $category->status,
        ]);
    }
}
