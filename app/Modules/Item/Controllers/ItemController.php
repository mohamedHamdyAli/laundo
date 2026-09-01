<?php

namespace App\Modules\Item\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Item\Requests\ItemRequest;
use App\Modules\Item\Services\itemCrudService;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function __construct(private readonly itemCrudService $itemCrudService) {}

    public function index(Request $request)
    {
        $items = $this->itemCrudService->shredData()['items'];
        $view = view('admin.item.index', compact('items'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $items = $this->itemCrudService->search($request->get('query'));

            return response()->json([
                'table' => view('admin.item.partials._item_table_body', compact('items'))->render(),
                'pagination' => (string) $items->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.item.create', $this->itemCrudService->shredData());
    }

    public function store(ItemRequest $request)
    {
        $this->itemCrudService->addNew($request->validated());

        return redirect()->route('admin.item.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.item.show', $this->itemCrudService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.item.edit', $this->itemCrudService->shredData($id));
    }

    public function update(ItemRequest $request, $id)
    {
        $this->itemCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.item.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->itemCrudService->deleteRecord($id);

        return redirect()->route('admin.item.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $item = $this->itemCrudService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $item->status]);
    }
}
