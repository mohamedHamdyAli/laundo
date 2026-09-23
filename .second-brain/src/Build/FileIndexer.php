<?php

namespace Laundo\SecondBrain\Build;

use Laundo\SecondBrain\Parse\BladeAnalyzer;
use Laundo\SecondBrain\Parse\FileScanner;
use Laundo\SecondBrain\Parse\ModelAnalyzer;
use Laundo\SecondBrain\Parse\PhpParser;
use Laundo\SecondBrain\Support\Json;
use Laundo\SecondBrain\Support\Paths;

/**
 * One file in, one artifact out — and the artifact is cached against the
 * file's own hash.
 *
 * This is what makes the incremental update real rather than nominal. Parsing
 * is the only expensive step (360 PHP files through the tokenizer); the graph,
 * the detectors and the search index are all cheap functions of the artifacts.
 * So an update re-parses the handful of files git says changed, rewrites their
 * artifacts, and then rebuilds everything downstream from the full set — which
 * costs a fraction of a second and, crucially, produces a graph identical to
 * the one a full rebuild would produce.
 *
 * The alternative — surgically removing and re-adding a file's nodes and edges
 * in place — is faster still and drifts. An edge produced by *another* file
 * that named this one would be left behind, and the drift is invisible until
 * somebody trusts a stale answer.
 */
final class FileIndexer
{
    private const ARTIFACT_VERSION = 3;

    public function __construct(
        private readonly Paths $paths,
        private readonly FileScanner $scanner,
        private readonly PhpParser $php = new PhpParser,
        private readonly ModelAnalyzer $models = new ModelAnalyzer,
        private readonly BladeAnalyzer $blade = new BladeAnalyzer,
    ) {}

    /**
     * @param  list<string>  $files
     * @param  list<string>  $forced  paths to re-parse even if the hash matches
     * @return array{artifacts:array<string,array<string,mixed>>,parsed:int,reused:int}
     */
    public function run(array $files, array $forced = [], ?callable $progress = null): array
    {
        $store = $this->paths->cache('artifacts.json');
        $cached = Json::read($store, []);

        // The cache is keyed on the parser version **and** on `config.php`,
        // because an artifact carries `layer`, `kind`, `module` and
        // `integrations` — all of them derived from that file rather than from
        // the source. Without the config in the key, editing a layer rule and
        // re-indexing produced a graph still labelled with the old one.
        $version = self::ARTIFACT_VERSION.':'.$this->paths->configHash();

        if (! is_array($cached) || ($cached['version'] ?? null) !== $version) {
            $cached = ['version' => $version, 'files' => []];
        }

        $previous = $cached['files'] ?? [];
        $artifacts = [];
        $parsed = 0;
        $reused = 0;
        $forced = array_flip($forced);

        foreach ($files as $index => $relative) {
            $hash = $this->scanner->hash($relative);

            if (! isset($forced[$relative])
                && isset($previous[$relative])
                && ($previous[$relative]['hash'] ?? null) === $hash) {
                $artifacts[$relative] = $previous[$relative];
                $reused++;
            } else {
                $artifacts[$relative] = $this->parse($relative, $hash);
                $parsed++;
            }

            if ($progress !== null && $index % 50 === 0) {
                $progress($index + 1, count($files));
            }
        }

        ksort($artifacts);

        // The same `$version` the read compared against — stamping the bare
        // constant here meant the guard never matched its own output and every
        // run re-parsed everything.
        Json::writeCompact($store, ['version' => $version, 'files' => $artifacts]);

        return ['artifacts' => $artifacts, 'parsed' => $parsed, 'reused' => $reused];
    }

