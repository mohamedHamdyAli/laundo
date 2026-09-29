<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Requests\CouponRequest;
use App\Modules\Coupon\Services\couponCrudService;
use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Service\Models\Service;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «أكواد الخصم» — the discount codes.
 *
 * Stored through `couponCrudService`, so a code typed in lower case lands upper
 * case exactly as it does from the form.
 *
 * **Who pays for the discount is a money term.** `discount_bearer` and
 * `discount_laundry_share` are columns only for somebody holding
 * `setting.update` — the same boundary the form draws, and `CouponRequest`
 * drops both fields for everybody else before validation, so a sheet cannot
 * move a campaign's cost onto the laundries either way.
 */
class CouponSheet extends Sheet
{
    public function key(): string
    {
        return 'coupon';
    }

    public function title(): string
    {
        return 'coupons';
    }

    public function query(): Builder
    {
        // The count the list's «Claimed» column shows.
        return Coupon::query()->withCount('redemptions');
    }

    public function searchColumns(): array
    {
        return ['code', 'name'];
    }

    public function columns(): array
    {
        $columns = [
            Column::make('id'),
            Column::make('code'),
            Column::make('name')->translatable(),
            Column::make('type'),
            Column::make('value'),
            Column::make('max_discount'),
            Column::make('min_order_total'),
            Column::make('applies_to_delivery'),
            // What it is limited to, in words. For reading only: the limit is a
            // list of ids of one kind, which a cell cannot carry back safely —
            // it is set on the coupon's own screen.
            Column::readOnly('applies_to', fn (Coupon $coupon) => $this->appliesTo($coupon)),
        ];

        if (canDo('setting.update')) {
            // Written the way the form reads the stored share back into its
            // select: null is «the general setting», 0 the platform, 100 the
            // laundry, anything else a split — with the figure beside it only
            // then, as the form shows it.
            $columns[] = Column::make('discount_bearer')->value(fn (Coupon $coupon) => self::bearer($coupon));
            $columns[] = Column::make('discount_laundry_share')->value(
                fn (Coupon $coupon) => self::bearer($coupon) === 'split' ? $coupon->discount_laundry_share : null
            );
        }

        return array_merge($columns, [
            Column::make('max_redemptions'),
            Column::make('max_per_user'),
            Column::make('starts_at'),
            Column::make('ends_at'),
            Column::make('status'),
            Column::readOnly('redemptions_count', fn (Coupon $coupon) => $coupon->redemptions_count),
        ]);
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return CouponRequest::class;
    }

    public function input(array $cells, ?Model $existing = null): array
    {
        $input = parent::input($cells, $existing);

        // A code of digits alone comes back from Excel as a number, and the
        // form's `string` rule would refuse what the operator typed.
        if (isset($input['code']) && is_int($input['code'])) {
            $input['code'] = (string) $input['code'];
        }

        if ($existing) {
            // The form always posts these together, and its checks read them
            // together: a percentage over 100 is caught only when the type is
            // there to say it is a percentage, and an end date is checked
            // against the start. A row changing one of a pair is posted with
            // the other as it is stored, so the check still means something.
            $input = $this->companions($input, $existing, [['type', 'value'], ['starts_at', 'ends_at']]);
        }

        return $input;
    }

    public function create(array $validated): void
    {
        app(couponCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        /** @var Coupon $row */
        app(couponCrudService::class)->updateRecord($validated + [
            'id' => $row->getKey(),
            // The service reads an absent box as «unticked», because that is
            // what an unchecked checkbox is. A blank cell is not: it keeps what
            // the coupon had.
            'applies_to_delivery' => $row->applies_to_delivery,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, array{0: string, 1: string}>  $pairs
     * @return array<string, mixed>
     */
    private function companions(array $input, Model $existing, array $pairs): array
    {
        foreach ($pairs as [$first, $second]) {
            foreach ([[$first, $second], [$second, $first]] as [$given, $missing]) {
                if (array_key_exists($given, $input) && ! array_key_exists($missing, $input)) {
                    $stored = $existing->getAttribute($missing);

                    if ($stored instanceof \DateTimeInterface) {
                        $stored = $stored->format('Y-m-d H:i:s');
                    }

                    if ($stored !== null && $stored !== '') {
                        $input[$missing] = $stored;
                    }
                }
            }
        }

        return $input;
    }

    private static function bearer(Coupon $coupon): string
    {
        $share = $coupon->discount_laundry_share;

        return match (true) {
            $share === null => 'default',
            (float) $share === 0.0 => 'platform',
            (float) $share === 100.0 => 'laundry',
            default => 'split',
        };
    }

    /** @var array<string, array<int, string>> every name of a kind, looked up once per export */
    private array $scopeNames = [];

    private function appliesTo(Coupon $coupon): ?string
    {
        if (! $coupon->isScoped()) {
            return null;
        }

        $kind = (string) $coupon->scope_type;

        $model = match ($kind) {
            Coupon::SCOPE_SERVICE => Service::class,
            Coupon::SCOPE_CATEGORY => ItemCategory::class,
            default => Item::class,
        };

        $this->scopeNames[$kind] ??= $model::all()
            ->mapWithKeys(fn ($row) => [$row->id => getLocalizedValueDashboard($row, 'name')])
            ->all();

        $names = array_filter(array_map(fn (int $id) => $this->scopeNames[$kind][$id] ?? null, $coupon->scopeIds()));

        return __(ucfirst($kind)).': '.implode('، ', $names);
    }
}
