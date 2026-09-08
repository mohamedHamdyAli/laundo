<?php

namespace App\Trait\Scopes;

use Illuminate\Database\Eloquent\Builder;

trait Searchable
{
    /**
     * The dashes that fold to a plain hyphen.
     *
     * Literal characters rather than escape sequences, so the pair is readable
     * at a glance. The first is the en dash `Service::durationLabel()` uses.
     */
    private const DASHES = ['–', '—'];

    /**
     * The `LIKE %term%` behind every list screen's search box.
     *
     * Columns may be plain (`name`) or a **dotted relation path**
     * (`profile.vehicle_type`, `city.name`, `zones.name`). Everything before the
     * last dot is the relation, resolved with `whereHas`, so Laravel's own
     * nesting works too: `laundry.city.name` is a valid path.
     *
     * ## Why relation paths exist
     *
     * The owner's requirement is that **anything a list screen displays can be
     * searched for**. Most screens show at least one column that lives on
     * another table — the driver's vehicle, a laundry's city, a staff member's
     * laundry and role — and a scope limited to the model's own table could
     * never reach them. A sweep of all 34 sidebar screens found 21 displayed
     * columns that their own search could not match.
     *
     * ## Why the cast and the LOWER()
     *
     * This was a plain `orWhere($column, 'LIKE', "%{$search}%")`, and on seven
     * modules it silently found nothing unless you matched the stored case —
     * searching `c` on Cities returned nothing while `C` returned Cairo.
     *
     * The cause is in the schema. Translatable columns are supposed to be `text`
     * (CLAUDE.md says so) and most are, with `utf8mb4_unicode_ci` — a
     * case-insensitive collation. But seven were created as **`json`**:
     * `cities.name`, `zones.name`, `services.name`, `items.name`,
     * `item_categories.name`, `laundries.name`, `coupons.name`. Production runs
     * MariaDB, where `json` is `longtext` with **`utf8mb4_bin`** — a binary
     * collation — so `LIKE` against it is case-sensitive.
     *
     * `CAST(... AS CHAR)` gives the value its text form under the connection's
     * charset and `LOWER()` on both sides makes the comparison case-insensitive
     * on every column type at once. Neither costs an index: a leading-wildcard
     * `LIKE` could never use one.
     *
     * Fixed here rather than by seven migrations, for the reason
     * `tasks/lessons.md` gives under "fix the class, not the call sites": one
     * scope serves every list screen, so correcting it reaches the modules that
     * exist now *and* the next translatable column somebody declares as `json`.
     *
     * Identifiers go through the grammar's own `wrap()` rather than being
     * interpolated. Call sites pass hardcoded lists today, and a raw fragment
     * built by concatenation is one refactor away from taking a request value.
     *
     * **Not searchable, deliberately:** aggregates (an item count), and dates
     * rendered by `humanDate()`. The latter is shown in the display timezone
     * while the column stores UTC, so matching the text somebody reads would
     * need a timezone conversion in SQL — a date *filter* rather than a
     * substring search. Left out rather than half-implemented.
     *
     * ## Composed expressions
     *
     * `$expressions` is for a cell that no column holds. The Services screen's
     * DURATION reads "24-48 hours" — a range assembled from `duration_min` and
     * `duration_max`, with the unit word coming from the Web File. Pasting a cell
     * somebody can see has to find its row, so the caller passes the SQL that
     * rebuilds it:
     *
     *     search($term, ['name'], ["CONCAT(`duration_min`, '-', `duration_max`)"])
     *
     * A separate parameter rather than a `raw:` prefix inside `$columns`, so the
     * two kinds cannot be confused: everything in `$columns` is an identifier and
     * is quoted by the grammar, and anything raw is *visibly* raw at the call
     * site. These are developer-authored constants; never build one from request
     * input.
     *
     * @param  array<int, string>  $columns  plain names, or `relation.column` paths
     * @param  array<int, string>  $expressions  raw SQL, for values no column holds
     */
    public function scopeSearch(
        Builder $query,
        ?string $search,
        array $columns = ['name'],
        array $expressions = []
    ): Builder {
        if (! $search || ($columns === [] && $expressions === [])) {
            return $query;
        }

        // Dashes fold to a plain hyphen on both sides. `durationLabel()` composes
        // a range with an en dash, which is on nobody's keyboard — so a term
        // typed by hand and the same term pasted out of the table must compare
        // equal.
        $term = '%'.str_replace(self::DASHES, '-', mb_strtolower(trim($search))).'%';

        return $query->where(function (Builder $outer) use ($term, $columns, $expressions): void {
            foreach ($expressions as $expression) {
                $outer->orWhereRaw($this->foldedExpression($expression).' LIKE ?', [$term]);
            }

            foreach ($columns as $column) {
                if (! str_contains($column, '.')) {
                    $this->orWhereFolded($outer, $column, $term);

                    continue;
                }

                $segments = explode('.', $column);
                $field = (string) array_pop($segments);
                $relation = implode('.', $segments);

                // `whereFolded`, not `orWhereFolded`: `whereHas` puts the
                // relation's own join condition in this same subquery, so an
                // `OR` here escapes it and `EXISTS` becomes true for every row.
                // Measured: zones matching city "cairo" returned all 25 instead
                // of Cairo's 15. The `OR` belongs between columns, not inside a
                // relation constraint.
                $outer->orWhereHas(
                    $relation,
                    fn (Builder $related) => $this->whereFolded($related, $field, $term)
                );
            }
        });
    }

