<?php

namespace App\Modules\Address\Services;

use App\Modules\Address\Models\Address;
use App\Modules\User\Models\User;
use App\Modules\Zone\Services\ZoneLocator;
use Illuminate\Support\Facades\DB;

/**
 * A customer's saved addresses — adding one.
 *
 * One place for two callers: the customer's own app (`POST /addresses`) and
 * the panel's «إنشاء طلب», where an operator taking an order over the phone
 * pins a new address for the customer (2026-11-08). Both have to agree on the
 * two rules below, and a second copy of them in a controller is the copy that
 * drifts.
 *
 * - **The zone follows the pin** (`ZoneLocator::placeAddress()`), never the
 *   caller's pick.
 * - **The first address a customer saves is their default**, ticked or not —
 *   otherwise they would have none.
 */
class AddressBook
{
    public function __construct(private readonly ZoneLocator $zones) {}

    /**
     * @param  array<string, mixed>  $data  validated by `AddressRequest`
     */
    public function add(User $customer, array $data): Address
    {
        $data = $this->zones->placeAddress($data, null);

        return DB::transaction(function () use ($customer, $data) {
            $isFirst = ! $customer->addresses()->exists();
            $makeDefault = ($data['is_default'] ?? false) || $isFirst;

            $address = $customer->addresses()->create($data + ['is_default' => $makeDefault]);

            if ($makeDefault) {
                $customer->addresses()
                    ->where('id', '!=', $address->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return $address;
        });
    }
}
