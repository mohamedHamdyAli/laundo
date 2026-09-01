<?php

namespace App\Modules\Coupon\Services;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Models\CouponRedemption;
use App\Modules\Coupon\Repositories\CouponRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class couponCrudService
{
    public function __construct(
        private readonly CouponRepository $coupons,
        private readonly ResponseService $responseService,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     */
    public function addNew(array $request): Coupon
    {
        return DB::transaction(fn () => $this->coupons->create($this->payload($request)));
    }

    /**
     * @param  array<string, mixed>  $request
     */
    public function updateRecord(array $request): Coupon
    {
        return DB::transaction(fn () => $this->coupons->update($request['id'], $this->payload($request)));
    }

    public function deleteRecord($id): ?bool
    {
        return DB::transaction(function () use ($id) {
            // A coupon somebody has actually used is part of an order's history.
            // Deleting it would leave those orders pointing at nothing, so it is
            // deactivated instead — which is what the operator meant anyway.
            if (CouponRedemption::where('coupon_id', $id)->exists()) {
                throw new RuntimeException('coupon_has_been_used');
            }

            return $this->coupons->delete($id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function shredData($id = null): array
    {
        $data = ['coupons' => $this->coupons->getAllPaginated()];

        if ($id) {
            $row = $this->coupons->findById($id);
            $data['row'] = $row;
            $data['redemptions'] = CouponRedemption::where('coupon_id', $row->id)
                ->with(['customer:id,name,phone', 'order:id,code'])
                ->latest('id')
                ->limit(50)
                ->get();
        }

        return $data;
    }

    public function search($query, $perPage = 15)
    {
        return $this->coupons->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        return $this->responseService->toggleStatus($this->coupons->findById($id), $status);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function payload(array $request): array
    {
        $data = array_filter([
            'code' => isset($request['code']) ? strtoupper(trim($request['code'])) : null,
            'type' => $request['type'] ?? null,
            'value' => $request['value'] ?? null,
            'max_per_user' => $request['max_per_user'] ?? null,
            'status' => $request['status'] ?? null,
        ], fn ($value) => ! is_null($value));

        if (isset($request['name'])) {
            $data['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
        }

        // Outside the filter: all four are meant to be clearable back to null. A
        // ceiling or an end date that can be set but never removed is a campaign
        // nobody can loosen.
        foreach (['max_discount', 'min_order_total', 'max_redemptions', 'starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $request)) {
                $data[$field] = $request[$field] === '' ? null : $request[$field];
            }
        }

        // An unchecked box is absent from the payload entirely.
        $data['applies_to_delivery'] = (bool) ($request['applies_to_delivery'] ?? false);

        return $data;
    }
}
