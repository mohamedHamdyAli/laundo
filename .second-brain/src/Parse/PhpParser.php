<?php

namespace Laundo\SecondBrain\Parse;

use Laundo\SecondBrain\Support\Text;

/**
 * One pass over one PHP file with `token_get_all`.
 *
 * Why the tokenizer and not a regex, and not nikic/php-parser:
 *
 * A regex cannot tell `new Order(` inside a string from one in code, and this
 * codebase has Arabic prose in almost every docblock — a regex over that finds
 * things that are not there. `php-parser` would do it properly and is a
 * composer dependency; the brief says add none, and `ext-tokenizer` ships with
 * PHP and is already loaded here.
 *
 * What is deliberately approximate, so a caller knows what it is holding:
 *
 * - **Calls** are resolved only where the receiver's class is knowable without
 *   running anything: `new X`, `X::y()`, `$this->method()`, and
 *   `$this->prop->method()` where `prop` was assigned from a typed constructor
 *   parameter. That last one is the whole layer contract in this project —
 *   a controller holding `private readonly offerCrudService $offerService` —
 *   so it covers the edges that matter and invents none.
 * - A call on a local variable is **not** guessed at. A wrong edge in a graph
 *   somebody is about to trust is worse than a missing one.
 */
final class PhpParser
{
    /** @var list<array{0:int|string,1:string,2:int}|string> */
    private array $tokens = [];

    private int $i = 0;

    private string $namespace = '';

    /** @var array<string,string> alias => fully qualified */
    private array $imports = [];

    /**
     * @return array{
     *     namespace:string,
     *     imports:array<string,string>,
     *     classes:list<array<string,mixed>>,
     *     calls_at_top:list<string>,
     *     views:list<string>,
     *     routes_referenced:list<string>,
     * }
     */
    public function parse(string $source): array
    {
        $this->tokens = token_get_all($source);
        $this->i = 0;
        $this->namespace = '';
        $this->imports = [];

        $classes = [];
        $views = [];
        $routes = [];
        $topCalls = [];

        $pendingDoc = null;

        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            if (is_array($token)) {
                switch ($token[0]) {
                    case T_DOC_COMMENT:
                        $pendingDoc = $token[1];
                        $this->i++;

                        continue 2;

                    case T_NAMESPACE:
                        $this->i++;
                        $this->namespace = $this->readQualifiedName();

                        continue 2;

                    case T_USE:
                        // `use` at the top level is an import; inside a class
                        // body it is a trait and is handled by readClass().
                        //
                        // The third shape is a closure's captured variables,
                        // `function () use ($x) { … }`, which is not an import
                        // at all. Reading it as one used to consume everything
                        // up to the next `;` — skipping over whatever followed.
                        // No top-level closure in this repository captures
                        // anything today, so this was latent; a route file that
                        // did would have lost the rest of its statement.
                        if ($this->previousSignificant() === ')') {
                            $this->i++;
                            $this->skipParenthesised();

                            continue 2;
                        }

                        $this->i++;
                        $this->readImports();

                        continue 2;

                    case T_CLASS:
                    case T_INTERFACE:
                    case T_TRAIT:
                    case T_ENUM:
                        // `Foo::class` — the constant, not a declaration.
                        if ($this->previousSignificant() === '::') {
                            $this->i++;

                            continue 2;
                        }
                        $class = $this->readClass($this->kindOf($token[0]), $pendingDoc);
                        $pendingDoc = null;
                        if ($class !== null) {
                            $classes[] = $class;
                            $views = array_merge($views, $class['_views']);
                            $routes = array_merge($routes, $class['_routes']);
                            unset($classes[count($classes) - 1]['_views'], $classes[count($classes) - 1]['_routes']);
                        }

                        continue 2;

                    case T_CONSTANT_ENCAPSED_STRING:
                        // Top-level `view('...')`/`route('...')`, which is how
                        // routes/web.php and the Blade-free closures read.
                        break;
                }

            }

            // Top-level helper calls worth recording — this is where
            // routes/web.php lives, so `view()` and `route()` here are real.
            $at = $this->i;

            $helper = $this->helperCallAt($at);
            if ($helper !== null) {
                [$name, $argument] = $helper;
                if ($name === 'view') {
                    $views[] = $argument;
                } elseif ($name === 'route') {
                    $routes[] = $argument;
                }
            }
            $this->i = $at;

            $topCall = $this->staticOrNewAt($at);
            if ($topCall !== null) {
                $topCalls[] = $topCall;
            }

            $this->i = $at + 1;
        }

