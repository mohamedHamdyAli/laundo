<?php

namespace Laundo\SecondBrain\Build;

use Laundo\SecondBrain\Detect\CommunityDetector;
use Laundo\SecondBrain\Detect\FeatureDetector;
use Laundo\SecondBrain\Detect\ModuleDetector;
use Laundo\SecondBrain\Graph\Graph;
use Laundo\SecondBrain\Index\Lexicon;
use Laundo\SecondBrain\Parse\ConfigReader;
use Laundo\SecondBrain\Parse\FileScanner;
use Laundo\SecondBrain\Parse\GitHistory;
use Laundo\SecondBrain\Parse\RouteCollector;
use Laundo\SecondBrain\Support\Json;
use Laundo\SecondBrain\Support\Paths;

/**
 * The whole build, in one place, in the order the stages depend on each other.
 *
 * `index` and `update` are the same method. The only difference is which files
 * are re-parsed: `update` asks git what changed and forces those, `index`
 * forces nothing and lets the artifact hashes decide — which means a second
 * `index` immediately after the first is nearly free and produces a
 * byte-identical result. Repeated indexing being safe is not a nice property
 * here, it is the property that lets a hook run it.
 */
final class Indexer
{
    public const VERSION = '1.0.0';

    private readonly Paths $paths;

    private readonly FileScanner $scanner;

    /**
     * The scanner is derived from the paths, never defaulted.
     *
     * It used to default to `new FileScanner(new Paths(''))`, which is a
     * fatal error waiting to be called: an empty root makes the config path
     * `/.second-brain/config.php` and the `require` dies. Nothing hit it only
     * because every call site went through `make()` — a default argument that
     * cannot be used is not a default, it is a trap for the next caller.
     */
    public function __construct(Paths $paths, ?FileScanner $scanner = null)
    {
        $this->paths = $paths;
        $this->scanner = $scanner ?? new FileScanner($paths);
    }

    public static function make(Paths $paths): self
    {
        return new self($paths);
    }

    /**
     * @param  list<string>|null  $forced  null = let the hashes decide
     * @return array<string,mixed>  the manifest
     */
    public function run(?array $forced = null, ?callable $log = null): array
    {
        $log ??= static function (): void {};
        $started = microtime(true);

        $log('scanning');
        $files = $this->scanner->all();

        $log('parsing '.count($files).' files');
        $fileIndexer = new FileIndexer($this->paths, $this->scanner);
        $parse = $fileIndexer->run($files, $forced ?? []);

        $log('reading routes');
        $routes = (new RouteCollector($this->paths))->collectCached();

        $log('reading config');
        $config = new ConfigReader($this->paths);
        $menu = $config->menu();
        $dashboard = $config->dashboardModels();
        $runtime = $config->runtimeFacts();

        $log('reading git history');
        $git = (new GitHistory($this->paths))->collect($this->scanner);

        $log('building graph');
        $builder = (new GraphBuilder)->withRoot($this->paths->root());
        $graph = $builder->build($parse['artifacts'], $routes, $menu, $dashboard, $git);

        $log('detecting communities');
        $communities = (new CommunityDetector)->detect($graph, $menu, $dashboard);

        $log('detecting modules');
        $modules = (new ModuleDetector)->detect($graph, $communities, $this->paths->root());

        $log('detecting features');
        $features = (new FeatureDetector)->detect($graph, $menu, $communities);

        $this->attachFeatureNodes($graph, $features);

        $log('building search index');
        $lexicon = (new Lexicon)->build($graph);

        $log('writing');
        $manifest = $this->write(
            $graph, $communities, $modules, $features, $routes, $builder->schema,
            $git, $menu, $dashboard, $runtime, $lexicon, $parse, $files, $started
        );

        $log('done in '.$manifest['built_in_seconds'].'s');

        return $manifest;
    }

