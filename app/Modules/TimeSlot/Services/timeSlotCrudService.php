<?php

namespace App\Modules\TimeSlot\Services;

use App\Modules\TimeSlot\Repositories\TimeSlotRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class timeSlotCrudService
{
    protected $timeSlotRepository;

    protected $responseService;

    public function __construct(TimeSlotRepository $timeSlotRepository, ResponseService $responseService)
    {
        $this->timeSlotRepository = $timeSlotRepository;
        $this->responseService = $responseService;
    }

    public function addNew(array $request)
    {
        return DB::transaction(fn () => $this->timeSlotRepository->create($this->payload($request)));
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(fn () => $this->timeSlotRepository->update($request['id'], $this->payload($request)));
    }

    public function deleteRecord($id)
    {
        return DB::transaction(fn () => $this->timeSlotRepository->delete($id));
    }

    public function shredData($id = null)
    {
        $data = ['timeSlots' => $this->timeSlotRepository->getAllPaginated()];

        if ($id) {
            $data['row'] = $this->timeSlotRepository->findById($id);
        }

        return $data;
    }

    public function toggleStatus($id, $status)
    {
        return $this->responseService->toggleStatus($this->timeSlotRepository->findById($id), $status);
    }

    /**
     * `capacity` is handled separately from the rest on purpose: an empty field
     * means unlimited, and array_filter would drop it, so the previous value
     * would survive. Clearing the box has to actually clear the column.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function payload(array $request): array
    {
        $data = array_filter([
            'start_time' => $request['start_time'] ?? null,
            'end_time' => $request['end_time'] ?? null,
            'applies_to' => $request['applies_to'] ?? null,
            'sort_order' => $request['sort_order'] ?? null,
            'status' => $request['status'] ?? null,
        ], fn ($value) => ! is_null($value));

        if (array_key_exists('capacity', $request)) {
            $data['capacity'] = ($request['capacity'] === null || $request['capacity'] === '')
                ? null
                : (int) $request['capacity'];
        }

        return $data;
    }
}
