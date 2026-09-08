<?php

namespace App\Modules\Coupon\Repositories;

use App\Modules\Coupon\Models\Coupon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Every coupon query lives here.
 */
class CouponRepository
{
    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return Coupon::withCount('redemptions')->latest('id')->paginate($perPage);
    }

    public function search(?string $query, int $perPage = 15): LengthAwarePaginator
    {
        return Coupon::withCount('redemptions')
            // `name` is the human label shown under the code on the list screen;
            // it is a translatable json column, so one term hits both languages.
            ->search($query, ['code', 'name'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function findById($id): Coupon
    {
        return Coupon::withCount('redemptions')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Coupon
    {
        return Coupon::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update($id, array $data): Coupon
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->update($data);

        return $coupon;
    }

    public function delete($id): ?bool
    {
        return Coupon::findOrFail($id)->delete();
    }
}
