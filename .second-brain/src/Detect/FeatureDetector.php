<?php

namespace Laundo\SecondBrain\Detect;

use Laundo\SecondBrain\Graph\Graph;
use Laundo\SecondBrain\Support\Text;

/**
 * Features — the layer between "here is a file" and "here is what the product
 * does".
 *
 * A feature is only created where the source can point at an **entry point**:
 * a registered route, a console command, or a domain service class. Nothing is
 * inferred from a name alone, because a feature list somebody cannot verify is
 * worse than no feature list — it sends a reader to a capability that does not
 * exist and they lose the time twice.
 *
 * Three evidence kinds, and each feature says which one made it:
 *
 * - `route`   — a registered route, grouped into a capability by its action.
 *               `admin.coupon.store` is "Create", `api.v1.coupons.check` is
 *               its own capability because nothing else shares that verb.
 * - `command` — an artisan command, scheduled or not.
 * - `service` — a domain service class (not a `{name}CrudService`). These are
 *               capabilities with rules in them — `LaundryAssigner`,
 *               `OrderStateMachine`, `SettlementService` — and several have no
 *               endpoint of their own at all, which is a fact about this
 *               codebase worth surfacing rather than hiding.
 *
 * Each feature is then filled in by walking the graph outward from the entry
 * point: the request that validates it, the service it delegates to, the
 * repository under that, the models, the tables those models map to, the view
 * it renders and the tests that name any of them.
 */
final class FeatureDetector
{
    /** Controller actions that mean the same thing on every screen here. */
    private const CAPABILITIES = [
        'index' => ['Browse', 10],
        'search' => ['Browse', 11],
        'create' => ['Create', 20],
        'store' => ['Create', 21],
        'edit' => ['Update', 30],
        'update' => ['Update', 31],
        'show' => ['View one', 15],
        'destroy' => ['Delete', 40],
        'delete' => ['Delete', 41],
        'toggleStatus' => ['Toggle status', 50],
    ];

    /**
     * @param  array<string,mixed>  $menu
     * @param  list<array<string,mixed>>  $communities
     * @return list<array<string,mixed>>
     */
    public function detect(Graph $graph, array $menu, array $communities): array
    {
        $moduleToCommunity = $this->moduleToCommunity($communities);

        $features = array_merge(
            $this->fromRoutes($graph, $menu, $moduleToCommunity),
            $this->fromCommands($graph, $moduleToCommunity),
            $this->fromDomainServices($graph, $moduleToCommunity),
        );

        usort($features, static fn ($a, $b) => [$a['group'], $a['order'], $a['label']] <=> [$b['group'], $b['order'], $b['label']]);

        return $features;
    }

    /**
     * @param  array<string,mixed>  $menu
     * @param  array<string,string>  $moduleToCommunity
     * @return list<array<string,mixed>>
     */
    private function fromRoutes(Graph $graph, array $menu, array $moduleToCommunity): array
    {
        $buckets = [];

        foreach ($graph->ofType('route') as $route) {
            $name = $route['name'] ?? null;
            $action = $route['action'] ?? '';

            if ($action === '' || $action === 'Closure' || ! str_contains($action, '@')) {
                continue;   // a closure route has no implementation to point at
            }

            [$controller, $method] = explode('@', $action, 2);

            // The group is the screen, not the module: `driver_earning` and
            // `refund` are different screens on one Payment module, and folding
            // them together would send a reader to six controllers.
            $group = $this->groupFor($route, $controller, $menu);
            [$capability, $order] = self::CAPABILITIES[$method] ?? [Text::humanise($method), 60];

            $key = $group.'|'.$capability;

            $buckets[$key] ??= [
                'group' => $group,
                'capability' => $capability,
                'order' => $order,
                'routes' => [],
                'methods' => [],
                'surface' => $route['surface'] ?? 'panel',
            ];

            $buckets[$key]['routes'][] = $route['id'];
            $buckets[$key]['methods'][] = 'method:'.$controller.'::'.$method;
        }

        $features = [];

        foreach ($buckets as $bucket) {
            $features[] = $this->assemble(
                $graph,
                $bucket['group'],
                $bucket['capability'],
                'route',
                $bucket['routes'],
                array_values(array_unique($bucket['methods'])),
                $bucket['order'],
                $moduleToCommunity,
                $bucket['surface'],
            );
        }

        return $features;
    }