        return [
            'namespace' => $this->namespace,
            'imports' => $this->imports,
            'classes' => $classes,
            'calls_at_top' => array_values(array_unique($topCalls)),
            'views' => array_values(array_unique(array_filter($views))),
            'routes_referenced' => array_values(array_unique(array_filter($routes))),
        ];
    }

    private function kindOf(int $id): string
    {
        return match ($id) {
            T_CLASS => 'class',
            T_INTERFACE => 'interface',
            T_TRAIT => 'trait',
            T_ENUM => 'enum',
            default => 'class',
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readClass(string $kind, ?string $doc): ?array
    {
        $line = is_array($this->tokens[$this->i]) ? $this->tokens[$this->i][2] : 0;
        $this->i++; // past the keyword

        $name = $this->readIdentifier();
        if ($name === '') {
            return null; // anonymous class — `new class extends Migration`
        }

        $extends = [];
        $implements = [];
        $mode = null;

        // Walk the header until the body opens.
        while ($this->i < count($this->tokens)) {
            $token = $this->tokens[$this->i];

            if ($token === '{') {
                break;
            }
            if (is_array($token)) {
                if ($token[0] === T_EXTENDS) {
                    $mode = 'extends';
                    $this->i++;

                    continue;
                }
                if ($token[0] === T_IMPLEMENTS) {
                    $mode = 'implements';
                    $this->i++;

                    continue;
                }
                if ($this->isNamePart($token[0])) {
                    $qualified = $this->resolve($this->readQualifiedName());
                    if ($mode === 'extends') {
                        $extends[] = $qualified;
                    } elseif ($mode === 'implements') {
                        $implements[] = $qualified;
                    }

                    continue;
                }
            }
            $this->i++;
        }

        $body = $this->readClassBody();

        return [
            'kind' => $kind,
            'name' => $name,
            'fqcn' => ($this->namespace !== '' ? $this->namespace.'\\' : '').$name,
            'line' => $line,
            'doc' => $doc,
            'summary' => Text::summarise($doc),
            'extends' => array_values(array_unique($extends)),
            'implements' => array_values(array_unique($implements)),
            'traits' => $body['traits'],
            'constants' => $body['constants'],
            'properties' => $body['properties'],
            'methods' => $body['methods'],
            'references' => $body['references'],
            'strings' => $body['strings'],
            '_views' => $body['views'],
            '_routes' => $body['routes'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readClassBody(): array
    {
        $traits = [];
        $methods = [];
        $properties = [];
        $constants = [];
        $references = [];
        $views = [];
        $routes = [];
        $strings = [];

        // `$this->x = $x` where `$x` is a typed constructor parameter. This is
        // what makes `$this->orders->place()` resolvable.
        $propertyTypes = [];

        if (($this->tokens[$this->i] ?? null) !== '{') {
            return [
                'traits' => $traits, 'methods' => $methods, 'properties' => $properties,
                'constants' => $constants, 'references' => $references, 'views' => $views,
                'routes' => $routes, 'strings' => $strings, 'property_types' => $propertyTypes,
            ];
        }

        $depth = 0;
        $pendingDoc = null;
        $modifiers = [];

        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            if ($token === '{') {
                $depth++;
                $this->i++;

                continue;
            }
            if ($token === '}') {
                $depth--;
                $this->i++;
                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if (is_array($token)) {
                switch ($token[0]) {
                    case T_DOC_COMMENT:
                        $pendingDoc = $token[1];
                        $this->i++;

                        continue 2;

                    case T_PUBLIC:
                    case T_PROTECTED:
                    case T_PRIVATE:
                    case T_STATIC:
                    case T_ABSTRACT:
                    case T_FINAL:
                    case T_READONLY:
                        $modifiers[] = strtolower($token[1]);
                        $this->i++;

                        continue 2;

                    case T_USE:
                        if ($depth === 1) {
                            $this->i++;
                            foreach ($this->readTraitNames() as $trait) {
                                $traits[] = $trait;
                                $references[] = $trait;
                            }

                            continue 2;
                        }
                        break;

                    case T_CONST:
                        $this->i++;
                        $constName = $this->readIdentifier();
                        if ($constName !== '') {
                            $constants[] = $constName;
                        }
                        $modifiers = [];

                        continue 2;

                    case T_VARIABLE:
                        if ($depth === 1) {
                            $properties[] = ['name' => ltrim($token[1], '$'), 'modifiers' => $modifiers];
                        }
                        $modifiers = [];
                        $this->i++;

                        continue 2;

                    case T_FUNCTION:
                        $method = $this->readMethod($modifiers, $pendingDoc);
                        $modifiers = [];
                        $pendingDoc = null;

                        if ($method !== null) {
                            if ($method['name'] === '__construct') {
                                $propertyTypes = $method['assigned_properties'] + $propertyTypes;
                            }
                            $methods[] = $method;
                            $references = array_merge($references, $method['references']);
                            $views = array_merge($views, $method['views']);
                            $routes = array_merge($routes, $method['routes']);
                            $strings = array_merge($strings, $method['strings']);
                        }

                        continue 2;
                }
            }

            $at = $this->i;
            $reference = $this->staticOrNewAt($at);
            if ($reference !== null) {
                $references[] = $reference;
            }

            $this->i = $at + 1;
        }

        // Second pass over the recorded raw calls, now that the constructor's
        // property map is complete. A controller's `index()` is parsed before
        // its `__construct()` in no file here, but a service with the
        // constructor at the bottom would otherwise lose every edge.
        foreach ($methods as $index => $method) {
            $resolved = [];
            foreach ($method['property_calls'] as $call) {
                $type = $propertyTypes[$call['property']] ?? null;
                if ($type !== null) {
                    $resolved[] = ['class' => $type, 'method' => $call['method']];
                    $references[] = $type;
                }
            }
            $methods[$index]['calls'] = array_merge($method['calls'], $resolved);
            unset($methods[$index]['property_calls']);
        }

        return [
            'traits' => array_values(array_unique($traits)),
            'methods' => $methods,
            'properties' => $properties,
            'constants' => $constants,
            'references' => array_values(array_unique(array_filter($references))),
            'views' => $views,
            'routes' => $routes,
            'strings' => array_values(array_unique($strings)),
            'property_types' => $propertyTypes,
        ];
    }

    /**
     * @param  list<string>  $modifiers
     * @return array<string,mixed>|null
     */
    private function readMethod(array $modifiers, ?string $doc): ?array
    {
        $line = is_array($this->tokens[$this->i]) ? $this->tokens[$this->i][2] : 0;
        $this->i++; // past `function`

        // `fn` and closures assigned to properties: no name follows.
        $name = $this->readIdentifier();
        if ($name === '') {
            $this->skipToBodyEnd();

            return null;
        }

        $parameters = [];
        $assigned = [];

        // Parameter list.
        if (($this->tokens[$this->i] ?? null) === '(') {
            $depth = 0;
            $currentType = null;
            $promoted = false;

            while ($this->i < count($this->tokens)) {
                $token = $this->tokens[$this->i];

                if ($token === '(') {
                    $depth++;
                    $this->i++;

                    continue;
                }
                if ($token === ')') {
                    $depth--;
                    $this->i++;
                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }
                if ($token === ',') {
                    $currentType = null;
                    $promoted = false;
                    $this->i++;

                    continue;
                }

                if (is_array($token)) {
                    if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true)) {
                        $promoted = true;
                        $this->i++;

                        continue;
                    }
                    if ($this->isNamePart($token[0]) && $depth === 1) {
                        $raw = $this->readQualifiedName();
                        if ($raw !== '' && ! $this->isScalarType($raw)) {
                            $currentType = $this->resolve($raw);
                        }

                        continue;
                    }
                    if ($token[0] === T_VARIABLE && $depth === 1) {
                        $variable = ltrim($token[1], '$');
                        $parameters[] = ['name' => $variable, 'type' => $currentType];
                        if ($promoted && $currentType !== null) {
                            $assigned[$variable] = $currentType;
                        }
                        $this->i++;

                        continue;
                    }
                }

                $this->i++;
            }
        }

        // Return type, up to `{` or `;`.
        $returnType = null;
        while ($this->i < count($this->tokens)) {
            $token = $this->tokens[$this->i];
            if ($token === '{' || $token === ';') {
                break;
            }
            if (is_array($token) && $this->isNamePart($token[0])) {
                $raw = $this->readQualifiedName();
                if ($raw !== '' && ! $this->isScalarType($raw)) {
                    $returnType = $this->resolve($raw);
                }

                continue;
            }
            $this->i++;
        }

        $body = $this->readMethodBody();

        foreach ($body['assignments'] as $property => $variable) {
            $type = null;
            foreach ($parameters as $parameter) {
                if ($parameter['name'] === $variable) {
                    $type = $parameter['type'];
                    break;
                }
            }
            if ($type !== null) {
                $assigned[$property] = $type;
            }
        }

        return [
            'name' => $name,
            'line' => $line,
            'visibility' => in_array('private', $modifiers, true) ? 'private'
                : (in_array('protected', $modifiers, true) ? 'protected' : 'public'),
            'static' => in_array('static', $modifiers, true),
            'doc' => $doc,
            'summary' => Text::summarise($doc, 160),
            'parameters' => $parameters,
            'return_type' => $returnType,
            'assigned_properties' => $assigned,
            'calls' => $body['calls'],
            'property_calls' => $body['property_calls'],
            'references' => array_values(array_unique(array_merge(
                $body['references'],
                array_values(array_filter(array_column($parameters, 'type'))),
                $returnType !== null ? [$returnType] : [],
            ))),
            'views' => $body['views'],
            'routes' => $body['routes'],
            'helpers' => $body['helpers'],
            'strings' => $body['strings'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readMethodBody(): array
    {
        $calls = [];
        $propertyCalls = [];
        $references = [];
        $views = [];
        $routes = [];
        $helpers = [];
        $strings = [];
        $assignments = [];

        if (($this->tokens[$this->i] ?? null) === ';') {
            $this->i++; // abstract or interface method
        }

        if (($this->tokens[$this->i] ?? null) !== '{') {
            return [
                'calls' => $calls, 'property_calls' => $propertyCalls, 'references' => $references,
                'views' => $views, 'routes' => $routes, 'helpers' => $helpers,
                'strings' => $strings, 'assignments' => $assignments,
            ];
        }

        $depth = 0;
        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            if ($token === '{') {
                $depth++;
                $this->i++;

                continue;
            }
            if ($token === '}') {
                $depth--;
                $this->i++;
                if ($depth === 0) {
                    break;
                }

                continue;
            }

            // Every lookup below may move the cursor. `$at` is the anchor, and
            // a lookup that finds nothing must leave the cursor exactly where
            // it was — a probe that consumed a token on the way to answering
            // "no" would step over the call that started on the next one.
            $at = $this->i;

            // `new X(` / `X::y(`
            $static = $this->staticCallAt($at);
            if ($static !== null) {
                $references[] = $static['class'];
                $calls[] = $static;

                continue;
            }
            $this->i = $at;

            $new = $this->newAt($at);
            if ($new !== null) {
                $references[] = $new;
                $calls[] = ['class' => $new, 'method' => '__construct'];

                continue;
            }
            $this->i = $at;

            // `$this->prop->method(` and `$this->method(`
            $chain = $this->thisChainAt($at);
            if ($chain !== null) {
                if ($chain['property'] === null) {
                    $calls[] = ['class' => '$this', 'method' => $chain['method']];
                } else {
                    $propertyCalls[] = $chain;
                }

                continue;
            }
            $this->i = $at;

            // `$this->prop = $param`
            $assignment = $this->thisAssignmentAt($at);
            if ($assignment !== null) {
                $assignments[$assignment[0]] = $assignment[1];

                continue;
            }
            $this->i = $at;

            $helper = $this->helperCallAt($at);
            if ($helper !== null) {
                [$name, $argument] = $helper;
                $helpers[] = $name;
                if ($name === 'view') {
                    $views[] = $argument;
                } elseif ($name === 'route') {
                    $routes[] = $argument;
                }
            }

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = trim($token[1], "'\"");
                if ($value !== '' && mb_strlen($value) <= 120) {
                    $strings[] = $value;
                }
            }

            $this->i++;
        }

        return [
            'calls' => $calls,
            'property_calls' => $propertyCalls,
            'references' => array_values(array_unique(array_filter($references))),
            'views' => array_values(array_unique($views)),
            'routes' => array_values(array_unique($routes)),
            'helpers' => array_values(array_unique($helpers)),
            'strings' => array_values(array_unique($strings)),
            'assignments' => $assignments,
        ];
    }

    // ---------------------------------------------------------------- lookups

    /** `Foo::bar(` — returns the resolved class and the method. */
    private function staticCallAt(int $at): ?array
    {
        $token = $this->tokens[$at];
        if (! is_array($token) || ! $this->isNamePart($token[0])) {
            return null;
        }
        // Not a static call if something attached precedes it.
        $previous = $this->previousSignificant();
        if (in_array($previous, ['->', '::', '$'], true) || $previous === 'function') {
            return null;
        }

        $save = $this->i;
        $this->i = $at;
        $name = $this->readQualifiedName();

        if ($name === '' || ($this->tokens[$this->i] ?? null) === null) {
            $this->i = $save + 1;

            return null;
        }

        $next = $this->tokens[$this->i];
        if (! is_array($next) || $next[0] !== T_DOUBLE_COLON) {
            $this->i = $save;

            return null;
        }

        $this->i++; // past ::
        $member = $this->readIdentifier();

        if ($member === '' || strtolower($member) === 'class') {
            // `Foo::class` is still a reference, just not a call.
            return ['class' => $this->resolve($name), 'method' => null];
        }

        return ['class' => $this->resolve($name), 'method' => $member];
    }

    private function newAt(int $at): ?string
    {
        $token = $this->tokens[$at];
        if (! is_array($token) || $token[0] !== T_NEW) {
            return null;
        }

        $this->i = $at + 1;
        $this->skipTrivia();

        $next = $this->tokens[$this->i] ?? null;
        if (is_array($next) && $next[0] === T_CLASS) {
            return null; // anonymous class
        }
        if (! is_array($next) || ! $this->isNamePart($next[0])) {
            return null;
        }

        return $this->resolve($this->readQualifiedName());
    }

    /**
     * `$this->method(` or `$this->property->method(`.
     *
     * @return array{property:?string,method:string}|null
     */
    private function thisChainAt(int $at): ?array
    {
        $token = $this->tokens[$at];
        if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
            return null;
        }

        $j = $this->nextSignificantIndex($at);
        $arrow = $this->tokens[$j] ?? null;
        if (! is_array($arrow) || $arrow[0] !== T_OBJECT_OPERATOR) {
            return null;
        }

        $k = $this->nextSignificantIndex($j);
        $first = $this->tokens[$k] ?? null;
        if (! is_array($first) || ! $this->isNamePart($first[0])) {
            return null;
        }
        $firstName = $first[1];

        $l = $this->nextSignificantIndex($k);
        $after = $this->tokens[$l] ?? null;

        if ($after === '(') {
            $this->i = $l + 1;

            return ['property' => null, 'method' => $firstName];
        }

        if (is_array($after) && $after[0] === T_OBJECT_OPERATOR) {
            $m = $this->nextSignificantIndex($l);
            $second = $this->tokens[$m] ?? null;
            if (is_array($second) && $this->isNamePart($second[0])) {
                $n = $this->nextSignificantIndex($m);
                if (($this->tokens[$n] ?? null) === '(') {
                    $this->i = $n + 1;

                    return ['property' => $firstName, 'method' => $second[1]];
                }
            }
        }

        return null;
    }

    /**
     * `$this->foo = $bar;` — the classic constructor assignment.
     *
     * @return array{0:string,1:string}|null
     */
    private function thisAssignmentAt(int $at): ?array
    {
        $token = $this->tokens[$at];
        if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
            return null;
        }

        $j = $this->nextSignificantIndex($at);
        $arrow = $this->tokens[$j] ?? null;
        if (! is_array($arrow) || $arrow[0] !== T_OBJECT_OPERATOR) {
            return null;
        }

        $k = $this->nextSignificantIndex($j);
        $property = $this->tokens[$k] ?? null;
        if (! is_array($property) || ! $this->isNamePart($property[0])) {
            return null;
        }

        $l = $this->nextSignificantIndex($k);
        if (($this->tokens[$l] ?? null) !== '=') {
            return null;
        }

        $m = $this->nextSignificantIndex($l);
        $value = $this->tokens[$m] ?? null;
        if (! is_array($value) || $value[0] !== T_VARIABLE) {
            return null;
        }

        $this->i = $m + 1;

        return [$property[1], ltrim($value[1], '$')];
    }

    /** `view('x.y')` / `route('a.b')` / `config('k')` and friends. */
    private function helperCallAt(int $at): ?array
    {
        $token = $this->tokens[$at];
        if (! is_array($token) || $token[0] !== T_STRING) {
            return null;
        }

        $name = strtolower($token[1]);
        if (! in_array($name, ['view', 'route', 'config', 'trans', '__', 'dispatch', 'abort_unless', 'abort_if'], true)) {
            return null;
        }
        if (in_array($this->previousSignificant(), ['->', '::', 'function'], true)) {
            return null;
        }

        $j = $this->nextSignificantIndex($at);
        if (($this->tokens[$j] ?? null) !== '(') {
            return null;
        }

        $k = $this->nextSignificantIndex($j);
        $argument = $this->tokens[$k] ?? null;

        $value = (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING)
            ? trim($argument[1], "'\"")
            : '';

        return [$name, $value];
    }

    private function staticOrNewAt(int $at): ?string
    {
        $new = $this->newAt($at);
        if ($new !== null) {
            return $new;
        }

        $static = $this->staticCallAt($at);

        return $static['class'] ?? null;
    }

    // ------------------------------------------------------------- primitives

    private function readImports(): void
    {
        $buffer = '';
        $alias = null;
        $group = null;

        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            if ($token === ';' || $token === '{' && $buffer === '') {
                break;
            }

            if (is_array($token)) {
                if ($token[0] === T_FUNCTION || $token[0] === T_CONST) {
                    // `use function ...` — not a class import.
                    while ($this->i < $count && $this->tokens[$this->i] !== ';') {
                        $this->i++;
                    }

                    return;
                }
                if ($token[0] === T_AS) {
                    $this->i++;
                    $alias = $this->readIdentifier();

                    continue;
                }
                if ($this->isNamePart($token[0])) {
                    $buffer = $this->readQualifiedName();

                    continue;
                }
            }

            if ($token === '{') {
                $group = rtrim($buffer, '\\');
                $this->i++;

                continue;
            }
            if ($token === ',' || $token === '}') {
                $this->recordImport($group, $buffer, $alias);
                $buffer = '';
                $alias = null;
                if ($token === '}') {
                    $group = null;
                }
                $this->i++;

                continue;
            }

            $this->i++;
        }

        $this->recordImport($group, $buffer, $alias);
        $this->i++;
    }

    private function recordImport(?string $group, string $name, ?string $alias): void
    {
        $name = trim($name, '\\');
        if ($name === '') {
            return;
        }

        $fqcn = $group !== null ? $group.'\\'.$name : $name;

        // `strrpos` returns false for a global import such as `use RuntimeException;`
        // and `(int) false` is 0, which would register the alias as
        // `untimeException` and leave every mention of the class resolving to
        // the current namespace instead.
        $separator = strrpos($fqcn, '\\');
        $short = $alias ?? ($separator === false ? $fqcn : substr($fqcn, $separator + 1));

        $this->imports[$short] = $fqcn;
    }

    /** @return list<string> */
    private function readTraitNames(): array
    {
        $names = [];
        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            if ($token === ';') {
                $this->i++;
                break;
            }
            if ($token === '{') {
                // `use A { ... }` conflict block — skip it wholesale.
                $depth = 0;
                while ($this->i < $count) {
                    if ($this->tokens[$this->i] === '{') {
                        $depth++;
                    }
                    if ($this->tokens[$this->i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $this->i++;
                            break;
                        }
                    }
                    $this->i++;
                }
                break;
            }
            if (is_array($token) && $this->isNamePart($token[0])) {
                $names[] = $this->resolve($this->readQualifiedName());

                continue;
            }

            $this->i++;
        }

        return $names;
    }

    private function readQualifiedName(): string
    {
        $this->skipTrivia();
        $name = '';
        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];
            if (! is_array($token) || ! $this->isNamePart($token[0])) {
                break;
            }
            $name .= $token[1];
            $this->i++;

            // A name may be split across T_STRING / T_NS_SEPARATOR on older
            // tokenizers; on 8.x it usually arrives whole.
            $next = $this->tokens[$this->i] ?? null;
            if (! is_array($next) || ! $this->isNamePart($next[0])) {
                break;
            }
        }

        return trim($name);
    }

    private function readIdentifier(): string
    {
        $this->skipTrivia();
        $token = $this->tokens[$this->i] ?? null;

        if (is_array($token) && ($token[0] === T_STRING || $this->isNamePart($token[0]))) {
            $this->i++;

            return $token[1];
        }

        return '';
    }

    /** Skip a balanced `( … )` group starting at or after the cursor. */
    private function skipParenthesised(): void
    {
        $count = count($this->tokens);
        $depth = 0;

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    $this->i++;

                    return;
                }
            } elseif ($depth === 0 && ($token === ';' || $token === '{')) {
                return;
            }

            $this->i++;
        }
    }

    private function skipTrivia(): void
    {
        $count = count($this->tokens);
        while ($this->i < $count) {
            $token = $this->tokens[$this->i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $this->i++;

                continue;
            }
            break;
        }
    }

    private function skipToBodyEnd(): void
    {
        $count = count($this->tokens);
        $depth = 0;
        $started = false;

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];
            if ($token === '{') {
                $depth++;
                $started = true;
            } elseif ($token === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    $this->i++;

                    return;
                }
            } elseif ($token === ';' && ! $started) {
                $this->i++;

                return;
            }
            $this->i++;
        }
    }

    private function nextSignificantIndex(int $from): int
    {
        $count = count($this->tokens);
        $j = $from + 1;

        while ($j < $count) {
            $token = $this->tokens[$j];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;

                continue;
            }
            break;
        }

        return $j;
    }

    private function previousSignificant(): string
    {
        $j = $this->i - 1;

        while ($j >= 0) {
            $token = $this->tokens[$j];
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j--;

                    continue;
                }

                return $token[0] === T_DOUBLE_COLON ? '::'
                    : ($token[0] === T_OBJECT_OPERATOR ? '->' : strtolower($token[1]));
            }

            return (string) $token;
        }

        return '';
    }

    private function isNamePart(int|string $id): bool
    {
        if (! is_int($id)) {
            return false;
        }

        // T_NAME_* exist from PHP 8.0; T_STRING/T_NS_SEPARATOR keep the older
        // shape working, which costs two comparisons.
        return in_array($id, array_filter([
            T_STRING,
            T_NS_SEPARATOR,
            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
            defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : null,
        ], static fn ($v) => $v !== null), true);
    }

    private function isScalarType(string $name): bool
    {
        return in_array(strtolower(ltrim($name, '?\\')), [
            'int', 'float', 'string', 'bool', 'array', 'void', 'mixed', 'null',
            'callable', 'iterable', 'object', 'never', 'false', 'true', 'static', 'self', 'parent',
        ], true);
    }

    /** Alias or relative name to a fully qualified one, using this file's imports. */
    private function resolve(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $first = $segments[0];

        if (isset($this->imports[$first])) {
            $segments[0] = $this->imports[$first];

            return implode('\\', $segments);
        }

        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return $name;
        }

        return ($this->namespace !== '' ? $this->namespace.'\\' : '').$name;
    }
}
