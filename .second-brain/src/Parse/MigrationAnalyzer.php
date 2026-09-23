<?php

namespace Laundo\SecondBrain\Parse;

/**
 * The database, read from the migrations rather than from a connection.
 *
 * Deliberate: `php artisan` cannot boot on a box without MySQL here (the
 * service provider reads `languages` at boot), and a brain that only builds
 * where the database happens to be up is a brain nobody rebuilds. The
 * migrations are also the only place the *intent* is written down — a column
 * comment, a foreign key's delete behaviour, the reason for a unique key.
 *
 * Replayed in filename order, which is the order Laravel applies them, so
 * `add_platform_fee_to_orders` lands on the `orders` built ninety files
 * earlier and a dropped table disappears rather than lingering.
 */
final class MigrationAnalyzer
{
    /**
     * @param  list<array{path:string,source:string}>  $migrations  in filename order
     * @return array{tables:array<string,array<string,mixed>>,foreign_keys:list<array<string,string>>}
     */
    public function replay(array $migrations): array
    {
        $tables = [];
        $foreignKeys = [];

        foreach ($migrations as $migration) {
            $path = $migration['path'];
            $source = $migration['source'];

            foreach ($this->statements($source) as $statement) {
                [$operation, $table, $body] = $statement;

                if ($operation === 'drop') {
                    unset($tables[$table]);

                    continue;
                }

                if ($operation === 'rename') {
                    if (isset($tables[$table]) && $body !== '') {
                        $tables[$body] = $tables[$table];
                        $tables[$body]['name'] = $body;
                        unset($tables[$table]);
                    }

                    continue;
                }

                $tables[$table] ??= [
                    'name' => $table,
                    'columns' => [],
                    'indexes' => [],
                    'migrations' => [],
                    'created_by' => $path,
                    'summary' => '',
                ];

                if ($operation === 'create' && $tables[$table]['summary'] === '') {
                    $tables[$table]['summary'] = \Laundo\SecondBrain\Support\Text::summarise(
                        $this->leadingDocblock($source)
                    );
                    $tables[$table]['created_by'] = $path;
                }

                $tables[$table]['migrations'][] = $path;

                foreach ($this->columns($body) as $column) {
                    $tables[$table]['columns'][$column['name']] = $column;
                }

                foreach ($this->droppedColumns($body) as $dropped) {
                    unset($tables[$table]['columns'][$dropped]);
                }

                foreach ($this->indexes($body) as $index) {
                    $tables[$table]['indexes'][] = $index;
                }

                foreach ($this->foreignKeys($body, $table) as $key) {
                    $foreignKeys[] = $key + ['migration' => $path];
                }
            }
        }

        foreach ($tables as $name => $table) {
            $tables[$name]['migrations'] = array_values(array_unique($table['migrations']));
            $tables[$name]['indexes'] = array_values(array_unique($table['indexes']));
        }

        // A key pointing at a table a later migration dropped is not a key.
        $foreignKeys = array_values(array_filter(
            $foreignKeys,
            static fn (array $key) => isset($tables[$key['from']]) && isset($tables[$key['to']])
        ));

        ksort($tables);

        return ['tables' => $tables, 'foreign_keys' => $foreignKeys];
    }

    /**
     * `Schema::create('x', function ...)` and its siblings, with the closure
     * body captured by brace counting rather than by a greedy pattern — a
     * regex spanning two `Schema::table` calls in one migration would merge
     * their columns onto the first table.
     *
     * @return list<array{0:string,1:string,2:string}>  operation, table, body
     */
    private function statements(string $source): array
    {
        // **`up()` only.** Every migration in this repository carries a
        // `down()` that drops what `up()` created, so reading the whole file
        // created each table and immediately deleted it again — the replay
        // found fourteen tables out of ninety and nothing said so.
        $source = $this->upMethod($source);

        $found = [];

        if (preg_match_all('/Schema::drop(?:IfExists)?\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $drops, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($drops as $drop) {
                $found[] = [$drop[0][1], ['drop', $drop[1][0], '']];
            }
        }

        if (preg_match_all('/Schema::rename\(\s*[\'"]([a-z0-9_]+)[\'"]\s*,\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $renames, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($renames as $rename) {
                $found[] = [$rename[0][1], ['rename', $rename[1][0], $rename[2][0]]];
            }
        }

        $offset = 0;
        while (preg_match(
            '/Schema::(create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*\{/i',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
            $offset
        ) === 1) {
            $start = $matches[0][1] + strlen($matches[0][0]);
            $body = $this->balanced($source, $start);

            $found[] = [$matches[0][1], [strtolower($matches[1][0]), $matches[2][0], $body]];
            $offset = $start + max(1, strlen($body));
        }

        // Source order is apply order: a migration that creates a table and
        // then drops another one further down runs in exactly that sequence.
        usort($found, static fn ($a, $b) => $a[0] <=> $b[0]);

        return array_map(static fn (array $entry) => $entry[1], $found);
    }