    /**
     * @param  array<string,mixed>  $route
     * @param  array<string,mixed>  $menu
     */
    private function groupFor(array $route, string $controller, array $menu): string
    {
        $name = (string) ($route['name'] ?? '');

        // `admin.driver_bonus_rule.index` → `driver_bonus_rule`, which is the
        // menu key and therefore the screen's own name.
        if (preg_match('/^admin\.([a-z0-9_]+)\./', $name, $matches) === 1) {
            $key = $matches[1];

            return $menu['titles'][$key] ?? Text::humanise($key);
        }

        if (preg_match('/^api\.v1\.(?:auth\.)?([a-z0-9_-]+)/', $name, $matches) === 1) {
            return 'API · '.Text::humanise(str_replace('-', ' ', $matches[1]));
        }

        $separator = strrpos($controller, '\\');
        $basename = $separator === false ? $controller : substr($controller, $separator + 1);

        return Text::humanise(preg_replace('/Controller$/', '', $basename) ?? $basename);
    }

    /**
     * @param  array<string,string>  $moduleToCommunity
     * @return list<array<string,mixed>>
     */
    private function fromCommands(Graph $graph, array $moduleToCommunity): array
    {
        $features = [];

        foreach ($graph->ofType('command') as $command) {
            $features[] = $this->assemble(
                $graph,
                'Scheduled · '.Text::humanise($command['name']),
                'Run',
                'command',
                [$command['id']],
                [],
                70,
                $moduleToCommunity,
                'console',
                $command['class'] ?? null,
            );
        }

        return $features;
    }

    /**
     * Domain services: the classes with the rules in them.
     *
     * A `{name}CrudService` is excluded — it is the view-data assembler every
     * module has and its capability is already described by the routes that
     * call it. What is left is the part of the product that is not CRUD, and
     * some of it (`OrderStateMachine`, `LaundryAssigner`) is the domain core.
     *
     * @param  array<string,string>  $moduleToCommunity
     * @return list<array<string,mixed>>
     */
    private function fromDomainServices(Graph $graph, array $moduleToCommunity): array
    {
        $features = [];

        foreach ($graph->nodes() as $id => $node) {
            if (($node['layer'] ?? null) !== 'service' || $node['type'] !== 'class') {
                continue;
            }
            if (str_ends_with($node['name'], 'CrudService') || str_ends_with($node['name'], 'crudService')) {
                continue;
            }

            $entryPoints = $this->entryPointsReaching($graph, $id);

            $features[] = $this->assemble(
                $graph,
                Text::humanise($node['name']),
                'Domain rule',
                'service',
                $entryPoints,
                array_map(
                    static fn (string $method) => 'method:'.$node['fqcn'].'::'.$method,
                    $node['methods'] ?? []
                ),
                65,
                $moduleToCommunity,
                $entryPoints === [] ? 'internal' : 'mixed',
                $node['fqcn'],
            );
        }

        return $features;
    }

    /**
     * Routes and commands that reach a class, at most three hops out.
     *
     * Three because that is the depth of the layer contract here — route →
     * controller method → service method → repository — and a fourth would
     * start returning "everything reaches the Order model", which is true and
     * useless.
     *
     * @return list<string>
     */
    private function entryPointsReaching(Graph $graph, string $classId): array
    {
        $frontier = [$classId];
        $seen = [$classId => true];
        $entries = [];

        for ($depth = 0; $depth < 3 && $frontier !== []; $depth++) {
            $next = [];

            foreach ($frontier as $current) {
                foreach ($graph->in($current, ['calls', 'depends_on', 'contains', 'route_to', 'references']) as $inbound) {
                    if (isset($seen[$inbound['id']])) {
                        continue;
                    }
                    $seen[$inbound['id']] = true;

                    $node = $graph->node($inbound['id']);
                    if ($node === null) {
                        continue;
                    }

                    if ($node['type'] === 'route' || $node['type'] === 'command') {
                        $entries[] = $inbound['id'];

                        continue;
                    }

                    $next[] = $inbound['id'];
                }
            }

            $frontier = $next;
        }

        sort($entries);

        return array_slice(array_values(array_unique($entries)), 0, 25);
    }

