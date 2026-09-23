<?php

namespace Laundo\SecondBrain\Build;

use Laundo\SecondBrain\Graph\Graph;
use Laundo\SecondBrain\Parse\MigrationAnalyzer;
use Laundo\SecondBrain\Support\Text;

/**
 * Artifacts in, graph out.
 *
 * Every edge in here is one the source shows. The two rules held to throughout:
 *
 * 1. **A class outside the project is not a node.** `Illuminate\…` and
 *    `RuntimeException` are recorded on the artifact but never become graph
 *    nodes, so `prune()` drops the edges to them and a dependency answer is
 *    the project's own code rather than a list of framework facades.
 * 2. **No edge without a witness.** Where a relationship is idiomatic but not
 *    written down — a service that "probably" uses a repository it never
 *    names — nothing is added.
 */
final class GraphBuilder
{
    /** @var array<string,string> fqcn => node id */
    private array $classIds = [];

    /** @var array<string,string> view name => node id */
    private array $viewIds = [];

    /**
     * @param  array<string,array<string,mixed>>  $artifacts
     * @param  array{source:string,routes:list<array<string,mixed>>,error:?string}  $routes
     * @param  array<string,mixed>  $menu
     * @param  array{models:list<string>,slugs:array<string,list<string>>}  $dashboard
     * @param  array<string,mixed>  $git
     */
    public function build(array $artifacts, array $routes, array $menu, array $dashboard, array $git): Graph
    {
        $graph = new Graph;

        $this->addFilesAndClasses($graph, $artifacts);
        $this->addDatabase($graph, $artifacts);
        $this->addRelationships($graph, $artifacts);
        $this->addRoutes($graph, $routes, $dashboard, $menu);
        $this->addViews($graph, $artifacts);
        $this->addTests($graph, $artifacts, $routes);
        $this->addCommands($graph, $artifacts);
        $this->addIntegrations($graph, $artifacts);
        $this->addGit($graph, $git);
        $this->reattachViews($graph);

        $graph->prune();
        $graph->sort();

        return $graph;
    }

    // ------------------------------------------------------------------ files