    /**
     * Features become graph nodes after they are detected, so they are
     * searchable and so `get_feature` and `search` cannot disagree about what
     * exists.
     *
     * @param  list<array<string,mixed>>  $features
     */
    private function attachFeatureNodes(Graph $graph, array $features): void
    {
        foreach ($features as $feature) {
            $id = 'feature:'.$feature['id'];

            $graph->addNode($id, 'feature', [
                'name' => $feature['label'],
                'module' => $feature['module'],
                'layer' => 'feature',
                'summary' => $this->featureSummary($graph, $feature),
                'kind' => $feature['kind'],
                'surface' => $feature['surface'],
                'community' => $feature['community'],
            ]);

            foreach ($feature['entry_points'] as $entry) {
                $graph->addEdge($id, $entry, 'entry_point');
            }
            foreach ($feature['classes'] as $class) {
                $graph->addEdge($id, $class, 'implemented_by');
            }
            foreach ($feature['tables'] as $table) {
                $graph->addEdge($id, 'table:'.$table, 'touches');
            }
            foreach ($feature['tests'] as $test) {
                $graph->addEdge($id, $test, 'tested_by');
            }
        }

        $graph->prune();
        $graph->sort();
    }

    /** @param array<string,mixed> $feature */
    private function featureSummary(Graph $graph, array $feature): string
    {
        // Borrow the entry point's own docblock where there is one — it was
        // written by somebody describing exactly this capability.
        foreach ($feature['entry_points'] as $entry) {
            $node = $graph->node($entry);
            if ($node !== null && trim((string) ($node['summary'] ?? '')) !== '') {
                return $node['summary'];
            }
        }

        foreach (array_slice($feature['classes'], 0, 3) as $class) {
            $node = $graph->node($class);
            if ($node !== null && trim((string) ($node['summary'] ?? '')) !== '') {
                return $node['summary'];
            }
        }

        return '';
    }

    /**
     * @param  list<array<string,mixed>>  $communities
     * @param  list<array<string,mixed>>  $modules
     * @param  list<array<string,mixed>>  $features
     * @param  array<string,mixed>  $routes
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $git
     * @param  array<string,mixed>  $menu
     * @param  array<string,mixed>  $dashboard
     * @param  array<string,mixed>  $runtime
     * @param  array<string,mixed>  $lexicon
     * @param  array<string,mixed>  $parse
     * @param  list<string>  $files
     * @return array<string,mixed>
     */
    private function write(
        Graph $graph,
        array $communities,
        array $modules,
        array $features,
        array $routes,
        array $schema,
        array $git,
        array $menu,
        array $dashboard,
        array $runtime,
        array $lexicon,
        array $parse,
        array $files,
        float $started,
    ): array {
        $data = $this->paths->data();

        $nodes = $graph->nodes();
        $edges = $graph->edges();

        $byType = [];
        foreach ($nodes as $node) {
            $byType[$node['type']] = ($byType[$node['type']] ?? 0) + 1;
        }
        ksort($byType);

        $byEdgeType = [];
        foreach ($edges as $edge) {
            $byEdgeType[$edge['type']] = ($byEdgeType[$edge['type']] ?? 0) + 1;
        }
        arsort($byEdgeType);

        Json::writeCompact($data.'/graph/nodes.json', $nodes);
        Json::writeCompact($data.'/graph/edges.json', $edges);
        Json::writeCompact($data.'/graph/dependencies.json', $this->dependencyMatrix($modules));

        Json::write($data.'/architecture/communities.json', $communities);
        Json::write($data.'/architecture/modules.json', $modules);
        Json::writeCompact($data.'/architecture/features.json', $features);

        Json::writeCompact($data.'/routes/routes.json', [
            'source' => $routes['source'],
            'error' => $routes['error'],
            'count' => count($routes['routes']),
            'routes' => $routes['routes'],
        ]);

        Json::writeCompact($data.'/database/tables.json', $schema['tables']);
        Json::write($data.'/database/relationships.json', [
            'foreign_keys' => $schema['foreign_keys'],
            'eloquent' => $this->eloquentRelations($graph),
        ]);

        Json::write($data.'/git/history.json', [
            'available' => $git['available'],
            'head' => $git['head'],
            'commits_scanned' => $git['commits'],
            'recent' => $git['recent'],
            'hottest_files' => array_slice($git['churn'], 0, 40, true),
        ]);

        Json::writeCompact($data.'/index/lexical.json', $lexicon);

        $overview = $this->overview($graph, $communities, $modules, $features, $routes, $schema, $menu, $dashboard, $runtime, $byType, $byEdgeType);
        Json::write($data.'/architecture/overview.json', $overview);

        $manifest = [
            'version' => self::VERSION,
            'built_at' => date('c'),
            'built_in_seconds' => round(microtime(true) - $started, 2),
            'repository' => basename($this->paths->root()),
            'files_scanned' => count($files),
            'files_parsed' => $parse['parsed'],
            'files_reused_from_cache' => $parse['reused'],
            'nodes' => count($nodes),
            'edges' => count($edges),
            'nodes_by_type' => $byType,
            'edges_by_type' => $byEdgeType,
            'communities' => count($communities),
            'modules' => count($modules),
            'features' => count($features),
            'routes' => count($routes['routes']),
            'route_source' => $routes['source'],
            'route_error' => $routes['error'],
            'tables' => count($schema['tables']),
            'models' => count(array_filter($nodes, static fn ($n) => ($n['is_model'] ?? false) === true)),
            'tests' => $byType['test'] ?? 0,
            'indexed_documents' => $lexicon['total'],
            'git' => ['available' => $git['available'], 'head' => $git['head'], 'commits_scanned' => $git['commits']],
        ];

        Json::write($data.'/manifest.json', $manifest);

        return $manifest;
    }

