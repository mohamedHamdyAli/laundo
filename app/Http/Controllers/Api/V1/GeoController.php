<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\City\Models\City;
use App\Modules\TimeSlot\Repositories\TimeSlotRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Geography and scheduling lookups for the address form and the wizard's
 * schedule step. Public, because guest mode browses before signing up.
 */
class GeoController extends Controller
{
    public function __construct(private readonly TimeSlotRepository $timeSlotRepository) {}

    /**
     * Active cities with their active zones — what the address form's two
     * dropdowns (المدينة / المنطقة) are built from.
     */
    public function cities(): JsonResponse
    {
        $cities = City::where('status', 'active')
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
        $type = $request->query('type');

        $slots = in_array($type, ['pickup', 'delivery'], true)
            ? $this->timeSlotRepository->activeFor($type)
            : $this->timeSlotRepository->allActive();

        $payload = $slots->map(fn ($slot) => [
            'id' => $slot->id,
            'start_time' => substr((string) $slot->start_time, 0, 5),
            'end_time' => substr((string) $slot->end_time, 0, 5),
            'label' => $slot->label(),
            'applies_to' => $slot->applies_to,
            'capacity' => $slot->capacity,
        ])->values();

        return successReturnData($payload);
    }
}
