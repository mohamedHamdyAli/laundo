<?php

namespace App\Modules\LaundryStaff\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LaundryStaff\Requests\LaundryStaffRequest;
use App\Modules\LaundryStaff\Services\laundryStaffCrudService;
use Illuminate\Http\Request;

class LaundryStaffController extends Controller
{
    public function __construct(private readonly laundryStaffCrudService $laundryStaffCrudService) {}

    public function index(Request $request)
    {
        $data = $this->laundryStaffCrudService->shredData();
        $view = view('admin.laundry_staff.index', $data);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $staff = $this->laundryStaffCrudService->search($request->get('query'));
            $table = view('admin.laundry_staff.partials._laundry_staff_table_body', compact('staff'))->render();

            return response()->json([
                'table' => $table,
                'pagination' => (string) $staff->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.laundry_staff.create', $this->laundryStaffCrudService->shredData());
    }

    public function store(LaundryStaffRequest $request)
    {
        $this->laundryStaffCrudService->addNew($request->validated());

        return redirect()->route('admin.laundry_staff.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.laundry_staff.show', $this->laundryStaffCrudService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.laundry_staff.edit', $this->laundryStaffCrudService->shredData($id));
    }

    public function update(LaundryStaffRequest $request, $id)
    {
        $this->laundryStaffCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.laundry_staff.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->laundryStaffCrudService->deleteRecord($id);

        return redirect()->route('admin.laundry_staff.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $staff = $this->laundryStaffCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $staff->status,
        ]);
    }
}