    /**
     * The body of `up()`, by brace counting.
     *
     * A migration with no `up()` at all — none here, but a closure-based one is
     * legal — falls back to the whole file, which is the old behaviour and no
     * worse than refusing to read it.
     */
    private function upMethod(string $source): string
    {
        if (preg_match('/function\s+up\s*\(\s*\)\s*:?\s*\w*\s*\{/', $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        $start = $matches[0][1] + strlen($matches[0][0]);

        return $this->balanced($source, $start);
    }

    private function balanced(string $source, int $from): string
    {
        $depth = 1;
        $length = strlen($source);

        for ($i = $from; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $from, $i - $from);
                }
            }
        }

        return substr($source, $from);
    }

    /**
     * @return list<array{name:string,type:string,nullable:bool,comment:string}>
     */
    private function columns(string $body): array
    {
        $columns = [];

        // `$table->string('name', 191)->nullable()->comment('…')`
        $pattern = '/\$table->([a-zA-Z]+)\(\s*[\'"]([a-z0-9_]+)[\'"]([^;]*);/';

        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $type = $match[1];
            $name = $match[2];
            $tail = $match[3];

            if (in_array($type, ['index', 'unique', 'primary', 'dropColumn', 'dropIndex', 'dropUnique', 'dropForeign', 'renameColumn'], true)) {
                continue;
            }

            $comment = '';
            if (preg_match('/->comment\(\s*[\'"](.*?)[\'"]\s*\)/s', $tail, $found) === 1) {
                $comment = $found[1];
            }

            $columns[] = [
                'name' => $name,
                'type' => $type,
                'nullable' => str_contains($tail, '->nullable('),
                'comment' => $comment,
            ];
        }

        // `$table->id()` and `$table->timestamps()` take no column name.
        if (preg_match('/\$table->id\(\s*\)/', $body) === 1) {
            array_unshift($columns, ['name' => 'id', 'type' => 'id', 'nullable' => false, 'comment' => '']);
        }
        if (preg_match('/\$table->timestamps\(\s*\)/', $body) === 1) {
            $columns[] = ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'comment' => ''];
            $columns[] = ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'comment' => ''];
        }
        if (preg_match('/\$table->softDeletes\(\s*\)/', $body) === 1) {
            $columns[] = ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true, 'comment' => ''];
        }

        return $columns;
    }

    /** @return list<string> */
    private function droppedColumns(string $body): array
    {
        $dropped = [];

        if (preg_match_all('/->dropColumn\(\s*(\[[^\]]*\]|[\'"][a-z0-9_]+[\'"])/i', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/', $match[1], $names);
                $dropped = array_merge($dropped, $names[1] ?? []);
            }
        }

        return array_values(array_unique($dropped));
    }

    /** @return list<string> */
    private function indexes(string $body): array
    {
        $indexes = [];

        if (preg_match_all('/->(index|unique|primary|fullText)\(\s*(\[[^\]]*\]|[\'"][a-z0-9_]+[\'"])?/i', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (! isset($match[2]) || $match[2] === '') {
                    continue;
                }
                preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/', $match[2], $names);
                if ($names[1] ?? []) {
                    $indexes[] = strtolower($match[1]).':'.implode(',', $names[1]);
                }
            }
        }

        return $indexes;
    }

    /**
     * `foreignId('order_id')->constrained('orders')` and the explicit
     * `foreign('x')->references('id')->on('y')`. Where `constrained()` is bare,
     * Laravel derives the table from the column, which is what this does too.
     *
     * @return list<array{from:string,column:string,to:string}>
     */
    private function foreignKeys(string $body, string $table): array
    {
        $keys = [];

        if (preg_match_all('/foreignId(?:For)?\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)((?:(?!;).)*)/s', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $column = $match[1];
                $tail = $match[2];

                if (! str_contains($tail, 'constrained')) {
                    continue;
                }

                $target = null;
                if (preg_match('/constrained\(\s*[\'"]([a-z0-9_]+)[\'"]/', $tail, $found) === 1) {
                    $target = $found[1];
                } elseif (str_ends_with($column, '_id')) {
                    $target = (new ModelAnalyzer)->pluralSnake(substr($column, 0, -3));
                }

                if ($target !== null) {
                    $keys[] = ['from' => $table, 'column' => $column, 'to' => $target];
                }
            }
        }

        if (preg_match_all(
            '/foreign\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)[^;]*?->on\(\s*[\'"]([a-z0-9_]+)[\'"]/s',
            $body,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $keys[] = ['from' => $table, 'column' => $match[1], 'to' => $match[2]];
            }
        }

        return $keys;
    }

    private function leadingDocblock(string $source): string
    {
        if (preg_match('#/\*\*(.*?)\*/#s', $source, $matches) === 1) {
            return $matches[0];
        }

        return '';
    }
}
