<?php

namespace App\Trait\Scopes;

use Illuminate\Database\Eloquent\Builder;

trait Searchable
{
    /**
     * The `LIKE %term%` behind every list screen's search box.
     *
     * ## Why the cast and the LOWER()
     *
     * This was a plain `orWhere($column, 'LIKE', "%{$search}%")`, and on seven
     * modules it silently found nothing unless you matched the stored case.
     * Reported as «السيرش بايظ في كل الصفح» — searching `c` on Cities returned
     * no rows while `C` returned Cairo.
     *
     * The cause is in the schema, not here. Translatable columns are supposed to
     * be `text` (CLAUDE.md says so), and most are — `banners.name`,
     * `faqs.question`, `intros.title`, `journey_steps.title`, `offers.title` are
     * all `text` with `utf8mb4_unicode_ci`, a case-insensitive collation, and
     * they always worked. But seven were created as **`json`**:
     * `cities.name`, `zones.name`, `services.name`, `items.name`,
     * `item_categories.name`, `laundries.name` and `coupons.name`.
     *
     * A MySQL `json` column has **no character set and no collation** — it is
     * compared as binary. So `LIKE` against it is case-sensitive, and every
     * lowercase search on those seven screens missed. Nothing errored, nothing
     * logged: an empty result is indistinguishable from "no such row", which is
     * why this survived so long.
     *
     * Fixed here rather than by seven migrations, for the reason
     * `tasks/lessons.md` gives under "fix the class, not the call sites": one
     * scope serves every list screen, so correcting it reaches the modules that
     * exist now *and* the next translatable column somebody declares as `json`.
     * Changing the columns would also mean an `ALTER` on seven tables holding
     * live data to fix something the query can express directly.
     *
     * `CAST(... AS CHAR)` gives the JSON its text form under the connection's
     * charset; `LOWER()` on both sides then makes the comparison
     * case-insensitive on every column type at once. Neither costs an index —
     * a leading-wildcard `LIKE` could never use one.
     *
     * The identifier goes through the grammar's own `wrap()` rather than being
     * interpolated. Call sites pass hardcoded column lists today, but a raw
     * fragment built by string concatenation is one refactor away from taking a
     * request value.
     *
     * @param  array<int, string>  $columns
     */
    public function scopeSearch(Builder $query, ?string $search, array $columns = ['name']): Builder
    {
        if (! $search || $columns === []) {
            return $query;
        }

        $term = '%'.mb_strtolower(trim($search)).'%';

        return $query->where(function (Builder $q) use ($term, $columns): void {
            $grammar = $q->getQuery()->getGrammar();

            foreach ($columns as $column) {
                $q->orWhereRaw(
                    'LOWER(CAST('.$grammar->wrap($column).' AS CHAR)) LIKE ?',
                    [$term]
                );
            }
        });
    }
}