    /**
     * @param  list<array<string,mixed>>  $modules
     * @return array<string,mixed>
     */
    private function dependencyMatrix(array $modules): array
    {
        $matrix = [];

        foreach ($modules as $module) {
            $matrix[$module['name']] = [
                'depends_on' => $module['depends_on'],
                'depended_on_by' => $module['depended_on_by'],
                'fan_out' => count($module['depends_on']),
                'fan_in' => count($module['depended_on_by']),
            ];
        }

        return $matrix;
    }

    /** @return list<array<string,mixed>> */
    private function eloquentRelations(Graph $graph): array
    {
        $relations = [];
        $types = ['belongs_to', 'has_many', 'has_one', 'belongs_to_many', 'has_many_through', 'has_one_through', 'morph_to', 'morph_many', 'morph_one', 'morph_to_many'];

        foreach ($graph->edges() as $edge) {
            if (! in_array($edge['type'], $types, true)) {
                continue;
            }

            $from = $graph->node($edge['from']);
            $to = $graph->node($edge['to']);

            $relations[] = [
                'from' => $from['fqcn'] ?? $edge['from'],
                'to' => $to['fqcn'] ?? $edge['to'],
                'type' => $edge['type'],
                'method' => $edge['meta']['method'] ?? null,
                'foreign_key' => $edge['meta']['foreign_key'] ?? null,
                'from_table' => $from['table'] ?? null,
                'to_table' => $to['table'] ?? null,
            ];
        }

        return $relations;
    }

