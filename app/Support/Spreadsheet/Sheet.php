<?php

namespace App\Support\Spreadsheet;

use App\Models\Language;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What one panel screen exports, and — when it may — imports.
 *
 * A sheet is the screen's own data, read through the same model, so the tenant
 * scope applies to an export exactly as it does to the list: a laundry owner
 * exporting orders gets its own orders. Importing goes through the screen's own
 * FormRequest and its own service (see `Importer`), so a row is accepted on
 * exactly the terms the form would accept it and stored the way the form stores
 * it — the JSON-encoded names, the upper-cased coupon code, the rest.
 *
 * Money records never import: a payment, a settlement or a wallet movement
 * typed into a spreadsheet is a ledger entry nobody earned. Those sheets leave
 * `importable()` false and export only.
 */
abstract class Sheet
{
    /** @var array<int, string>|null */
    private ?array $languages = null;

    /** The key in the URL, e.g. `city`. */
    abstract public function key(): string;

    /** The file name, before the date. */
    abstract public function title(): string;

    /** Every row the screen shows, before any filter. */
    abstract public function query(): Builder;

    /** @return array<int, Column> */
    abstract public function columns(): array;

    /**
     * The model slug the permissions are named after — `city.view`,
     * `city.create`, `city.update`. Usually the key.
     */
    public function permission(): string
    {
        return $this->key();
    }

    /**
     * The permission that adding a row needs. `{model}.create` for most
     * screens; a screen whose own form adds by editing (the price grid, where
     * filling a blank box is `item_price.update`) says so here, so a role that
     * may do it on the screen may do it by import.
     */
    public function createPermission(): string
    {
        return $this->permission().'.create';
    }

    /**
     * The columns the list screen's search box reaches, so an export of a
     * searched list holds the rows the list showed.
     *
     * @return array<int, string>
     */
    public function searchColumns(): array
    {
        return [];
    }

    /**
     * Narrow the query to what the screen is showing: its search term, and
     * whatever filters the screen passes (override for screen-specific ones).
     *
     * @param  array<string, mixed>  $filters
     */
    public function filter(Builder $query, array $filters): Builder
    {
        $term = trim((string) ($filters['query'] ?? ''));

        if ($term !== '' && $this->searchColumns() !== [] && method_exists($query->getModel(), 'scopeSearch')) {
            // Through `scopes()`: the base class cannot know every model it is
            // handed carries the Searchable scope, so it asks for it by name.
            $query->scopes(['search' => [$term, $this->searchColumns()]]);
        }

        return $query;
    }

    // ------------------------------------------------------------ importing

    public function importable(): bool
    {
        return false;
    }

    /** The screen's own FormRequest, which every imported row is validated by. */
    public function request(): ?string
    {
        return null;
    }

    /**
     * The route parameter the FormRequest reads the edited row's id from.
     */
    public function routeKey(): string
    {
        return 'id';
    }

    /**
     * The row an `id` cell names, through the scoped query — never past it.
     */
    public function find(int $id): ?Model
    {
        return $this->query()->getModel()->newQuery()->find($id);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated): void {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Model $row, array $validated): void {}

    /**
     * One sheet row as the form would have posted it: translatable columns
     * folded back into `['en' => …, 'ar' => …]`, read-only helpers dropped.
     *
     * **An empty cell leaves the field alone.** On an edit the form posts every
     * field; a sheet row with a blank cell means «I did not touch this», and
     * posting it as empty would blank a value somebody entered by hand. The
     * same for one language of a translatable field: a row carrying only the
     * English name keeps the Arabic one it had.
     *
     * @param  array<string, mixed>  $cells  header => value, blanks as null
     * @return array<string, mixed>
     */
    public function input(array $cells, ?Model $existing = null): array
    {
        $languages = $this->languages();
        $input = [];

        foreach ($this->columns() as $column) {
            if (! $column->isImportable() || $column->key === 'id') {
                continue;
            }

            if ($column->isTranslatable()) {
                $current = $existing ? $existing->getAttribute($column->key) : null;
                $current = is_string($current) ? json_decode($current) : $current;
                $values = is_object($current) ? array_filter((array) $current, fn ($v) => $v !== null && $v !== '') : [];

                foreach ($languages as $code) {
                    $value = $cells[$column->key.'_'.$code] ?? null;

                    if ($value !== null && $value !== '') {
                        $values[$code] = (string) $value;
                    }
                }

                // Always posted when there is anything to post, edits included:
                // the services encode the whole array, so a name left out of the
                // payload would be stored as `null` rather than left alone.
                if ($values !== []) {
                    $input[$column->key] = $values;
                }

                continue;
            }

            $value = $cells[$column->key] ?? null;

            if ($value !== null && $value !== '') {
                $input[$column->key] = $value;
            }
        }

        return $input;
    }

    /**
     * The language codes, default first, that translatable columns expand to.
     *
     * @return array<int, string>
     */
    public function languages(): array
    {
        // Memoised per sheet, not per process: an import reads it once per
        // row, and a static would outlive the request it was read in.
        return $this->languages ??= Language::query()
            ->orderByRaw("case when `default` = 'true' then 0 else 1 end")
            ->orderBy('id')
            ->pluck('code')
            ->all();
    }

    /**
     * Every header, in order.
     *
     * @return array<int, string>
     */
    public function headers(): array
    {
        $languages = $this->languages();

        return collect($this->columns())
            ->flatMap(fn (Column $column) => $column->headers($languages))
            ->all();
    }
}
