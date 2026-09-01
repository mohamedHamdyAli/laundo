<?php

namespace App\Modules\ItemCategory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ItemCategory\Requests\ItemCategoryRequest;
use App\Modules\ItemCategory\Services\itemCategoryCrudService;
use Illuminate\Http\Request;

class ItemCategoryController extends Controller
{
    public function __construct(private readonly itemCategoryCrudService $itemCategoryCrudService) {}

    public function index(Request $request)
    {
        $itemCategories = $this->itemCategoryCrudService->shredData()['itemCategories'];
        $view = view('admin.item_category.index', compact('itemCategories'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $itemCategories = $this->itemCategoryCrudService->search($request->get('query'));

            return response()->json([
                'table' => view('admin.item_category.partials._item_category_table_body', compact('itemCategories'))->render(),
                'pagination' => (string) $itemCategories->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.item_category.create', $this->itemCategoryCrudService->shredData());
    }

    public function store(ItemCategoryRequest $request)
    {
        $this->itemCategoryCrudService->addNew($request->validated());

        return redirect()->route('admin.item_category.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.item_category.show', $this->itemCategoryCrudService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.item_category.edit', $this->itemCategoryCrudService->shredData($id));
    }

    public function update(ItemCategoryRequest $request, $id)
    {
        $this->itemCategoryCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.item_category.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->itemCategoryCrudService->deleteRecord($id);

        return redirect()->route('admin.item_category.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $category = $this->itemCategoryCrudService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $category->status]);
    }
}
