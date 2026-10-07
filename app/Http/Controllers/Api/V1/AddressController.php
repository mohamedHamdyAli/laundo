<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddressRequest;
use App\Modules\Address\Models\Address;
use App\Modules\Zone\Services\ZoneLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A customer's saved addresses.
 *
 * Every lookup goes through `$request->user()->addresses()`, never
 * `Address::find()`. That is the whole of the isolation here: a query rooted in
 * the authenticated user cannot return someone else's row, so guessing an id in
 * the URL yields a 404 rather than another customer's home address.
 */
class AddressController extends Controller
{
    public function __construct(private readonly ZoneLocator $zones) {}

    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()
            ->addresses()
            ->with(['city', 'zone'])
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        $payload = [];

        foreach ($addresses as $address) {
            $payload[] = $this->present($address);
        }

        return successReturnData($payload);
    }

    public function store(AddressRequest $request): JsonResponse
    {
        // The zone follows the pin (ZoneLocator), not the app's pick.
        $data = $this->zones->placeAddress($request->validated(), null);

        $address = DB::transaction(function () use ($request, $data) {
            $user = $request->user();

            // The first address a customer saves is their default, whether or not
            // they ticked the box — otherwise they would have none.
            $isFirst = ! $user->addresses()->exists();
            $makeDefault = ($data['is_default'] ?? false) || $isFirst;

            $address = $user->addresses()->create($data + ['is_default' => $makeDefault]);

            if ($makeDefault) {
                $this->clearOtherDefaults($request, $address->id);
            }

            return $address;
        });

        return successReturnCreated($this->present($address->fresh(['city', 'zone'])), 'Address saved.');
    }

    public function show(Request $request, $id): JsonResponse
    {
        $address = $request->user()->addresses()->with(['city', 'zone'])->find($id);

        if (! $address) {
            return failReturnNotFound('Address not found.');
        }

        return successReturnData($this->present($address));
    }

    public function update(AddressRequest $request, $id): JsonResponse
    {
        $address = $request->user()->addresses()->find($id);

        if (! $address) {
            return failReturnNotFound('Address not found.');
        }

        $data = $this->zones->placeAddress($request->validated(), $address);

        DB::transaction(function () use ($request, $address, $data) {
            $address->update($data);

            if ($data['is_default'] ?? false) {
                $this->clearOtherDefaults($request, $address->id);
            }
        });

        return successReturnData($this->present($address->fresh(['city', 'zone'])), 'Address updated.');
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $address = $request->user()->addresses()->find($id);

        if (! $address) {
            return failReturnNotFound('Address not found.');
        }

        DB::transaction(function () use ($request, $address) {
            $wasDefault = $address->is_default;
            $address->delete();

            // Never leave a customer with addresses but no default.
            if ($wasDefault) {
                $next = $request->user()->addresses()->orderByDesc('id')->first();
                $next?->update(['is_default' => true]);
            }
        });

        return returnSuccessMsg('Address deleted.');
    }

    public function makeDefault(Request $request, $id): JsonResponse
    {
        $address = $request->user()->addresses()->find($id);

        if (! $address) {
            return failReturnNotFound('Address not found.');
        }

        DB::transaction(function () use ($request, $address) {
            $address->update(['is_default' => true]);
            $this->clearOtherDefaults($request, $address->id);
        });

        return successReturnData($this->present($address->fresh(['city', 'zone'])), 'Default address updated.');
    }

    /**
     * Exactly one default per customer. Scoped through the relation so it can
     * only ever touch the acting user's rows.
     */
    private function clearOtherDefaults(Request $request, int $keepId): void
    {
        $request->user()
            ->addresses()
            ->where('id', '!=', $keepId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Address $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'city' => $address->city ? [
                'id' => $address->city->id,
                'name' => getLocalizedValue($address->city, 'name'),
            ] : null,
            'zone' => $address->zone ? [
                'id' => $address->zone->id,
                'name' => getLocalizedValue($address->zone, 'name'),
            ] : null,
            // Whether an order to or from here would be accepted — false is
            // the pin outside every zone that `POST /orders` and the quote
            // refuse with `out_of_coverage`. Sent so the app can warn when the
            // address is picked, before the customer fills the basket.
            'is_covered' => $address->isCovered(),
            'street' => $address->street,
            'building' => $address->building,
            'floor' => $address->floor,
            'apartment' => $address->apartment,
            'landmark' => $address->landmark,
            'notes' => $address->notes,
            'driver_note' => $address->driver_note,
            'contact_phone' => $address->callablePhone(),
            'lat' => (float) $address->lat,
            'lng' => (float) $address->lng,
            'is_default' => (bool) $address->is_default,
        ];
    }
}