    /** @param array<string,array<string,mixed>> $artifacts */
    private function addFilesAndClasses(Graph $graph, array $artifacts): void
    {
        // Pass one: declare every class so the second pass can point at them.
        foreach ($artifacts as $path => $artifact) {
            foreach ($artifact['classes'] ?? [] as $class) {
                $this->classIds[$class['fqcn']] = 'class:'.$class['fqcn'];
            }
            if (($artifact['view_name'] ?? null) !== null) {
                $this->viewIds[$artifact['view_name']] = 'view:'.$artifact['view_name'];
            }
        }

        foreach ($artifacts as $path => $artifact) {
            $fileId = 'file:'.$path;

            $graph->addNode($fileId, 'file', [
                'name' => basename($path),
                'path' => $path,
                'module' => $artifact['module'],
                'layer' => $artifact['layer'],
                'kind' => $artifact['kind'],
                'lines' => $artifact['lines'],
            ]);

            $moduleId = 'module:'.$artifact['module'];
            $graph->addNode($moduleId, 'module', ['name' => $artifact['module']]);
            $graph->addEdge($moduleId, $fileId, 'contains');

            foreach ($artifact['classes'] ?? [] as $class) {
                $this->addClass($graph, $fileId, $path, $artifact, $class);
            }

            // `use` statements that name a project class.
            foreach ($artifact['imports'] ?? [] as $fqcn) {
                $target = $this->classIds[$fqcn] ?? null;
                if ($target !== null) {
                    $graph->addEdge($fileId, $target, 'imports');
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $artifact
     * @param  array<string,mixed>  $class
     */
    private function addClass(Graph $graph, string $fileId, string $path, array $artifact, array $class): void
    {
        $type = match ($class['kind']) {
            'interface' => 'interface',
            'trait' => 'trait',
            'enum' => 'enum',
            default => 'class',
        };

        $classId = 'class:'.$class['fqcn'];

        $graph->addNode($classId, $type, [
            'name' => $class['name'],
            'fqcn' => $class['fqcn'],
            'path' => $path,
            'line' => $class['line'],
            'module' => $artifact['module'],
            'layer' => $artifact['layer'],
            'summary' => $class['summary'],
            'methods' => array_values(array_map(
                static fn (array $method) => $method['name'],
                array_filter($class['methods'], static fn (array $m) => $m['visibility'] === 'public')
            )),
        ]);

        $graph->addEdge($fileId, $classId, 'contains');

        foreach ($class['extends'] as $parent) {
            $graph->addEdge($classId, 'class:'.$parent, 'extends');
        }
        foreach ($class['implements'] as $contract) {
            $graph->addEdge($classId, 'class:'.$contract, 'implements');
        }
        foreach ($class['traits'] as $trait) {
            $graph->addEdge($classId, 'class:'.$trait, 'uses_trait');
        }
        foreach ($class['references'] as $reference) {
            $graph->addEdge($classId, 'class:'.$reference, 'references');
        }

        // Enum cases read as constants — the domain vocabulary, and worth
        // carrying because "which statuses exist" is a real question.
        if ($type === 'enum' && $class['constants'] !== []) {
            $graph->setAttribute($classId, 'cases', $class['constants']);
        }

        foreach ($class['methods'] as $method) {
            $this->addMethod($graph, $classId, $class, $method, $path, $artifact);
        }

        if (isset($class['validates']) && $class['validates'] !== []) {
            $graph->setAttribute($classId, 'validates', $class['validates']);
        }

        // Contracts that happen not to be public — `present*`, `scope*`,
        // `shredData`. Facets on the class, never nodes of their own.
        if (($class['significant_methods'] ?? []) !== []) {
            $graph->setAttribute($classId, 'significant_methods', $class['significant_methods']);
        }
    }

    /**
     * @param  array<string,mixed>  $class
     * @param  array<string,mixed>  $method
     * @param  array<string,mixed>  $artifact
     */
    private function addMethod(Graph $graph, string $classId, array $class, array $method, string $path, array $artifact): void
    {
        // Only public methods become nodes. A private helper is an
        // implementation detail; putting 1,500 of them in the graph would
        // treble its size and dilute every search result.
        if ($method['visibility'] !== 'public') {
            return;
        }

        $methodId = 'method:'.$class['fqcn'].'::'.$method['name'];

        $graph->addNode($methodId, 'method', [
            'name' => $method['name'],
            'fqcn' => $class['fqcn'].'::'.$method['name'],
            'path' => $path,
            'line' => $method['line'],
            'module' => $artifact['module'],
            'layer' => $artifact['layer'],
            'summary' => $method['summary'],
            'static' => $method['static'],
        ]);

        $graph->addEdge($classId, $methodId, 'contains');

        // Constructor-injected collaborators: the layer contract, edge by edge.
        if ($method['name'] === '__construct') {
            foreach ($method['assigned_properties'] as $type) {
                $graph->addEdge($classId, 'class:'.$type, 'depends_on');
            }
        }

        foreach ($method['parameters'] as $parameter) {
            if ($parameter['type'] === null || $method['name'] === '__construct') {
                continue;
            }

            $graph->addEdge($classId, 'class:'.$parameter['type'], 'depends_on');

            // A controller action type-hinting `OfferRequest` *is* the
            // validation wiring — Laravel resolves and runs it before the body.
            // `Illuminate\Http\Request` is excluded because it validates
            // nothing on its own, and a class that merely ends in `Request`
            // without being in the project is dropped by `prune()`.
            if (str_ends_with($parameter['type'], 'Request')
                && ! str_starts_with($parameter['type'], 'Illuminate\\')) {
                $graph->addEdge($methodId, 'class:'.$parameter['type'], 'validates');
            }
        }

        foreach ($method['calls'] as $call) {
            $target = $call['class'] ?? null;
            if ($target === null || $target === '$this' || $call['method'] === null) {
                continue;
            }
            $graph->addEdge($methodId, 'method:'.$target.'::'.$call['method'], 'calls');
            $graph->addEdge($classId, 'class:'.$target, 'depends_on');
        }

        foreach ($method['views'] ?? [] as $view) {
            $graph->addEdge($methodId, 'view:'.$view, 'renders');
        }
    }

    // --------------------------------------------------------------- database

    /** @param array<string,array<string,mixed>> $artifacts */
    private function addDatabase(Graph $graph, array $artifacts): void
    {
        $migrations = [];

        foreach ($artifacts as $path => $artifact) {
            if (($artifact['is_migration'] ?? false) === true) {
                $migrations[$path] = $path;
            }
        }

        ksort($migrations);   // filename order is apply order

        $sources = [];
        foreach ($migrations as $path) {
            $sources[] = ['path' => $path, 'source' => $this->readMigration($path)];
        }

        $schema = (new MigrationAnalyzer)->replay($sources);

        foreach ($schema['tables'] as $name => $table) {
            $tableId = 'table:'.$name;

            $graph->addNode($tableId, 'table', [
                'name' => $name,
                'module' => 'Database',
                'layer' => 'database',
                'summary' => $table['summary'],
                'columns' => array_keys($table['columns']),
                'column_detail' => array_values($table['columns']),
                'indexes' => $table['indexes'],
                'created_by' => $table['created_by'],
                'migrations' => $table['migrations'],
            ]);

            foreach ($table['migrations'] as $migration) {
                $graph->addEdge('file:'.$migration, $tableId, 'writes_table');
            }
        }

        foreach ($schema['foreign_keys'] as $key) {
            $graph->addEdge('table:'.$key['from'], 'table:'.$key['to'], 'foreign_key', [
                'column' => $key['column'],
            ]);
        }

        $this->schema = $schema;
    }

    /** @var array{tables:array<string,array<string,mixed>>,foreign_keys:list<array<string,string>>} */
    public array $schema = ['tables' => [], 'foreign_keys' => []];

    private string $rootForMigrations = '';

    public function withRoot(string $root): self
    {
        $this->rootForMigrations = $root;

        return $this;
    }

    private function readMigration(string $path): string
    {
        $absolute = rtrim($this->rootForMigrations, '/').'/'.$path;

        return is_file($absolute) ? (string) file_get_contents($absolute) : '';
    }

    // ---------------------------------------------------------- model mapping

    /** @param array<string,array<string,mixed>> $artifacts */
    private function addRelationships(Graph $graph, array $artifacts): void
    {
        foreach ($artifacts as $path => $artifact) {
            foreach ($artifact['classes'] ?? [] as $class) {
                if (! isset($class['model'])) {
                    continue;
                }

                $model = $class['model'];
                $classId = 'class:'.$class['fqcn'];

                $graph->setAttribute($classId, 'is_model', true);
                $graph->setAttribute($classId, 'table', $model['table']);
                $graph->setAttribute($classId, 'fillable', $model['fillable']);
                $graph->setAttribute($classId, 'scopes', $model['scopes']);
                $graph->setAttribute($classId, 'tenant_scoped', $model['tenant_scoped']);
                $graph->setAttribute($classId, 'searchable', $model['searchable']);

                $graph->addEdge($classId, 'table:'.$model['table'], 'maps_to');

                // The migration that defines the model's table.
                //
                // Inferred, and only where the inference is safe: the table a
                // model declares (or that Laravel's own convention gives it)
                // is looked up in the replayed schema, and the migration that
                // *created* it is the definition. Nothing is invented — a
                // model whose table no migration creates gets no edge, which
                // is why `LaundryRevenue` and `Report` have none and `doctor`
                // reports them.
                $table = $graph->node('table:'.$model['table']);
                $createdBy = $table['created_by'] ?? null;

                if ($createdBy !== null) {
                    $graph->addEdge($classId, 'file:'.$createdBy, 'defined_by', [
                        // `$table` declared outright is certain; a name derived
                        // from Laravel's pluralisation rule is a convention
                        // this codebase happens to follow.
                        'confidence' => $model['table_declared'] ?? false ? 'declared' : 'convention',
                        'table' => $model['table'],
                    ]);
                }

                // Later migrations that altered the same table — where a
                // column was added, which is what "add a column to X" needs.
                foreach (array_slice($table['migrations'] ?? [], 0, 8) as $migration) {
                    if ($migration !== $createdBy) {
                        $graph->addEdge('table:'.$model['table'], 'file:'.$migration, 'writes_table', [
                            'confidence' => 'declared',
                        ]);
                    }
                }

                foreach ($model['relations'] as $relation) {
                    if ($relation['target'] === null) {
                        continue;
                    }
                    $graph->addEdge($classId, 'class:'.$relation['target'], $relation['type'], [
                        'method' => $relation['name'],
                        'foreign_key' => $relation['foreign_key'],
                    ]);
                }
            }
        }
    }

    // ----------------------------------------------------------------- routes

    /**
     * @param  array{source:string,routes:list<array<string,mixed>>,error:?string}  $routes
     * @param  array{models:list<string>,slugs:array<string,list<string>>}  $dashboard
     * @param  array<string,mixed>  $menu
     */
    private function addRoutes(Graph $graph, array $routes, array $dashboard, array $menu): void
    {
        // Permission nodes first, so a route can be gated on one that exists.
        foreach ($dashboard['slugs'] as $fqcn => $slugs) {
            foreach ($slugs as $slug) {
                $graph->addNode('permission:'.$slug, 'permission', [
                    'name' => $slug,
                    'model' => $fqcn,
                    'module' => $this->moduleOfFqcn($fqcn),
                    'layer' => 'permission',
                ]);
                $graph->addEdge('permission:'.$slug, 'class:'.$fqcn, 'references');
            }
        }

        foreach ($routes['routes'] as $route) {
            $id = 'route:'.($route['name'] ?? (implode(',', $route['methods']).' '.$route['uri']));

            $controller = $route['controller'];
            $module = $controller !== null ? $this->moduleOfFqcn($controller) : 'Platform';

            $graph->addNode($id, 'route', [
                'name' => $route['name'],
                'uri' => $route['uri'],
                'methods' => $route['methods'],
                'action' => $route['action'],
                'surface' => $route['surface'],
                'permission' => $route['permission'],
                'middleware' => $this->shortMiddleware($route['middleware']),
                'module' => $module,
                'layer' => 'route',
            ]);

            if ($controller !== null && $route['controller_method'] !== null) {
                $graph->addEdge($id, 'method:'.$controller.'::'.$route['controller_method'], 'route_to');
                $graph->addEdge($id, 'class:'.$controller, 'route_to');
            }

            if ($route['permission'] !== null) {
                // A slug a route names but the generator never produced is
                // still recorded — that mismatch is worth being able to find.
                $graph->addNode('permission:'.$route['permission'], 'permission', [
                    'name' => $route['permission'],
                    'module' => $module,
                    'layer' => 'permission',
                ]);
                $graph->addEdge($id, 'permission:'.$route['permission'], 'guarded_by');
            }
        }

        // The sidebar's own grouping, kept as node attributes on the module.
        foreach ($menu['titles'] ?? [] as $key => $title) {
            $graph->addNode('permission:'.$key.'.view', 'permission', [
                'name' => $key.'.view',
                'menu_title' => $title,
                'menu_route' => $menu['routes'][$key] ?? null,
                'layer' => 'permission',
            ]);
        }
    }

    /** @param list<string> $middleware */
    private function shortMiddleware(array $middleware): array
    {
        return array_values(array_map(static function (string $entry): string {
            $separator = strrpos($entry, '\\');

            return $separator === false ? $entry : substr($entry, $separator + 1);
        }, $middleware));
    }

    // ------------------------------------------------------------------ views

    /** @param array<string,array<string,mixed>> $artifacts */
    private function addViews(Graph $graph, array $artifacts): void
    {
        foreach ($artifacts as $path => $artifact) {
            if (($artifact['view_name'] ?? null) === null) {
                continue;
            }

            $viewId = 'view:'.$artifact['view_name'];
            $blade = $artifact['blade'];

            $graph->addNode($viewId, 'view', [
                'name' => $artifact['view_name'],
                'path' => $path,
                'module' => $artifact['module'],
                'layer' => 'view',
                'extends' => $blade['extends'],
                'permissions' => $blade['permissions'],
                'client_side' => $blade['client_side'],

                // What the screen says about itself. The heading doubles as
                // the summary because it is what a person calls the screen —
                // «Discount Codes», «مكافآت الشهر» — and a view had no summary
                // at all before, so it ranked on its dotted name alone.
                'summary' => $this->viewSummary($blade),
                'headings' => $blade['headings'] ?? [],
                'labels' => $blade['labels'] ?? [],
                'fields' => $blade['fields'] ?? [],
                'translation_keys' => $blade['translation_keys'] ?? [],
                'components' => $blade['components'] ?? [],
            ]);

            $graph->addEdge('file:'.$path, $viewId, 'contains');

            foreach ($blade['includes'] as $include) {
                $graph->addEdge($viewId, 'view:'.$include, 'includes');
            }
            if ($blade['extends'] !== null) {
                $graph->addEdge($viewId, 'view:'.$blade['extends'], 'includes');
            }
            foreach ($blade['permissions'] as $permission) {
                $graph->addEdge($viewId, 'permission:'.$permission, 'guarded_by');
            }
            foreach ($blade['routes'] as $routeName) {
                $graph->addEdge($viewId, 'route:'.$routeName, 'references');
            }
        }
    }

    /**
     * A one-line description of a screen, from its own headings.
     *
     * @param  array<string,mixed>  $blade
     */
    private function viewSummary(array $blade): string
    {
        $parts = array_slice($blade['headings'] ?? [], 0, 3);

        if ($parts === [] && ($blade['section'] ?? null) !== null) {
            $parts = [Text::humanise((string) $blade['section'])];
        }

        $summary = trim(implode(' · ', $parts));

        return mb_strlen($summary) > 160 ? rtrim(mb_substr($summary, 0, 159)).'…' : $summary;
    }

    // ------------------------------------------------------------------ tests

    /**
     * @param  array<string,array<string,mixed>>  $artifacts
     * @param  array{routes:list<array<string,mixed>>}  $routes
     */
    private function addTests(Graph $graph, array $artifacts, array $routes): void
    {
        $byUri = [];
        foreach ($routes['routes'] as $route) {
            $byUri[$route['uri']] = 'route:'.($route['name'] ?? (implode(',', $route['methods']).' '.$route['uri']));
        }

        foreach ($artifacts as $path => $artifact) {
            if (! in_array($artifact['layer'], ['test', 'browser-test'], true)) {
                continue;
            }

            $testId = 'test:'.$path;
            $classes = $artifact['classes'] ?? [];
            $first = $classes[0] ?? null;

            $cases = $first['test']['cases'] ?? ($artifact['js']['describes'] ?? []);

            $graph->addNode($testId, 'test', [
                'name' => basename($path),
                'path' => $path,
                'module' => $artifact['module'],
                'layer' => $artifact['layer'],
                'summary' => $first['summary'] ?? '',
                'cases' => array_slice($cases, 0, 40),
                'case_count' => count($cases),
            ]);

            $graph->addEdge('file:'.$path, $testId, 'contains');

            // A test names the classes it imports. That is the honest signal —
            // a test importing `SettlementService` is a test of settlement.
            foreach ($artifact['imports'] ?? [] as $fqcn) {
                if (isset($this->classIds[$fqcn])) {
                    $graph->addEdge($this->classIds[$fqcn], $testId, 'tested_by');
                }
            }

            foreach ($first['test']['routes'] ?? [] as $routeName) {
                $graph->addEdge('route:'.$routeName, $testId, 'tested_by');
            }

            foreach (array_merge($first['test']['uris'] ?? [], $artifact['js']['urls'] ?? []) as $uri) {
                if (isset($byUri[$uri])) {
                    $graph->addEdge($byUri[$uri], $testId, 'tested_by');
                }
            }
        }
    }

    // --------------------------------------------------------------- commands

    /** @param array<string,array<string,mixed>> $artifacts */
    private function addCommands(Graph $graph, array $artifacts): void
    {
        foreach ($artifacts as $path => $artifact) {
            foreach ($artifact['classes'] ?? [] as $class) {
                $signature = $class['command']['name'] ?? null;
                if ($signature === null) {
                    continue;
                }

                $commandId = 'command:'.$signature;

                $graph->addNode($commandId, 'command', [
                    'name' => $signature,
                    'path' => $path,
                    'module' => $artifact['module'],
                    'layer' => 'command',
                    'summary' => $class['command']['description'] ?: $class['summary'],
                    'class' => $class['fqcn'],
                ]);

                $graph->addEdge($commandId, 'class:'.$class['fqcn'], 'references');
            }
        }
    }

    /** @param array<string,array<string,mixed>> $artifacts */
    private function addIntegrations(Graph $graph, array $artifacts): void
    {
        foreach ($artifacts as $path => $artifact) {
            foreach ($artifact['integrations'] ?? [] as $integration) {
                $id = 'integration:'.$integration;

                $graph->addNode($id, 'integration', [
                    'name' => Text::humanise($integration),
                    'module' => 'Platform',
                    'layer' => 'integration',
                ]);

                $graph->addEdge('file:'.$path, $id, 'integrates');
            }
        }
    }

    /**
     * A view belongs to whichever module renders it.
     *
     * The directory `resources/views/admin/driver_bonus/` looks like a module
     * and is not one — it is a screen of `app/Modules/Driver`. Deriving a
     * module from the view path produced fifty-four "modules", two dozen of
     * which were screens wearing a module's name, and every module count in
     * the brain was then wrong.
     *
     * The `renders` edge is the evidence: `DriverBonusAwardController::index`
     * calls `view('admin.driver_bonus.index')`, so the view is the Driver
     * module's. A view nothing renders — a partial included by another view —
     * inherits from its includer, and a view with neither keeps the path-derived
     * name, which is the best available answer rather than a guess.
     */
    private function reattachViews(Graph $graph): void
    {
        $owner = [];

        foreach ($graph->edges() as $edge) {
            if ($edge['type'] !== 'renders') {
                continue;
            }
            $renderer = $graph->node($edge['from']);
            if ($renderer !== null && ($renderer['module'] ?? null) !== null) {
                $owner[$edge['to']] ??= $renderer['module'];
            }
        }

        // Propagate to partials and layouts, two hops — `index` includes
        // `partials/_x_table_body`, which includes a shared component.
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($graph->edges() as $edge) {
                if ($edge['type'] !== 'includes') {
                    continue;
                }
                // A layout is included by everything; leaving it in the module
                // of whichever view happened to be walked first would be
                // arbitrary, so shared shells keep their own place.
                if (str_starts_with($edge['to'], 'view:layouts.')
                    || str_starts_with($edge['to'], 'view:components.')) {
                    continue;
                }
                if (isset($owner[$edge['from']])) {
                    $owner[$edge['to']] ??= $owner[$edge['from']];
                }
            }
        }

        foreach ($owner as $viewId => $module) {
            $view = $graph->node($viewId);
            if ($view === null) {
                continue;
            }

            $graph->setAttribute($viewId, 'module', $module);

            if (($view['path'] ?? null) !== null) {
                $graph->setAttribute('file:'.$view['path'], 'module', $module);
                $graph->addEdge('module:'.$module, 'file:'.$view['path'], 'contains');
            }
        }

        // Modules that now contain nothing were only ever view directories.
        foreach ($graph->ofType('module') as $module) {
            $has = false;
            foreach ($graph->out($module['id'], ['contains']) as $child) {
                $node = $graph->node($child['id']);
                if ($node !== null && ($node['module'] ?? null) === $module['name']) {
                    $has = true;
                    break;
                }
            }
            if (! $has) {
                $graph->removeNode($module['id']);
            }
        }
    }

    /** @param array<string,mixed> $git */
    private function addGit(Graph $graph, array $git): void
    {
        foreach ($git['churn'] ?? [] as $path => $count) {
            $graph->setAttribute('file:'.$path, 'churn', $count);
            $graph->setAttribute('file:'.$path, 'last_changed', $git['last_changed'][$path] ?? null);
        }

        foreach ($git['co_change'] ?? [] as $path => $partners) {
            foreach ($partners as $partner) {
                $graph->addEdge('file:'.$path, 'file:'.$partner['path'], 'co_changes', [
                    'commits' => $partner['count'],
                ]);
            }
        }
    }

    private function moduleOfFqcn(string $fqcn): string
    {
        if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\/', $fqcn, $matches) === 1) {
            return $matches[1];
        }
        if (str_starts_with($fqcn, 'App\\Http\\Controllers\\Api\\')) {
            return 'Api';
        }
        if (str_starts_with($fqcn, 'App\\Http\\Controllers\\Auth\\')) {
            return 'Auth';
        }
        if (str_starts_with($fqcn, 'App\\Http\\Controllers\\Admin\\')) {
            return 'AdminShell';
        }

        return 'Platform';
    }
}
