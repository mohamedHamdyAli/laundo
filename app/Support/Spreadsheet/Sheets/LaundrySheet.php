<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Requests\LaundryRequest;
use App\Modules\Laundry\Services\laundryCrudService;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Modules\Payment\Services\SettlementService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «المغاسل» — the laundries, as the panel creates them.
 *
 * Tenant-scoped by the model's own `own_laundry` scope, so a laundry owner
 * exporting this list gets the one row the list shows them.
 *
 * **The owner columns are for a new row.** A laundry the panel creates is
 * created with its owner account in the same transaction, which is why
 * `owner_name`, `owner_email`, `owner_phone` and `owner_password` are here. On
 * an existing row `LaundryRequest` does not accept the owner's identity — it
 * belongs to the person, not to the laundry record — so those three cells are
 * printed for reading and ignored; only `owner_password` applies, to hand a
 * locked-out owner a new one, and blank leaves it alone.
 *
 * **No password ever leaves.** `owner_password` is always empty on export; it
 * is a column so the template has somewhere to type one.
 *
 * The logo is a file and never imports.
 */
class LaundrySheet extends Sheet
{
    private float|false|null $generalShare = false;

    public function key(): string
    {
        return 'laundry';
    }

    public function title(): string
    {
        return 'laundries';
    }

    public function query(): Builder
    {
        return Laundry::query()->with(['city:id,name', 'owner', 'commissionRules']);
    }

    public function searchColumns(): array
    {
        return ['name', 'phone', 'email', 'city.name'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name')->translatable(),
            Column::make('phone'),
            Column::make('email'),
            Column::make('address'),
            Column::make('city_id'),
            Column::readOnly('city', fn (Laundry $laundry) => $laundry->city ? getLocalizedValueDashboard($laundry->city, 'name') : null),
            Column::make('lat'),
            Column::make('lng'),
            Column::make('status'),
            Column::readOnly('laundry_share', fn (Laundry $laundry) => $this->share($laundry)),
            Column::make('owner_name')->value(fn (Laundry $laundry) => $laundry->owner?->name),
            Column::make('owner_email')->value(fn (Laundry $laundry) => $laundry->owner?->email),
            Column::make('owner_phone')->value(fn (Laundry $laundry) => $laundry->owner?->phone),
            // Never the stored hash — always blank on the way out.
            Column::make('owner_password')->value(fn () => null),
        ];
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return LaundryRequest::class;
    }

    public function input(array $cells, ?Model $existing = null): array
    {
        $input = parent::input($cells, $existing);

        // Excel hands a cell of digits back as a number, and every one of these
        // is validated as a string.
        foreach (['phone', 'email', 'address', 'owner_name', 'owner_email', 'owner_phone', 'owner_password'] as $field) {
            if (isset($input[$field]) && (is_int($input[$field]) || is_float($input[$field]))) {
                $input[$field] = (string) $input[$field];
            }
        }

        // A sheet has one password cell; the form has a box and its repeat.
        // The cell *is* the confirmation — there is no second place to mistype.
        if (isset($input['owner_password'])) {
            $input['owner_password_confirmation'] = $input['owner_password'];
        }

        return $input;
    }

    public function create(array $validated): void
    {
        app(laundryCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        app(laundryCrudService::class)->updateRecord($validated + ['id' => $row->getKey()]);
    }

    /**
     * What the list's «Laundry share» column says: the laundry's own share, or
     * the general one it falls back to, or nothing when neither is set.
     */
    private function share(Laundry $laundry): ?string
    {
        $rule = $laundry->commissionRules
            ->where('status', 'active')
            ->where('basis', CommissionBasis::Percent)
            ->sortBy('id')
            ->first();

        if ($rule) {
            return $rule->explain();
        }

        if ($this->generalShare === false) {
            $this->generalShare = app(SettlementService::class)->defaultShareRate();
        }

        return $this->generalShare === null
            ? null
            : rtrim(rtrim(number_format($this->generalShare, 2), '0'), '.').'%';
    }
}
