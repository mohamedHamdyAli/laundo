<?php

namespace Laundo\SecondBrain\Parse;

use Laundo\SecondBrain\Support\Text;

/**
 * What an Eloquent class says about itself.
 *
 * Relationships are read out of the parsed call list rather than by matching
 * source text, and the pairing rule is the Laravel idiom itself: a relation
 * method contains `$this->belongsTo(` immediately followed by `Target::class`.
 * `PhpParser` records both, in order, so the target is simply the next
 * `::class` reference after the relation call. Nothing is guessed — a relation
 * method built some other way is recorded with a null target rather than with
 * a plausible one.
 *
 * Table names are taken from `protected $table` when it is declared and
 * otherwise from Laravel's own convention (snake_case plural of the class),
 * which is what every model here relies on.
 */
final class ModelAnalyzer
{
    private const RELATIONS = [
        'belongsTo' => 'belongs_to',
        'hasMany' => 'has_many',
        'hasOne' => 'has_one',
        'belongsToMany' => 'belongs_to_many',
        'hasManyThrough' => 'has_many_through',
        'hasOneThrough' => 'has_one_through',
        'morphTo' => 'morph_to',
        'morphMany' => 'morph_many',
        'morphOne' => 'morph_one',
        'morphToMany' => 'morph_to_many',
    ];

    /**
     * @param  array<string,mixed>  $class  one entry from PhpParser::parse()['classes']
     * @return array<string,mixed>|null  null when the class is not a model
     */
    public function analyse(array $class, string $source): ?array
    {
        if (! $this->looksLikeModel($class)) {
            return null;
        }

        $relations = [];
        $scopes = [];
        $accessors = [];

        foreach ($class['methods'] as $method) {
            if (str_starts_with($method['name'], 'scope') && strlen($method['name']) > 5) {
                $scopes[] = lcfirst(substr($method['name'], 5));
            }

            if (preg_match('/^get([A-Z].*)Attribute$/', $method['name'], $matches) === 1) {
                $accessors[] = Text::snake($matches[1]);
            }

            $relation = $this->relationIn($method);
            if ($relation !== null) {
                $relations[] = $relation;
            }
        }

        return [
            'table' => $this->tableFor($class, $source),
            // Whether the name was declared outright or derived from Laravel's
            // pluralisation rule. Carried so an inferred edge can say which.
            'table_declared' => preg_match('/protected\s+\$table\s*=/', $source) === 1,
            'fillable' => $this->arrayProperty($source, 'fillable'),
            'casts' => $this->castKeys($source),
            'relations' => $relations,
            'scopes' => $scopes,
            'accessors' => $accessors,
            'traits' => $class['traits'],
            'tenant_scoped' => in_array('App\\Trait\\BelongsToLaundry', $class['traits'], true),
            'searchable' => in_array('App\\Trait\\Scopes\\Searchable', $class['traits'], true),
            'permissioned' => in_array('App\\Trait\\DashboardModel', $class['traits'], true),
        ];
    }

    /**
     * @param  array<string,mixed>  $class
     */
    public function looksLikeModel(array $class): bool
    {
        foreach ($class['extends'] as $parent) {
            if (str_ends_with($parent, 'Eloquent\\Model') || str_ends_with($parent, '\\Authenticatable')) {
                return true;
            }
        }

        // `App\Models\Role` and friends extend Model through an alias; the path
        // check in the indexer catches those, and this stays a pure look at the
        // class so it can be asked about any class at all.
        return false;
    }

    /**
     * @param  array<string,mixed>  $method
     * @return array{name:string,type:string,target:?string,foreign_key:?string}|null
     */
    private function relationIn(array $method): ?array
    {
        $calls = $method['calls'] ?? [];

        foreach ($calls as $index => $call) {
            if (($call['class'] ?? null) !== '$this') {
                continue;
            }

            $type = self::RELATIONS[$call['method'] ?? ''] ?? null;
            if ($type === null) {
                continue;
            }

            // The target is the first `X::class` recorded after the relation
            // call. `morphTo()` legitimately has none.
            $target = null;
            for ($j = $index + 1; $j < count($calls); $j++) {
                $candidate = $calls[$j];
                if (($candidate['method'] ?? null) === null && ($candidate['class'] ?? '') !== '$this') {
                    $target = $candidate['class'];
                    break;
                }
                if (($candidate['class'] ?? null) === '$this') {
                    break; // a second relation call — this one has no target
                }
            }

            $foreignKey = null;
            foreach ($method['strings'] ?? [] as $string) {
                if (preg_match('/^[a-z0-9_]+_id$/', $string) === 1) {
                    $foreignKey = $string;
                    break;
                }
            }

            return [
                'name' => $method['name'],
                'type' => $type,
                'target' => $target,
                'foreign_key' => $foreignKey,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $class
     */
    private function tableFor(array $class, string $source): string
    {
        if (preg_match('/protected\s+\$table\s*=\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $matches) === 1) {
            return $matches[1];
        }

        return $this->pluralSnake($class['name']);
    }

    /**
     * Laravel's own convention, reduced to the cases this repository contains:
     * `OrderStatusLog` → `order_status_logs`, `City` → `cities`,
     * `Address` → `addresses`.
     */
    public function pluralSnake(string $studly): string
    {
        $snake = Text::snake($studly);

        if (preg_match('/(s|x|z|ch|sh)$/', $snake) === 1) {
            return $snake.'es';
        }
        if (preg_match('/[^aeiou]y$/', $snake) === 1) {
            return substr($snake, 0, -1).'ies';
        }

        return $snake.'s';
    }

    /** @return list<string> */
    private function arrayProperty(string $source, string $name): array
    {
        if (preg_match('/protected\s+\$'.preg_quote($name, '/').'\s*=\s*\[(.*?)\];/s', $source, $matches) !== 1) {
            return [];
        }

        preg_match_all('/[\'"]([a-zA-Z0-9_]+)[\'"]/', $matches[1], $found);

        return array_values(array_unique($found[1] ?? []));
    }

    /**
     * Cast keys, from either shape: the `protected $casts = [...]` property or
     * the `protected function casts(): array` method Laravel 11 introduced —
     * this codebase uses both.
     *
     * @return list<string>
     */
    private function castKeys(string $source): array
    {
        $keys = $this->arrayProperty($source, 'casts');

        if (preg_match('/function\s+casts\s*\(\s*\)\s*:\s*array\s*\{(.*?)\n\s{4}\}/s', $source, $matches) === 1) {
            preg_match_all('/[\'"]([a-zA-Z0-9_]+)[\'"]\s*=>/', $matches[1], $found);
            $keys = array_merge($keys, $found[1] ?? []);
        }

        return array_values(array_unique($keys));
    }
}