    /**
     * The document a reader — or Claude — opens first.
     *
     * Deliberately opinionated: it states the conventions that decide *where*
     * something goes in this codebase, because that is the question the brain
     * exists to shorten, and a list of counts would not answer it.
     *
     * @return array<string,mixed>
     */
    private function overview(
        Graph $graph,
        array $communities,
        array $modules,
        array $features,
        array $routes,
        array $schema,
        array $menu,
        array $dashboard,
        array $runtime,
        array $byType,
        array $byEdgeType,
    ): array {
        return [
            'project' => 'Laundo — laundry pickup & delivery platform',
            'stack' => [
                'framework' => 'Laravel 13',
                'php' => '^8.3',
                'database' => 'MySQL in production, MariaDB on the deployed box; SQLite in the PHPUnit suite',
                'auth' => 'Sanctum for the mobile API, session auth for the panel',
                'frontend' => 'Blade + a static vendor admin template under public/assets (Vite is near-unused)',
                'tests' => 'PHPUnit 11 (not Pest) + Playwright browser specs in tests/Browser',
            ],
            'surfaces' => [
                'panel' => ['prefix' => '/admin', 'routes' => $this->countRoutes($routes, 'panel'), 'note' => 'Blade admin panel; laundry owners share it and are confined by the tenant scope'],
                'api' => ['prefix' => '/api/v1', 'routes' => $this->countRoutes($routes, 'api'), 'note' => 'stateless JSON for the customer app and the driver app'],
                'landing' => ['prefix' => '/', 'routes' => $this->countRoutes($routes, 'landing'), 'note' => 'public marketing page; must not load any admin asset'],
                'public' => ['prefix' => '/', 'routes' => $this->countRoutes($routes, 'public'), 'note' => 'auth pages, laundry/driver applications, locale switch'],
            ],
            'layer_contract' => [
                'order' => ['Controller', 'Service', 'Repository', 'Model'],
                'controller' => 'HTTP only — delegates to the service',
                'service' => 'business rules; every write wrapped in DB::transaction',
                'repository' => 'the only place raw Eloquent queries live',
                'crud_service_convention' => 'app/Modules/{Name}/Services/{name}CrudService.php exposes shredData($id = null); the list comes back under a plural key and the single record under `row`',
            ],
            'conventions' => [
                'module_root' => 'app/Modules/{Name}/{Controllers,Models,Repositories,Services,Requests,Enums}',
                'screen_is_not_a_module' => 'a sidebar screen often lives inside an existing module — payment/driver_earning/refund/order_settlement/commission_rule all live in app/Modules/Payment',
                'permissions' => '{model}.{action} with action in '.implode('|', $dashboard['slugs'] === [] ? ['view', 'create', 'update', 'delete', 'toggle'] : array_map(static fn ($s) => explode('.', $s)[1], reset($dashboard['slugs']) ?: [])),
                'money_permissions' => 'money terms gate on setting.update, not on the module\'s own update',
                'translatable_columns' => 'JSON {"en":…,"ar":…} in a text column, decoded to stdClass by a getXAttribute accessor — $row->name->en, never $row->name[\'en\']',
                'status_column' => "string 'active'/'inactive', never a boolean",
                'route_names' => 'kebab-case URI, snake_case route name (/admin/journey-step → admin.journey_step.index)',
                'list_pages' => 'index + search routes per module; search is AJAX-only and reads $request->get(\'query\')',
            ],
            'absent_by_design' => [
                'policies' => 'none — authorisation is CheckPermission middleware, the canDo() Blade helper and MenuBuilder',
                'events_and_listeners' => 'none — services call notifiers directly and synchronously',
                'api_resources' => 'none — each API controller keeps private present*() methods per payload shape',
                'jobs' => 'one only (app/Jobs/SendManualNotification.php); everything else is synchronous on purpose',
            ],
            'scale' => [
                'communities' => count($communities),
                'modules' => count($modules),
                'features' => count($features),
                'routes' => count($routes['routes']),
                'tables' => count($schema['tables']),
                'nodes_by_type' => $byType,
                'edges_by_type' => $byEdgeType,
            ],
            'communities' => array_map(static fn (array $c) => [
                'slug' => $c['slug'],
                'title' => $c['title'],
                'source' => $c['source'],
                'modules' => $c['modules'],
                'routes' => $c['route_count'],
                'cohesion' => $c['cohesion'],
            ], $communities),
            'runtime' => $runtime,
            'entry_files' => [
                'routes/web.php', 'routes/api.php', 'routes/console.php',
                'config/menu.php', 'config/dashboard.php', 'bootstrap/app.php',
            ],
            'documentation' => array_values(array_filter(array_map(
                static fn (array $n) => $n['path'] ?? null,
                $graph->ofType('file')
            ), static fn (?string $p) => $p !== null && str_starts_with($p, 'docs/') && str_ends_with($p, '.md'))),
        ];
    }

    /** @param array<string,mixed> $routes */
    private function countRoutes(array $routes, string $surface): int
    {
        return count(array_filter($routes['routes'], static fn (array $r) => $r['surface'] === $surface));
    }
}
