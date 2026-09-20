<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Address\Models\Address;
use App\Modules\City\Models\City;
use App\Modules\Laundry\Services\LaundryLoad;
use App\Modules\Order\Enums\SlotOverflowBehavior;
use App\Modules\Order\Services\LaundryAssigner;
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
        private readonly LaundryAssigner $assigner,
        private readonly LaundryLoad $load,
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
     * With a `date`, `remaining` and `is_full` describe the **platform's** own
     * per-window capacity — how many visits can be made that day.
     *
     * With an `address_id` as well, `laundries_full` additionally says whether
     * every laundry that could serve that address has filled its intake for
     * the window. It is null when the question cannot be asked: no address, an
     * address with no zone, a zone nothing covers, or a delivery-only window.
     * `is_full` folds it in only when `Slot_Overflow_Behavior` is `hide_slot`,
     * because on the other two settings the order is still accepted and
     * withholding the window would refuse business the server would take.
     *
     * Both new parameters are optional and a client that sends neither gets
     * exactly the response it got before.
     */
    public function timeSlots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:pickup,delivery'],
            'date' => ['nullable', 'date'],
            /*
             * Optional, and the whole reason the endpoint can answer
             * «this window has nowhere to go» rather than only «the
             * platform is at capacity». Without it the laundry half is
             * unknowable: capacity is per laundry, and which laundries
             * matter depends on the address's zone.
             *
             * `service_id` narrows it further, because a laundry that
             * does not offer the service was never a candidate.
             */
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
        ]);

        $type = $data['type'] ?? null;

        $slots = $type !== null
            ? $this->timeSlotRepository->activeFor($type)
            : $this->timeSlotRepository->allActive();

        $date = isset($data['date']) ? Carbon::parse($data['date']) : null;

        // Which laundries could take work at this address at all. Empty
        // when no address was sent, when it has no zone, or when nothing
        // covers it — and an empty list is deliberately NOT «all full».
        $covering = $this->coveringFor($data['address_id'] ?? null, $data['service_id'] ?? null);

        // Only this setting turns a full set of laundries into a hidden
        // window. On the other two the order is still accepted, so
        // withholding the window would refuse business the server would
        // have taken.
        $hideWhenFull = SlotOverflowBehavior::current() === SlotOverflowBehavior::HideSlot;

        $payload = $slots->map(function ($slot) use ($date, $covering, $hideWhenFull) {
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
            $platformFull = $remaining !== null && $remaining < 1;

            // Reported separately from `remaining`, which keeps meaning
            // exactly what it meant: places left across the platform. A
            // client that ignores this field behaves as it always did.
            //
            // Only for a window clothes are *collected* in. Laundry
            // capacity counts intake, so asking it about a
            // delivery-only window would measure the wrong thing and
            // report a clear evening as full because the morning was.
            $laundriesFull = $covering === [] || ! $slot->appliesTo('pickup')
                ? false
                : $this->load->everyOneFull($covering, $slot->id, $date);

            return $row + [
                'remaining' => $remaining,
                'is_full' => $platformFull || ($hideWhenFull && $laundriesFull),
                'laundries_full' => $covering === [] || ! $slot->appliesTo('pickup') ? null : $laundriesFull,
            ];
        })->values();

        return successReturnData($payload);
    }

    /**
     * The laundries that could serve this address, through the same
     * definition the assigner uses.
     *
     * Shared rather than re-queried: a window this endpoint hides must be
     * a window the assigner would also have had nowhere to send.
     *
     * @return array<int, int>
     */
    private function coveringFor(?int $addressId, ?int $serviceId): array
    {
        if ($addressId === null) {
            return [];
        }

        $zoneId = Address::whereKey($addressId)->value('zone_id');

        if ($zoneId === null) {
            return [];
        }

        return $this->assigner->coveringLaundryIds((int) $zoneId, $serviceId);
    }
}
