<?php

namespace Laundo\SecondBrain\Parse;

use Laundo\SecondBrain\Support\Json;
use Laundo\SecondBrain\Support\Paths;

/**
 * The route table, preferably from Laravel and otherwise from the source.
 *
 * `php artisan route:list --json` is the truth: it resolves the prefixes and
 * groups declared in `bootstrap/app.php`, expands `Auth::routes()`, and carries
 * the middleware stack — which is where the `permission:` slug lives, and the
 * permission is half of what anybody asks this brain about a route.
 *
 * It is run with a **sqlite override** because this application reads the
 * `languages` table while booting, so artisan cannot start against a MySQL
 * server that is not there. The override is passed as environment, never
 * written anywhere, and the command is read-only.
 *
 * When artisan cannot run at all the static reader takes over. It is honest
 * about being second best: it reports `source: static` and the indexer records
 * that, so a thin route list is visible as a degraded index rather than as a
 * project with few routes.
 */
final class RouteCollector
{
    public function __construct(private readonly Paths $paths) {}

    /**
     * @return array{source:string,routes:list<array<string,mixed>>,error:?string}
     */
    public function collect(): array
    {
        $fromArtisan = $this->fromArtisan();

        if ($fromArtisan['routes'] !== []) {
            return $fromArtisan;
        }

        $static = $this->fromSource();

        return [
            'source' => 'static',
            'routes' => $static,
            'error' => $fromArtisan['error'],
        ];
    }

    /**
     * @return array{source:string,routes:list<array<string,mixed>>,error:?string}
     */
    private function fromArtisan(): array
    {
        $php = PHP_BINARY;
        $artisan = $this->paths->abs('artisan');

        if (! is_file($artisan)) {
            return ['source' => 'artisan', 'routes' => [], 'error' => 'artisan not found'];
        }

        $environment = [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'APP_ENV' => 'local',
        ];

        $result = \Laundo\SecondBrain\Support\Process::run(
            [$php, $artisan, 'route:list', '--json'],
            $this->paths->root(),
            $environment,
            90,
        );

        $stdout = $result['stdout'];
        $stderr = $result['stderr'];

        // Laravel prints warnings before the JSON on some setups; take the
        // array and nothing before it.
        $start = strpos($stdout, '[');
        $decoded = $start === false ? null : json_decode(substr($stdout, $start), true);

        if (! is_array($decoded)) {
            $error = trim($stderr) !== '' ? substr(trim($stderr), 0, 300) : 'route:list produced no JSON';

            return ['source' => 'artisan', 'routes' => [], 'error' => $error];
        }

        $routes = [];
        foreach ($decoded as $route) {
            $action = $route['action'] ?? '';
            [$controller, $method] = $this->splitAction($action);

            $routes[] = [
                'uri' => '/'.ltrim((string) ($route['uri'] ?? ''), '/'),
                'methods' => array_values(array_filter(
                    explode('|', (string) ($route['method'] ?? '')),
                    static fn ($m) => $m !== 'HEAD'
                )),
                'name' => $route['name'] ?? null,
                'action' => $action,
                'controller' => $controller,
                'controller_method' => $method,
                'middleware' => array_values($route['middleware'] ?? []),
                'permission' => $this->permissionIn($route['middleware'] ?? []),
                'surface' => $this->surfaceOf((string) ($route['uri'] ?? ''), $route['name'] ?? null),
            ];
        }

        return ['source' => 'artisan', 'routes' => $routes, 'error' => null];
    }

    /**
     * The fallback, used when artisan cannot run.
     *
     * It walks the file character by character keeping brace depth, so
     * `Route::prefix('admin')->group(function () { … })` contributes its prefix
     * to everything inside it and stops contributing at the closing brace.
     * Without that the URIs come back without their group prefix —
     * `/widget` where the route is really `/admin/widget` — which is the kind
     * of wrong that is worse than missing, because it looks like an answer.
     *
     * Middleware and permissions are still absent here: they are resolved by
     * the framework, not written next to the route. The collector reports
     * `source: static` so that absence is visible rather than read as "this
     * route is ungated".
     *
     * @return list<array<string,mixed>>
     */
    private function fromSource(): array
    {
        $routes = [];

        foreach (['routes/web.php', 'routes/api.php'] as $file) {
            $absolute = $this->paths->abs($file);
            if (! is_file($absolute)) {
                continue;
            }

            $source = (string) file_get_contents($absolute);
            $imports = $this->importsIn($source);
            $basePrefix = $file === 'routes/api.php' ? 'api/v1' : '';

            $routes = array_merge($routes, $this->walk($source, $imports, $basePrefix));
        }

        return $routes;
    }

