<?php

namespace App\Modules\TimeSlot\Services;

use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Repositories\TimeSlotRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class timeSlotCrudService
{
    protected $timeSlotRepository;

    protected $responseService;

    public function __construct(
        TimeSlotRepository $timeSlotRepository,
        ResponseService $responseService,
        private readonly SlotCapacity $capacity,
    ) {
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
        $slots = $this->timeSlotRepository->getAllPaginated();

        $data = [
            'timeSlots' => $slots,
            // A capacity nobody can watch being used is a number somebody sets
            // once and never revisits. Today and tomorrow are the two days that
            // can still be acted on: today is what is happening, tomorrow is what
            // can still be re-staffed.
            'usage' => $this->usage($slots),
        ];

        if ($id) {
            $data['row'] = $this->timeSlotRepository->findById($id);
        }

        return $data;
    }

    /**
     * Bookings against each window for today and tomorrow.
     *
     * @param  iterable<int, TimeSlot>  $slots
     * @return array<int, array{today: int, tomorrow: int}>
     */
    private function usage(iterable $slots): array
    {
        $out = [];

        foreach ($slots as $slot) {
            $out[$slot->id] = [
                'today' => $this->capacity->booked($slot, now()),
                'tomorrow' => $this->capacity->booked($slot, now()->addDay()),
            ];
        }

        return $out;
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
