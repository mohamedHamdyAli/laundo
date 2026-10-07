<?php

namespace App\Modules\Zone\Repositories;

use App\Modules\Zone\Models\CoverageRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CoverageRequestRepository
{
    /**
     * Everything the list shows that a column holds — the owner's rule that a
     * displayed value can be searched for. The attempt count and the dates
     * are left out, as `Searchable` explains.
     */
    private const SEARCHABLE = ['customer.name', 'customer.phone', 'address_line', 'address.zone.name', 'contacter.name'];

    /**
     * The call list: nobody rung yet first, and among those the ones whose
     * address is served now — the promise that can be kept today — then the
     * latest attempt first.
     *
     * `address:id,zone_id,city_id`: `isNowCovered()` reads `zone_id`, and a
     * column left off a constrained eager load reads as null — which here
     * would quietly say «still outside» for everybody.
     *
     * @return LengthAwarePaginator<int, CoverageRequest>
     */
    public function list(?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        return CoverageRequest::query()
            ->with(['customer:id,name,phone', 'address:id,zone_id,city_id', 'address.zone:id,name', 'contacter:id,name'])
            ->search($search, self::SEARCHABLE)
            // Through `Address::scopeCovered()`, the definition the badge and
            // the row's pill read, so the order cannot disagree with either.
            ->withExists(['address as now_covered' => fn (Builder $address) => $address->covered()])
            ->orderByRaw('contacted_at IS NULL DESC')
            ->orderByDesc('now_covered')
            ->orderByDesc('last_attempt_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findById(int $id): CoverageRequest
    {
        return CoverageRequest::with(['customer:id,name,phone', 'address:id,zone_id,city_id', 'address.zone:id,name', 'contacter:id,name'])
            ->findOrFail($id);
    }

    /**
     * The row for this customer and address, created on the first attempt.
     *
     * `firstOrCreate` inserts through `createOrFirst`, so two refusals a moment
     * apart meet the unique index and read the row the other one wrote rather
     * than failing.
     *
     * @param  array<string, mixed>  $values
     */
    public function firstOrCreateFor(int $userId, int $addressId, array $values): CoverageRequest
    {
        return CoverageRequest::firstOrCreate(['user_id' => $userId, 'address_id' => $addressId], $values);
    }

    public function readyToCallCount(): int
    {
        return CoverageRequest::readyToCall()->count();
    }
}