    /**
     * One case-insensitive `LIKE`, OR'd against the other columns.
     *
     * Only ever used at the top level, where each column is an alternative.
     */
    private function orWhereFolded(Builder $query, string $column, string $term): Builder
    {
        return $query->orWhereRaw($this->foldedLike($query, $column), [$term]);
    }

    /**
     * The same comparison, AND'ed.
     *
     * This is the one a `whereHas` closure needs. That subquery already carries
     * the relation's join condition, and an `OR` beside it matches every parent
     * row whose relation table contains the term *anywhere* — which is not a
     * subtle difference: it returns the entire table.
     */
    private function whereFolded(Builder $query, string $column, string $term): Builder
    {
        return $query->whereRaw($this->foldedLike($query, $column), [$term]);
    }

    /**
     * Concatenate SQL fragments in a way both drivers understand.
     *
     * The app runs on MariaDB and the test suite on in-memory SQLite, and there
     * is no spelling of string concatenation they share: SQLite has no `CONCAT`
     * function, and MySQL reads `||` as logical OR unless `PIPES_AS_CONCAT` is
     * set. Writing either one directly means the query works in exactly one of
     * the two places.
     *
     * CLAUDE.md warns that MySQL-only SQL "will pass in tests and fail in the
     * app". This is that hazard the other way round, which is the luckier
     * direction — `CONCAT` failed on the first test run rather than in
     * production.
     *
     * Parts are passed through verbatim, so they may be quoted identifiers or
     * SQL literals. Developer-authored only; never build one from request input.
     *
     * @param  array<int, string>  $parts
     */
    public static function searchConcat(array $parts): string
    {
        // `static::query()` rather than `new static`: a trait cannot know that
        // every model using it has a no-argument constructor, which is what
        // phpstan means by "unsafe usage of new static()" — it reported this
        // once per model, 24 times. The query builder hands over the same
        // connection without instantiating anything itself, and the hop through
        // `getModel()` is what types it: `Builder::getConnection()` is declared as
        // `ConnectionInterface`, which has no `getDriverName()`, while the model's
        // own `getConnection()` returns the concrete `Connection`.
        $driver = static::query()->getModel()->getConnection()->getDriverName();

        return $driver === 'sqlite'
            ? '('.implode(' || ', $parts).')'
            : 'CONCAT('.implode(', ', $parts).')';
    }

    /**
     * A raw expression, lowered and with its dashes folded to match the term.
     */
    private function foldedExpression(string $expression): string
    {
        $folded = $expression;

        foreach (self::DASHES as $dash) {
            $folded = "REPLACE({$folded}, '{$dash}', '-')";
        }

        return "LOWER({$folded})";
    }

    /**
     * `LOWER(CAST(col AS CHAR)) LIKE ?`, with the identifier quoted by the
     * grammar rather than interpolated.
     */
    private function foldedLike(Builder $query, string $column): string
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return "LOWER(CAST({$wrapped} AS CHAR)) LIKE ?";
    }
}