    /**
     * @return array<string,mixed>
     */
    public function parse(string $relative, string $hash): array
    {
        $artifact = [
            'path' => $relative,
            'hash' => $hash,
            'kind' => $this->kindOf($relative),
            'layer' => $this->layerOf($relative),
            'module' => $this->moduleOf($relative),
            'size' => 0,
            'lines' => 0,
        ];

        $source = $this->scanner->read($relative);
        $artifact['size'] = strlen($source);
        $artifact['lines'] = substr_count($source, "\n") + 1;

        $artifact['integrations'] = $this->integrationsIn($source);

        if (str_ends_with($relative, '.blade.php')) {
            $artifact['blade'] = $this->blade->analyse($source);
            $artifact['view_name'] = $this->blade->viewName($relative);

            return $artifact;
        }

        if (str_ends_with($relative, '.php')) {
            $parsed = $this->php->parse($source);

            $artifact['namespace'] = $parsed['namespace'];
            $artifact['imports'] = $parsed['imports'];
            $artifact['views'] = $parsed['views'];
            $artifact['routes_referenced'] = $parsed['routes_referenced'];
            $artifact['top_level_calls'] = $parsed['calls_at_top'];
            $artifact['classes'] = [];

            foreach ($parsed['classes'] as $class) {
                // The docblock text is not carried into the artifact — only its
                // first sentence. The full comment would double the cache and
                // nothing downstream reads it.
                unset($class['doc']);
                foreach ($class['methods'] as $i => $method) {
                    unset($class['methods'][$i]['doc']);
                }

                if ($artifact['layer'] === 'model' || $this->models->looksLikeModel($class)) {
                    $model = $this->models->analyse($class, $source);

                    if ($model === null && $artifact['layer'] === 'model' && $class['kind'] === 'class') {
                        // Sitting in a `Models/` directory and extending
                        // something the parse cannot recognise as Eloquent —
                        // `App\Modules\User\Models\User` extends
                        // `Illuminate\Foundation\Auth\User as Authenticatable`,
                        // and three more models do the same. The directory is
                        // the author's statement of intent, so it is taken.
                        //
                        // `array_merge`, not `+`: the union operator keeps the
                        // left-hand value, so the forced `extends` was silently
                        // discarded and every one of these stayed undetected.
                        $model = $this->models->analyse(
                            array_merge($class, ['extends' => ['Illuminate\\Database\\Eloquent\\Model']]),
                            $source
                        );
                    }

                    if ($model !== null) {
                        $class['model'] = $model;
                    }
                }

                if ($artifact['layer'] === 'command' || $artifact['kind'] === 'command') {
                    $class['command'] = $this->commandSignature($source);
                }

                if (in_array($artifact['layer'], ['test', 'browser-test'], true)) {
                    $class['test'] = $this->testTargets($class, $source);
                }

                if (in_array($artifact['layer'], ['request'], true)) {
                    $class['validates'] = $this->validationFields($source);
                }

                // Non-public methods that are nonetheless contracts — see
                // `significant_methods` in config.php. Collected before the
                // per-method detail is thinned, and attached to the class so
                // the class stays the node.
                $class['significant_methods'] = $this->significantMethods($class['methods']);

                // **Raw string literals are not persisted.** The parser
                // captures them because `ModelAnalyzer` needs one shape of
                // them — a `*_id` foreign key — and that has already run by
                // this point. Keeping the rest would put every literal in
                // every scanned file (`config/` included) into a cache file on
                // disk, to be read by nothing. The narrowest thing that works
                // is the thing to store.
                unset($class['strings']);
                foreach ($class['methods'] as $i => $method) {
                    unset($class['methods'][$i]['strings']);
                }

                $artifact['classes'][] = $class;
            }

            if ($artifact['layer'] === 'migration') {
                $artifact['is_migration'] = true;
            }

            return $artifact;
        }

        if (str_ends_with($relative, '.js')) {
            $artifact['js'] = [
                'describes' => $this->jsDescribes($source),
                'urls' => $this->jsUrls($source),
            ];

            return $artifact;
        }

        if (str_ends_with($relative, '.md')) {
            $artifact['doc'] = [
                'title' => $this->markdownTitle($source),
                'headings' => $this->markdownHeadings($source),
            ];

            return $artifact;
        }

        return $artifact;
    }

