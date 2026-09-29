<?php

namespace App\Modules\Coupon\Services;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Models\CouponRedemption;
use App\Modules\Coupon\Repositories\CouponRepository;
use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Service\Models\Service;
use App\Services\ResponseService;
use Illuminate\Support\Collection;
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

    /**
     * What a coupon may be limited to, for the form's three lists. Only on the
     * form screens — the list screen has no use for the catalogue.
     *
     * Active rows, plus whatever the coupon being edited already holds even if
     * it has since been switched off: a list missing it would drop it without a
     * word the next time somebody saved the coupon for its end date.
     *
     * @return array{scopeChoices: array{service: Collection<int, Service>, category: Collection<int, ItemCategory>, item: Collection<int, Item>}}
     */
    public function formChoices($id = null): array
    {
        $coupon = $id ? $this->coupons->findById($id) : null;
        $held = fn (string $kind) => $coupon && $coupon->scope_type === $kind ? $coupon->scopeIds() : [];
        $listed = fn (string $model, string $kind) => $model::query()
            ->where(fn ($query) => $query->where('status', 'active')->orWhereIn('id', $held($kind)));

        return ['scopeChoices' => [
            'service' => $listed(Service::class, Coupon::SCOPE_SERVICE)->orderBy('sort_order')->get(['id', 'name']),
            'category' => $listed(ItemCategory::class, Coupon::SCOPE_CATEGORY)->orderBy('sort_order')->get(['id', 'name']),
            'item' => $listed(Item::class, Coupon::SCOPE_ITEM)->orderBy('item_category_id')->orderBy('sort_order')->get(['id', 'name', 'item_category_id']),
        ]];
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

        // What it applies to — only when the form said, so an import sheet that
        // does not carry the columns leaves the limit as it was. A blank kind is
        // the whole order, and takes its list with it.
        if (array_key_exists('scope_type', $request)) {
            $scope = in_array($request['scope_type'], Coupon::SCOPES, true) ? $request['scope_type'] : null;
            $data['scope_type'] = $scope;
            $data['scope_ids'] = $scope
                ? array_values(array_unique(array_map('intval', (array) ($request['scope_ids'] ?? []))))
                : null;
        }

        // Who pays for the discount — only when the form was allowed to say
        // (the request drops the field for anybody without `setting.update`),
        // so an edit by somebody who may not set it leaves it as it was.
        if (array_key_exists('discount_bearer', $request) && $request['discount_bearer'] !== null) {
            $data['discount_laundry_share'] = match ($request['discount_bearer']) {
                'platform' => 0,
                'laundry' => 100,
                'split' => round(max(min((float) ($request['discount_laundry_share'] ?? 0), 100.0), 0.0), 2),
                default => null,
            };
        }

        return $data;
    }
}