    /**
     * @param  array<string,string>  $imports
     * @return list<array<string,mixed>>
     */
    private function walk(string $source, array $imports, string $basePrefix): array
    {
        $routes = [];
        $length = strlen($source);
        $depth = 0;

        /** @var list<array{depth:int,prefix:string,name:string,controller:string}> $stack */
        $stack = [];

        // Two action shapes, because this project uses both:
        //   Route::get('/x', [WidgetController::class, 'index'])
        //   Route::controller(WidgetController::class)->group(… Route::get('/x', 'index') …)
        // The second is the dominant one in routes/web.php, and a reader that
        // knew only the first found seven of the three hundred admin routes.
        $definition = '/\GRoute::(get|post|put|patch|delete|options|any)\(\s*'
            .'[\'"]([^\'"]*)[\'"]\s*,\s*'
            .'(?:\[\s*\\\\?([A-Za-z0-9_\\\\]+)::class\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]|[\'"]([A-Za-z0-9_]+)[\'"])'
            .'\s*\)((?:(?!;).)*)/s';

        $opener = '/\GRoute::((?:(?!;).)*?)group\(\s*(?:\[(.*?)\]\s*,\s*)?(?:function\s*\([^)]*\)|fn\s*\([^)]*\)\s*=>)\s*\{/s';

        for ($i = 0; $i < $length; $i++) {
            $character = $source[$i];

            if ($character === '{') {
                $depth++;

                continue;
            }

            if ($character === '}') {
                $depth--;
                while ($stack !== [] && end($stack)['depth'] > $depth) {
                    array_pop($stack);
                }

                continue;
            }

            if ($character !== 'R' || substr($source, $i, 7) !== 'Route::') {
                continue;
            }

            if (preg_match($opener, $source, $match, 0, $i) === 1) {
                $chain = $match[1].' '.($match[2] ?? '');

                $inherited = $this->currentGroup($stack);

                $groupController = $this->valueIn($chain, 'controller');

                $stack[] = [
                    // The opening brace of the closure is consumed by the match,
                    // so the body sits one level deeper than here.
                    'depth' => $depth + 1,
                    'prefix' => trim($inherited['prefix'].'/'.trim($this->valueIn($chain, 'prefix'), '/'), '/'),
                    'name' => $inherited['name'].$this->valueIn($chain, 'name'),
                    'controller' => $groupController !== ''
                        ? ($imports[$groupController] ?? $groupController)
                        : $inherited['controller'],
                ];

                $i += strlen($match[0]) - 1;
                $depth++;   // the brace this match swallowed

                continue;
            }

            if (preg_match($definition, $source, $match, 0, $i) === 1) {
                $group = $this->currentGroup($stack);
                $tail = $match[6] ?? '';

                $name = null;
                if (preg_match('/->name\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $tail, $found) === 1) {
                    $name = $group['name'].$found[1];
                }

                // Either the array form named the class, or the enclosing
                // `Route::controller()` group did.
                $named = ($match[3] ?? '') !== '';
                $controller = $named ? ($imports[$match[3]] ?? $match[3]) : $group['controller'];
                $action = $named ? $match[4] : ($match[5] ?? '');

                if ($controller === '' || $action === '') {
                    $i += strlen($match[0]) - 1;

                    continue;   // a closure route — nothing to point at
                }

                $uri = trim(implode('/', array_filter([
                    trim($basePrefix, '/'),
                    trim($group['prefix'], '/'),
                    trim($match[2], '/'),
                ], static fn (string $part) => $part !== '')), '/');

                $routes[] = [
                    'uri' => '/'.$uri,
                    'methods' => [strtoupper($match[1])],
                    'name' => $name,
                    'action' => $controller.'@'.$action,
                    'controller' => $controller,
                    'controller_method' => $action,
                    'middleware' => [],
                    'permission' => $this->permissionInChain($tail),
                    'surface' => $this->surfaceOf($uri, $name),
                ];

                $i += strlen($match[0]) - 1;
            }
        }

        return $routes;
    }

    /**
     * @param  list<array{depth:int,prefix:string,name:string,controller:string}>  $stack
     * @return array{prefix:string,name:string,controller:string}
     */
    private function currentGroup(array $stack): array
    {
        $last = end($stack);

        return $last === false
            ? ['prefix' => '', 'name' => '', 'controller' => '']
            : ['prefix' => $last['prefix'], 'name' => $last['name'], 'controller' => $last['controller']];
    }

    /**
     * Pulls `prefix`/`name`/`controller` out of either shape: the fluent
     * `->prefix('admin')->name('admin.')` or the array
     * `['prefix' => 'admin', 'as' => 'admin.']`.
     *
     * Matched on a word boundary rather than on `->`. The captured chain is
     * everything between `Route::` and `group(`, so the *first* call in it has
     * no arrow in front of it — `Route::prefix('admin')->group(…)` yields the
     * chain `prefix('admin')->`, and requiring the arrow silently returned an
     * empty prefix for exactly the outermost group, the one that matters most.
     */
    private function valueIn(string $chain, string $key): string
    {
        $aliases = match ($key) {
            'name' => ['name', 'as'],
            'controller' => ['controller'],
            default => ['prefix'],
        };

        foreach ($aliases as $alias) {
            if ($alias === 'controller') {
                if (preg_match('/\bcontroller\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class/', $chain, $match) === 1) {
                    return $match[1];
                }

                continue;
            }

            if (preg_match('/\b'.$alias.'\(\s*[\'"]([^\'"]*)[\'"]/', $chain, $match) === 1) {
                return $match[1];
            }
            if (preg_match('/[\'"]'.$alias.'[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $chain, $match) === 1) {
                return $match[1];
            }
        }

        return '';
    }

    private function permissionInChain(string $tail): ?string
    {
        if (preg_match('/middleware\(\s*[\'"]permission:([^\'"]+)[\'"]/', $tail, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /** @return array{0:?string,1:?string} */
    private function splitAction(string $action): array
    {
        if ($action === '' || $action === 'Closure') {
            return [null, null];
        }

        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return [$class, $method];
        }

        // A single-action controller registered by class name.
        return [$action, '__invoke'];
    }

    /** @param list<string> $middleware */
    private function permissionIn(array $middleware): ?string
    {
        foreach ($middleware as $entry) {
            if (preg_match('/CheckPermission:(.+)$/', (string) $entry, $matches) === 1) {
                return $matches[1];
            }
            if (str_starts_with((string) $entry, 'permission:')) {
                return substr((string) $entry, strlen('permission:'));
            }
        }

        return null;
    }

    private function surfaceOf(string $uri, ?string $name): string
    {
        $uri = ltrim($uri, '/');

        if (str_starts_with($uri, 'api/')) {
            return 'api';
        }
        if (str_starts_with($uri, 'admin') || str_starts_with((string) $name, 'admin.')) {
            return 'panel';
        }
        if (in_array($name, ['landing', 'landing.terms', 'landing.privacy', 'locale.set'], true)
            || str_starts_with((string) $name, 'landing')) {
            return 'landing';
        }

        return 'public';
    }

    /** @return array<string,string> */
    private function importsIn(string $source): array
    {
        $imports = [];

        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fqcn = $match[1];
                $separator = strrpos($fqcn, '\\');
                $short = $match[2] ?? ($separator === false ? $fqcn : substr($fqcn, $separator + 1));
                $imports[$short] = $fqcn;
            }
        }

        return $imports;
    }

    /**
     * The route table is cached against the hash of everything that can change
     * it, because `route:list` costs about a second and an incremental update
     * that did not touch a route file has no reason to pay it.
     */
    public function cacheKey(): string
    {
        $parts = [];

        foreach (['routes/web.php', 'routes/api.php', 'bootstrap/app.php', 'config/menu.php'] as $file) {
            $absolute = $this->paths->abs($file);
            $parts[] = $file.':'.(is_file($absolute) ? sha1_file($absolute) : 'missing');
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @return array{source:string,routes:list<array<string,mixed>>,error:?string}
     */
    public function collectCached(): array
    {
        $cachePath = $this->paths->cache('routes.json');
        $key = $this->cacheKey();
        $cached = Json::read($cachePath);

        if (is_array($cached) && ($cached['key'] ?? null) === $key) {
            return $cached['value'];
        }

        $value = $this->collect();
        Json::writeCompact($cachePath, ['key' => $key, 'value' => $value]);

        return $value;
    }
}