    /**
     * Fill a feature in by walking out from its entry points.
     *
     * @param  list<string>  $entryPoints
     * @param  list<string>  $seedMethods
     * @param  array<string,string>  $moduleToCommunity
     * @return array<string,mixed>
     */
    private function assemble(
        Graph $graph,
        string $group,
        string $capability,
        string $kind,
        array $entryPoints,
        array $seedMethods,
        int $order,
        array $moduleToCommunity,
        string $surface,
        ?string $seedClass = null,
    ): array {
        $classes = [];
        $views = [];
        $tables = [];
        $tests = [];
        $permissions = [];
        $modules = [];

        $seeds = $seedMethods;
        if ($seedClass !== null) {
            $seeds[] = 'class:'.$seedClass;
        }

        foreach ($entryPoints as $entryId) {
            $entry = $graph->node($entryId);
            if ($entry === null) {
                continue;
            }
            if (($entry['permission'] ?? null) !== null) {
                $permissions[] = $entry['permission'];
            }
            foreach ($graph->out($entryId, ['route_to', 'references']) as $out) {
                $seeds[] = $out['id'];
            }
            foreach ($graph->out($entryId, ['tested_by']) as $out) {
                // A test attached to the entry point itself is the most
                // specific evidence there is — it named this route's URI.
                $tests[$out['id']] = max($tests[$out['id']] ?? 0.0, 1.2);
            }
        }

        // Breadth-first over the implementation edges, capped at three layers.
        $frontier = array_values(array_unique(array_filter($seeds, static fn ($id) => $graph->hasNode($id))));
        $seen = array_flip($frontier);

        // The module the feature starts in, read off its seeds — the
        // controller or the domain service the entry point reaches. Used to
        // tell the feature's own classes from the shared ones it passes
        // through.
        $homeModule = null;
        foreach ($frontier as $seed) {
            $homeModule = $graph->node($seed)['module'] ?? null;
            if ($homeModule !== null) {
                break;
            }
        }

        for ($depth = 0; $depth < 3 && $frontier !== []; $depth++) {
            $next = [];

            foreach ($frontier as $current) {
                $node = $graph->node($current);
                if ($node === null) {
                    continue;
                }

                if ($node['type'] === 'class' || $node['type'] === 'enum') {
                    $classes[$current] = $this->relevanceFor($node, $depth);
                    $modules[] = $node['module'] ?? null;
                }
                if ($node['type'] === 'view') {
                    $views[] = $node['name'];
                }
                if ($node['type'] === 'table') {
                    $tables[] = $node['name'];
                }
                if ($node['type'] === 'method') {
                    $modules[] = $node['module'] ?? null;
                }

                foreach ($graph->out($current, ['contains', 'calls', 'depends_on', 'validates', 'renders', 'maps_to']) as $out) {
                    if (isset($seen[$out['id']])) {
                        continue;
                    }
                    $seen[$out['id']] = true;
                    $next[] = $out['id'];
                }

                // A test earns its place by how central the class that named
                // it is to this feature. `tested_by` is import-based, so a
                // feature reaching `User` or `OrderTask` a couple of hops out
                // otherwise drags in every test that happens to import them —
                // `ComplaintTest` was listed under "approve a driver bonus",
                // which teaches a reader to ignore the list.
                //
                // Depth alone is the wrong discriminator: `Coupon` is two hops
                // from the coupon Create action and is exactly the right class
                // to find `CouponTest` through. What separates the two cases is
                // **whose module the class belongs to** — a shared model
                // reached sideways is noise, the feature's own model is not.
                $sameModule = ($node['module'] ?? null) === $homeModule;

                $weight = $this->relevanceFor($node, 0)
                    * (0.75 ** $depth)
                    * ($sameModule ? 1.0 : 0.4);

                foreach ($graph->out($current, ['tested_by']) as $out) {
                    $tests[$out['id']] = max($tests[$out['id']] ?? 0.0, $weight);
                }
            }

            $frontier = $next;
        }

        arsort($classes);

        $modules = array_values(array_unique(array_filter($modules)));
        $module = $modules[0] ?? 'Platform';

        $id = $this->slug($group).'.'.$this->slug($capability);

        return [
            'id' => $id,
            'label' => $group.' · '.$capability,
            'group' => $group,
            'capability' => $capability,
            'kind' => $kind,
            'surface' => $surface,
            'order' => $order,
            'module' => $module,
            'modules' => $modules,
            'community' => $moduleToCommunity[$module] ?? null,
            'entry_points' => $entryPoints,
            'permissions' => array_values(array_unique($permissions)),
            'files' => $this->filesFor($graph, array_keys($classes)),
            'classes' => array_slice(array_keys($classes), 0, 20),
            'views' => array_slice(array_values(array_unique($views)), 0, 12),
            'tables' => array_values(array_unique($tables)),
            'tests' => $this->rankedTests($tests),
            'evidence' => $this->evidenceFor($kind, $entryPoints, $seedClass),
        ];
    }

