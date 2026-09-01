<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\City\Models\City;
use App\Modules\TimeSlot\Repositories\TimeSlotRepository;
use App\Modules\TimeSlot\Services\SlotCapacity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Geography and scheduling lookups for the address form and the wizard's
 * schedule step. Public, because guest mode browses before signing up.
 */
class GeoController extends Controller
{
    public function __construct(
        private readonly TimeSlotRepository $timeSlotRepository,
        private readonly SlotCapacity $capacity,
    ) {}

    /**
     * Active cities with their active zones — what the address form's two
     * dropdowns (المدينة / المنطقة) are built from.
     *
     * Both dropdowns off one call by default, because they are filled together
     * and a second round trip between picking a city and seeing its zones is a
     * spinner in the middle of a form. `city_id` narrows it to one city for the
     * caller that already knows which — a saved address being edited, or a form
     * reloading zones after the city changed on a slow connection.
     */
    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
        ]);

        $cities = City::where('status', 'active')
            ->when(isset($data['city_id']), fn ($q) => $q->where('id', (int) $data['city_id']))
            ->with(['zones' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->get();

        $payload = [];

        // A plain loop rather than nested map() calls: eager-loading with a
        // constraint closure loses the collection's element type, so a typed
        // callback cannot be written without fighting the inference.
        foreach ($cities as $city) {
            $zones = [];

            foreach ($city->zones as $zone) {
                $zones[] = [
                    'id' => $zone->id,
                    'name' => getLocalizedValue($zone, 'name'),
                ];
            }

            $payload[] = [
                'id' => $city->id,
                'name' => getLocalizedValue($city, 'name'),
                'zones' => $zones,
            ];
        }

        return successReturnData($payload);
    }

    /**
     * The pickup / delivery windows.
     *
     * `capacity` is returned as configured, but no "remaining" figure is: that
     * needs a count of orders already booked into the window, and orders arrive
     * in P6. Returning a made-up number would be worse than returning none.
     */
    public function timeSlots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:pickup,delivery'],
            'date' => ['nullable', 'date'],
        ]);

        $type = $data['type'] ?? null;

        $slots = $type !== null
            ? $this->timeSlotRepository->activeFor($type)
            : $this->timeSlotRepository->allActive();

        $date = isset($data['date']) ? Carbon::parse($data['date']) : null;

        $payload = $slots->map(function ($slot) use ($date) {
            $row = [
                'id' => $slot->id,
                'start_time' => substr((string) $slot->start_time, 0, 5),
                'end_time' => substr((string) $slot->end_time, 0, 5),
                'label' => $slot->label(),
                'applies_to' => $slot->applies_to,
                'capacity' => $slot->capacity,
            ];

            // Without a date there is nothing to count against: capacity is per
            // window per day, and a bare list is what the pricing screen wants.
            if ($date === null) {
                return $row;
            }

            $remaining = $this->capacity->remaining($slot, $date);

            // `remaining: null` is «as many as you like», `0` is «choose another
            // window» — the app has to draw those differently, so they are not
            // collapsed into one number here.
            return $row + [
                'remaining' => $remaining,
                'is_full' => $remaining !== null && $remaining < 1,
            ];
        })->values();

        return successReturnData($payload);
    }
}