    /**
     * Method names matching a configured significant pattern, whatever their
     * visibility.
     *
     * @param  list<array<string,mixed>>  $methods
     * @return list<string>
     */
    private function significantMethods(array $methods): array
    {
        $patterns = $this->paths->config()['significant_methods'] ?? [];

        if ($patterns === []) {
            return [];
        }

        $found = [];

        foreach ($methods as $method) {
            $name = (string) ($method['name'] ?? '');

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    $found[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    // ------------------------------------------------------------ classifiers

    public function layerOf(string $relative): string
    {
        foreach ($this->paths->config()['layers'] as $pattern => $layer) {
            if (preg_match($pattern, $relative) === 1) {
                return $layer;
            }
        }

        return 'other';
    }

    private function kindOf(string $relative): string
    {
        if (str_ends_with($relative, '.blade.php')) {
            return 'view';
        }
        if (str_ends_with($relative, '.spec.js')) {
            return 'browser-test';
        }
        if (str_ends_with($relative, '.md')) {
            return 'doc';
        }
        if (str_ends_with($relative, '.json')) {
            return 'data';
        }
        if (str_ends_with($relative, '.js')) {
            return 'script';
        }

        return $this->layerOf($relative);
    }

    /**
     * Which module a file belongs to. `app/Modules/{Name}` is the answer where
     * one applies; everything else gets the shared pseudo-module its directory
     * implies, because "no module" would leave a third of the graph unplaceable.
     */
    public function moduleOf(string $relative): string
    {
        if (preg_match('#^app/Modules/([^/]+)/#', $relative, $matches) === 1) {
            return $matches[1];
        }

        return match (true) {
            str_starts_with($relative, 'app/Http/Controllers/Api/') => 'Api',
            str_starts_with($relative, 'app/Http/Controllers/Auth/') => 'Auth',
            str_starts_with($relative, 'app/Http/Controllers/Admin/') => 'AdminShell',
            str_starts_with($relative, 'app/Services/Landing/') => 'Landing',
            str_starts_with($relative, 'app/Services/Auth/') => 'Auth',
            str_starts_with($relative, 'app/Services/Routing/') => 'Routing',
            str_starts_with($relative, 'app/Services/Push/') => 'Notification',
            str_starts_with($relative, 'app/Services/Sms/') => 'Auth',
            str_starts_with($relative, 'app/Jobs/'),
            str_starts_with($relative, 'app/Notifications/') => 'Notification',
            str_starts_with($relative, 'resources/views/landing/') => 'Landing',
            str_starts_with($relative, 'resources/views/admin/') => $this->moduleFromViewPath($relative),
            str_starts_with($relative, 'database/migrations/') => 'Database',
            str_starts_with($relative, 'database/seeders/') => 'Database',
            str_starts_with($relative, 'tests/') => 'Tests',
            str_starts_with($relative, 'docs/') => 'Docs',
            str_starts_with($relative, 'routes/') => 'Routing',
            str_starts_with($relative, 'config/') => 'Platform',
            default => 'Platform',
        };
    }

    private function moduleFromViewPath(string $relative): string
    {
        if (preg_match('#^resources/views/admin/([^/]+)/#', $relative, $matches) === 1) {
            return str_replace(' ', '', ucwords(str_replace('_', ' ', $matches[1])));
        }

        return 'AdminShell';
    }

    /** @return list<string> */
    private function integrationsIn(string $source): array
    {
        $found = [];

        foreach ($this->paths->config()['integrations'] as $name => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $found[] = $name;
                    break;
                }
            }
        }

        return $found;
    }

    /** @return array{name:?string,description:?string} */
    private function commandSignature(string $source): array
    {
        $name = null;
        $description = null;

        if (preg_match('/\$signature\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 1) {
            $name = trim(explode(' ', $matches[1])[0]);
        } elseif (preg_match('/\$name\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 1) {
            $name = $matches[1];
        }

        if (preg_match('/\$description\s*=\s*[\'"](.*?)[\'"]\s*;/s', $source, $matches) === 1) {
            $description = $matches[1];
        }

        return ['name' => $name, 'description' => $description];
    }

    /**
     * What a test file is about: the classes it names, the routes it calls, and
     * its `#[Test]` method summaries. This is the `tested_by` edge, and it is
     * the cheapest high-value signal in the graph — a search for a feature that
     * returns its test tells the reader what the feature is supposed to do.
     *
     * @param  array<string,mixed>  $class
     * @return array<string,mixed>
     */
    private function testTargets(array $class, string $source): array
    {
        preg_match_all('/route\(\s*[\'"]([a-z0-9_.-]+)[\'"]/i', $source, $routes);
        preg_match_all('#[\'"](/(?:admin|api)/[a-z0-9/_.{}$-]+)[\'"]#i', $source, $uris);

        $cases = [];
        foreach ($class['methods'] as $method) {
            if ($method['visibility'] === 'public' && ! str_starts_with($method['name'], '__')
                && ! in_array($method['name'], ['setUp', 'tearDown'], true)) {
                $cases[] = $method['name'];
            }
        }

        return [
            'routes' => array_values(array_unique($routes[1] ?? [])),
            'uris' => array_values(array_unique($uris[1] ?? [])),
            'cases' => $cases,
        ];
    }

    /** @return list<string> */
    private function validationFields(string $source): array
    {
        if (preg_match('/function\s+rules\s*\(.*?\)\s*:?\s*[a-z]*\s*\{(.*)/s', $source, $matches) !== 1) {
            return [];
        }

        preg_match_all('/[\'"]([a-zA-Z0-9_.*]+)[\'"]\s*=>/', $matches[1], $found);

        return array_values(array_unique(array_slice($found[1] ?? [], 0, 60)));
    }

    /** @return list<string> */
    private function jsDescribes(string $source): array
    {
        preg_match_all('/\b(?:test|describe|it)\(\s*[\'"`](.{3,120}?)[\'"`]/s', $source, $matches);

        return array_values(array_unique(array_slice($matches[1] ?? [], 0, 40)));
    }

    /** @return list<string> */
    private function jsUrls(string $source): array
    {
        preg_match_all('#[\'"`](/(?:admin|api|laundry)[a-z0-9/_.-]*)[\'"`]#i', $source, $matches);

        return array_values(array_unique(array_slice($matches[1] ?? [], 0, 40)));
    }

    private function markdownTitle(string $source): string
    {
        if (preg_match('/^#\s+(.+)$/m', $source, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    /** @return list<string> */
    private function markdownHeadings(string $source): array
    {
        preg_match_all('/^#{2,3}\s+(.+)$/m', $source, $matches);

        return array_values(array_slice($matches[1] ?? [], 0, 40));
    }
}