    /**
     * The tests most specific to this feature, best first.
     *
     * Six, not twelve. A list long enough to contain the right test and ten
     * others is a list nobody reads to the end of.
     *
     * @param  array<string,float>  $weighted  test id => specificity
     * @return list<string>
     */
    private function rankedTests(array $weighted): array
    {
        arsort($weighted);

        $best = reset($weighted);
        if ($best === false) {
            return [];
        }

        // A third of the best match. In practice this keeps the tests reached
        // through the feature's own controller, service and models, and drops
        // the ones reached at the third hop through a shared model like `User`
        // — which is every test in the suite.
        $floor = $best / 3;

        $kept = array_keys(array_filter(
            $weighted,
            static fn (float $weight) => $weight >= $floor
        ));

        return array_slice($kept, 0, 6);
    }

    /**
     * Relevance for a file inside a feature. The layer decides it: somebody
     * asking where a feature lives wants the service before the model and the
     * model before the enum, and the distance from the entry point breaks ties.
     *
     * @param  array<string,mixed>  $node
     */
    private function relevanceFor(array $node, int $depth): float
    {
        $base = match ($node['layer'] ?? '') {
            'service' => 0.95,
            'controller', 'api-controller' => 0.90,
            'model' => 0.85,
            'repository' => 0.80,
            'request' => 0.70,
            'enum' => 0.60,
            'command' => 0.60,
            default => 0.45,
        };

        return round(max(0.05, $base - ($depth * 0.12)), 3);
    }

    /**
     * @param  list<string>  $classIds
     * @return list<array{path:string,layer:string,relevance:float}>
     */
    private function filesFor(Graph $graph, array $classIds): array
    {
        $files = [];

        foreach ($classIds as $index => $classId) {
            $node = $graph->node($classId);
            if ($node === null || ($node['path'] ?? null) === null) {
                continue;
            }

            $path = $node['path'];
            $relevance = round(max(0.1, 1.0 - ($index * 0.05)), 3);

            if (! isset($files[$path]) || $files[$path]['relevance'] < $relevance) {
                $files[$path] = [
                    'path' => $path,
                    'layer' => $node['layer'] ?? 'other',
                    'relevance' => $relevance,
                ];
            }
        }

        $files = array_values($files);
        usort($files, static fn ($a, $b) => $b['relevance'] <=> $a['relevance']);

        return array_slice($files, 0, 15);
    }

    /**
     * @param  list<string>  $entryPoints
     * @return list<string>
     */
    private function evidenceFor(string $kind, array $entryPoints, ?string $seedClass): array
    {
        $evidence = [];

        foreach (array_slice($entryPoints, 0, 4) as $entry) {
            $evidence[] = $entry;
        }
        if ($seedClass !== null) {
            $evidence[] = 'class:'.$seedClass;
        }
        if ($evidence === []) {
            $evidence[] = 'kind:'.$kind.' with no reachable entry point — internal capability';
        }

        return $evidence;
    }

    /** @param list<array<string,mixed>> $communities */
    private function moduleToCommunity(array $communities): array
    {
        return CommunityDetector::primaryCommunities($communities);
    }

    private function slug(string $text): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text) ?? $text);

        return trim($slug, '-');
    }
}
