<?php

namespace App\Http\Controllers\Admin\Category;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\category\CategoryRequest;
use App\Models\Category;
use App\Services\category\categoryCrudService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private categoryCrudService $categoryCrudService;
    public function __construct(categoryCrudService $categoryCrudService)
    {
        $this->categoryCrudService = $categoryCrudService;
    }
    public function index(Request $request)
    {
        $categories = Category::whereNull('parent_id')->paginate(10);
        $view = view('admin.category.index', compact('categories'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $searchQuery = $request->get('query');
            $categories = Category::search($searchQuery, ['name'])->paginate(10);
            $table = view('admin.category.partials._category_table_body', compact('categories'))->render();
            return response()->json([
                'table' => $table,
                'pagination' => (string) $categories->withQueryString()->links(),
            ]);
        }
    }
    public function showSubCategories(string $id)
    {
        $data = $this->categoryCrudService->showSubCategories($id);
        return view('admin.category.subcategories', $data);
    }
    /**
     * Show the form for creating a new resource.
     */
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

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $data = $this->categoryCrudService->shredData($id);
        return view('admin.category.show', $data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $data = $this->categoryCrudService->shredData($id);
        return view('admin.category.edit', $data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CategoryRequest $request, $id)
    {
        $this->categoryCrudService->updateRecord($request->validated() + ['id' => $id]);
        return redirect()->route('admin.category.index')->with('success', __('Updated Successfully'));
    }

    /**
     * Remove the specified resource from storage.
     */
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
