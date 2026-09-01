<?php

namespace App\Modules\TimeSlot\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TimeSlot\Requests\TimeSlotRequest;
use App\Modules\TimeSlot\Services\timeSlotCrudService;
use Illuminate\Http\Request;

class TimeSlotController extends Controller
{
    public function __construct(private readonly timeSlotCrudService $timeSlotCrudService) {}

    public function index(Request $request)
    {
        $timeSlots = $this->timeSlotCrudService->shredData()['timeSlots'];
        $view = view('admin.time_slot.index', compact('timeSlots'));

        return $request->ajax() ? response($view) : $view;
    }

    public function create()
    {
        return view('admin.time_slot.create', $this->timeSlotCrudService->shredData());
    }

    public function store(TimeSlotRequest $request)
    {
        $this->timeSlotCrudService->addNew($request->validated());

        return redirect()->route('admin.time_slot.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.time_slot.show', $this->timeSlotCrudService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.time_slot.edit', $this->timeSlotCrudService->shredData($id));
    }

    public function update(TimeSlotRequest $request, $id)
    {
        $this->timeSlotCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.time_slot.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->timeSlotCrudService->deleteRecord($id);

        return redirect()->route('admin.time_slot.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $slot = $this->timeSlotCrudService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $slot->status]);
    }
}
