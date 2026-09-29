<?php

namespace App\Support\Spreadsheet;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One column of a sheet.
 *
 * The header is the field's own key (`name_ar`, `country_id`, `status`), not a
 * translated label: a sheet is exported, edited and imported back, and a header
 * that changed with the panel's language would stop matching on the way in.
 *
 * A column is either **importable** — it maps to a field the screen's own form
 * accepts — or **for reading only**: a helper printed beside an id so a person
 * editing the sheet can see what `country_id = 3` is. Read-only columns are
 * ignored on import, so a stale helper can never overwrite anything.
 */
final class Column
{
    private ?Closure $value = null;

    private bool $importable = true;

    private bool $translatable = false;

    private function __construct(public readonly string $key) {}

    public static function make(string $key): self
    {
        return new self($key);
    }

    /**
     * Printed on export, ignored on import.
     */
    public static function readOnly(string $key, Closure $value): self
    {
        $column = new self($key);
        $column->value = $value;
        $column->importable = false;

        return $column;
    }

    /**
     * How the value is read off a row on export. Defaults to the attribute.
     */
    public function value(Closure $value): self
    {
        $this->value = $value;

        return $this;
    }

    /**
     * A `{"en":…,"ar":…}` column: one sheet column per language, `name_en`,
     * `name_ar`, folded back into the array the form posts.
     */
    public function translatable(): self
    {
        $this->translatable = true;

        return $this;
    }

    public function isImportable(): bool
    {
        return $this->importable;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    /**
     * The sheet headers this column occupies.
     *
     * @param  array<int, string>  $languages
     * @return array<int, string>
     */
    public function headers(array $languages): array
    {
        if (! $this->translatable) {
            return [$this->key];
        }

        return array_map(fn (string $code) => $this->key.'_'.$code, $languages);
    }

    /**
     * The cells this column writes for one row.
     *
     * @param  array<int, string>  $languages
     * @return array<int, scalar|null>
     */
    public function cells(Model $row, array $languages): array
    {
        $value = $this->value ? ($this->value)($row) : $row->getAttribute($this->key);

        if ($this->translatable) {
            // The accessor hands back a stdClass; a raw string is decoded here.
            $decoded = is_string($value) ? json_decode($value) : $value;

            return array_map(
                fn (string $code) => is_object($decoded) ? ($decoded->{$code} ?? null) : null,
                $languages
            );
        }

        return [self::scalar($value)];
    }

    /**
     * @return scalar|null
     */
    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? 1 : 0,
            is_scalar($value) => $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
