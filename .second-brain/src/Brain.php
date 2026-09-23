<?php

namespace Laundo\SecondBrain;

use Laundo\SecondBrain\Graph\Graph;
use Laundo\SecondBrain\Graph\Vocabulary;
use Laundo\SecondBrain\Search\SearchEngine;
use Laundo\SecondBrain\Support\Json;
use Laundo\SecondBrain\Support\Paths;

/**
 * The read side. One class, loaded by the CLI and by the MCP server alike, so
 * a question answered at the terminal and the same question asked by Claude
 * cannot give different answers.
 *
 * **Everything here is built to return metadata, never source.** The whole
 * point is "find first, read second": the brain says which four files matter
 * and how they connect, and the reader opens those four instead of grepping
 * three hundred. A tool that returned file contents would spend more context
 * than the exploration it replaced.
 *
 * Loading is lazy and per-file. `search` never reads the module detail,
 * `get_module` never reads the lexical index — which keeps a cold MCP call
 * around a tenth of the cost of loading the whole brain.
 */
final class Brain
{
    /** @var array<string,mixed> */
    private array $loaded = [];

    private ?Graph $graph = null;

    private ?SearchEngine $engine = null;

    public function __construct(private readonly Paths $paths) {}

    public static function open(?string $start = null): self
    {
        return new self(Paths::discover($start));
    }

    public function isBuilt(): bool
    {
        return is_file($this->paths->data('manifest.json'));
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return $this->load('manifest.json', []);
    }

    // ----------------------------------------------------------------- search

    /**
     * @return array<string,mixed>
     */
    public function search(string $query, int $limit = 10, ?string $type = null, ?string $module = null): array
    {
        $engine = $this->engine();
        $intent = $engine->intentOf($query);

        // A question the graph answers better than a ranking does is handed
        // over rather than ranked.
        if ($intent['kind'] === 'dependents' && $intent['subject'] !== null) {
            $resolved = $this->resolve($intent['subject']);
            if ($resolved !== null) {
                return [
                    'query' => $query,
                    'answered_as' => 'dependencies (inbound)',
                    'subject' => $resolved['id'],
                ] + $this->dependencies($intent['subject'], 'inbound', 1);
            }
        }

        if ($intent['kind'] === 'dependencies' && $intent['subject'] !== null) {
            $resolved = $this->resolve($intent['subject']);
            if ($resolved !== null) {
                return [
                    'query' => $query,
                    'answered_as' => 'dependencies (outbound)',
                    'subject' => $resolved['id'],
                ] + $this->dependencies($intent['subject'], 'outbound', 1);
            }
        }

        if ($intent['kind'] === 'related' && $intent['subject'] !== null) {
            return ['query' => $query, 'answered_as' => 'related'] + $this->related($intent['subject'], $limit);
        }

        if (str_starts_with($intent['kind'], 'permission_gate') && $intent['subject'] !== null) {
            $gate = $this->permissionGate($intent['subject']);
            if ($gate !== null) {
                return ['query' => $query, 'answered_as' => 'permission_gate'] + $gate;
            }
            // Nothing matched the subject — fall through and rank it, rather
            // than answering "no permissions" to a question about a screen the
            // asker just spelled slightly differently.
        }

        $found = $engine->search($query, $limit, $type, $module);
        $results = array_map(fn (array $r) => $this->decorate($r), $found['results']);
        $features = $this->featuresFor($found['results']);

        $expansion = $this->expandToFeature($query, $found['results'], $features);

        return array_filter([
            'query' => $query,
            'answered_as' => 'search',
            'intent' => $found['intent'],
            'task_kind' => $expansion['task_kind'],
            'terms' => $found['terms'],
            'expanded_with' => $found['expanded'],

            // `results` **is** the primary-hits list, kept under its original
            // name because every existing caller and test reads it. It is
            // deliberately not also emitted as `primary_hits`: doing that
            // doubled the largest part of the payload and pushed the average
            // response from 505 to 1,070 tokens for no new information.
            'results' => $results,

            'then_read' => $expansion['then_read'],
            'related' => $expansion['related'],
            'suggested_features' => $features,
            'next' => 'Read `results` first, then `then_read` — that is the rest of the change set. Open nothing else until those are read.',
        ], static fn ($value) => $value !== null && $value !== []);
    }

    /**
     * What gates a screen, answered from `route --guarded_by--> permission`
     * rather than by ranking documents that contain the word "permission".
     *
     * The subject is matched against route names and URIs as well as symbols,
     * because people name screens the way the sidebar does — "the commission
     * rules screen", "driver bonus" — not by controller class.
     *
     * Returns null when nothing plausible matches, so the caller can fall back
     * to ranking rather than assert that a screen has no permissions.
     *
     * @return array<string,mixed>|null
     */
    private function permissionGate(string $subject): ?array
    {
        $graph = $this->graph();
        $needle = mb_strtolower(trim($subject));

        // Trailing words people add that are not part of any identifier.
        $needle = trim(preg_replace('/\b(screen|page|module|section|list|the)\b/i', ' ', $needle) ?? $needle);
        $tokens = \Laundo\SecondBrain\Support\Text::tokens($needle);

        if ($tokens === []) {
            return null;
        }

        $routes = [];

        foreach ($graph->ofType('route') as $route) {
            $haystack = \Laundo\SecondBrain\Support\Text::tokens(
                ($route['name'] ?? '').' '.($route['uri'] ?? '').' '.($route['action'] ?? '')
            );

            $overlap = count(array_intersect($tokens, $haystack));
            if ($overlap === 0) {
                continue;
            }

            // Every query token has to appear, or "commission rule" would
            // match every route carrying the word "rule".
            if ($overlap < count($tokens)) {
                continue;
            }

            $routes[] = $route;
        }

        if ($routes === []) {
            return null;
        }

        $permissions = [];
        $controllers = [];
        $rows = [];

        foreach ($routes as $route) {
            $permission = $route['permission'] ?? null;
            if ($permission !== null) {
                $permissions[$permission] = true;
            }

            foreach ($graph->out($route['id'], ['route_to']) as $edge) {
                $node = $graph->node($edge['id']);
                if ($node !== null && ($node['path'] ?? null) !== null && $node['type'] !== 'method') {
                    $controllers[$node['path']] = true;
                }
            }

            $rows[] = array_filter([
                'route' => $route['name'] ?? $route['uri'],
                'methods' => implode('|', $route['methods'] ?? []),
                'uri' => $route['uri'] ?? null,
                'permission' => $permission,
                'middleware' => implode(', ', array_slice($route['middleware'] ?? [], 0, 4)),
            ], static fn ($v) => $v !== null && $v !== '');
        }

        // The declaring file, so somebody can go and change the gate.
        $declaredIn = [];
        foreach ($routes as $route) {
            $declaredIn[($route['surface'] ?? '') === 'api' ? 'routes/api.php' : 'routes/web.php'] = true;
        }

        return array_filter([
            'intent' => 'permission_gate',
            'target' => $subject,
            'permissions' => array_keys($permissions),
            'routes' => array_slice($rows, 0, 12),
            'controllers' => array_keys($controllers),
            'declared_in' => array_keys($declaredIn),
            'note' => $permissions === []
                ? 'These routes carry no `permission:` middleware — they are gated only by `auth` and `dashboard.only`, if at all.'
                : 'Enforced by CheckPermission middleware on the route; the Blade side uses canDo() with the same slug.',
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * The rest of the change set.
     *
     * Ranking answers "where do I start"; it does not answer "what else does
     * this touch", and the validation showed the gap plainly: a task to add a
     * translatable field returned the service and none of the request, the
     * model or the Blade form — even though CLAUDE.md names all four, and the
     * feature record already holds them.
     *
     * So after ranking, the strongest feature is resolved and its file set is
     * filtered **by what kind of task this is**. A database task wants the
     * migration and the model; a Blade task wants the view and its controller.
     * Returning the whole feature instead would be the opposite mistake —
     * twenty files is not an answer either.
     *
     * @param  list<array<string,mixed>>  $results
     * @param  list<array{id:string,label:string}>  $features
     * @return array{task_kind:string,then_read:list<array<string,string>>,related:array<string,mixed>}
     */
    private function expandToFeature(string $query, array $results, array $features): array
    {
        $kind = $this->taskKind($query, $results);
        $empty = ['task_kind' => $kind, 'then_read' => [], 'related' => []];

        $featureId = $features[0]['id'] ?? null;
        if ($featureId === null) {
            return $empty;
        }

        $feature = null;
        foreach ($this->features() as $candidate) {
            if ($candidate['id'] === $featureId) {
                $feature = $candidate;
                break;
            }
        }

        if ($feature === null) {
            return $empty;
        }

        $already = array_values(array_filter(array_column($results, 'path')));
        $wanted = self::EXPANSION[$kind] ?? self::EXPANSION['default'];

        $candidates = [];

        foreach ($feature['files'] as $file) {
            $position = array_search($file['layer'], $wanted, true);
            if ($position === false || in_array($file['path'], $already, true)) {
                continue;
            }
            $candidates[$file['path']] = [
                'rank' => $position,
                'relevance' => $file['relevance'],
                'layer' => $file['layer'],
            ];
        }

        // Views are held on a feature by name, and migrations only through the
        // tables they write — both have to be resolved back to a path before
        // they can be read.
        if (in_array('view', $wanted, true)) {
            foreach ($feature['views'] as $name) {
                $node = $this->graph()->node('view:'.$name);
                $path = $node['path'] ?? null;
                if ($path !== null && ! in_array($path, $already, true)) {
                    $candidates[$path] ??= [
                        'rank' => (int) array_search('view', $wanted, true),
                        'relevance' => 0.5,
                        'layer' => 'view',
                    ];
                }
            }
        }

        if (in_array('migration', $wanted, true)) {
            foreach ($feature['tables'] as $table) {
                $node = $this->graph()->node('table:'.$table);
                $path = $node['created_by'] ?? null;
                if ($path !== null && ! in_array($path, $already, true)) {
                    $candidates[$path] ??= [
                        'rank' => (int) array_search('migration', $wanted, true),
                        'relevance' => 0.5,
                        'layer' => 'migration',
                    ];
                }
            }
        }

        uasort($candidates, static fn (array $a, array $b) => [$a['rank'], -$a['relevance']] <=> [$b['rank'], -$b['relevance']]);

        $thenRead = [];
        foreach (array_slice($candidates, 0, self::THEN_READ_CAP, true) as $path => $meta) {
            $thenRead[] = [
                'path' => $path,
                'why' => self::REASONS[$meta['layer']] ?? $meta['layer'],
            ];
        }

        return [
            'task_kind' => $kind,
            'then_read' => $thenRead,
            'related' => array_filter([
                'feature' => $feature['label'],
                'module' => $feature['module'],
                'tables' => array_slice($feature['tables'], 0, 6),
                'tests' => array_slice(array_map(
                    static fn (string $t) => str_replace('test:', '', $t),
                    $feature['tests']
                ), 0, 3),
                'permissions' => array_slice($feature['permissions'], 0, 4),
            ], static fn ($v) => $v !== null && $v !== []),
        ];
    }

    /** At most six. A change set nobody finishes reading is not a change set. */
    private const THEN_READ_CAP = 6;

    /**
     * Which layers a task of each kind actually needs, in reading order.
     *
     * Taken from this project's own layer contract rather than from a general
     * idea of MVC: a CRUD change here really does mean request → service →
     * model → view, because `shredData()` and the shared Blade partials make
     * that the shape of every module.
     */
    private const EXPANSION = [
        'crud' => ['request', 'service', 'model', 'view', 'repository', 'controller'],
        'api' => ['api-controller', 'request', 'service', 'model', 'enum'],
        'database' => ['migration', 'model', 'repository', 'service'],
        'blade' => ['view', 'controller', 'request', 'service'],
        'permission' => ['controller', 'routes', 'service'],
        'service' => ['service', 'repository', 'model', 'enum'],
        'report' => ['service', 'repository', 'model', 'command'],
        'default' => ['service', 'controller', 'model', 'request', 'repository'],
    ];

    /** Terse on purpose — this rides in every response. */
    private const REASONS = [
        'request' => 'validation',
        'service' => 'business rules',
        'repository' => 'queries',
        'model' => 'persistence + relationships',
        'view' => 'the screen',
        'controller' => 'entry point',
        'api-controller' => 'entry point + payload shape',
        'migration' => 'schema',
        'enum' => 'vocabulary',
        'command' => 'scheduled entry point',
        'routes' => 'route + permission',
    ];

    /**
     * What kind of change is being asked for.
     *
     * Deliberately a keyword vote and not a classifier: the vocabulary is
     * small, the categories are this repository's own, and a wrong guess costs
     * a slightly different expansion rather than a wrong answer. The top hit's
     * layer breaks ties, because "where is the delivery fee worked out"
     * contains no category word at all and its answer is plainly a service.
     *
     * @param  list<array<string,mixed>>  $results
     */
    private function taskKind(string $query, array $results): string
    {
        $text = ' '.mb_strtolower($query).' ';

        $votes = [];
        foreach ([
            'blade' => ['screen', 'view', 'page', 'form', 'dropdown', 'button', 'checkbox', 'column', 'list screen', 'blade', 'filter'],
            'api' => ['api', 'endpoint', 'mobile', 'app receives', 'payload', 'json', 'customer app', 'driver app'],
            'database' => ['table', 'column', 'migration', 'schema', 'foreign key', 'index', 'store'],
            'permission' => ['permission', 'gate', 'gates', 'authorize', 'authorisation', 'authorization', 'access', 'role'],
            'report' => ['report', 'metric', 'digest', 'totals', 'summary email'],
            'crud' => ['add a', 'new field', 'create', 'edit', 'delete', 'update form'],
            'service' => ['how is', 'where is', 'calculated', 'worked out', 'logic', 'rule', 'decided', 'chosen'],
        ] as $kind => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    $votes[$kind] = ($votes[$kind] ?? 0) + 1;
                }
            }
        }

        if ($votes !== []) {
            arsort($votes);

            return (string) array_key_first($votes);
        }

        return match ($results[0]['layer'] ?? '') {
            'view' => 'blade',
            'api-controller' => 'api',
            'migration' => 'database',
            'service' => 'service',
            default => 'default',
        };
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function decorate(array $result): array
    {
        $node = $this->graph()->node($result['id']);

        if ($node !== null && $node['type'] === 'route') {
            $result['uri'] = $node['uri'] ?? null;
            $result['methods'] = $node['methods'] ?? [];
            $result['permission'] = $node['permission'] ?? null;
            $result['handler'] = $node['action'] ?? null;
        }

        if ($node !== null && $node['type'] === 'table') {
            $result['columns'] = count($node['columns'] ?? []);
        }

        if ($node !== null && ($node['is_model'] ?? false) === true) {
            $result['table'] = $node['table'] ?? null;
            $result['tenant_scoped'] = $node['tenant_scoped'] ?? false;
        }

        return array_filter($result, static fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @param  list<array<string,mixed>>  $results
     * @return list<array{id:string,label:string}>
     */
    private function featuresFor(array $results): array
    {
        $paths = array_values(array_filter(array_column($results, 'path')));
        if ($paths === []) {
            return [];
        }

        $scored = [];

        foreach ($this->features() as $feature) {
            $hits = 0;
            foreach ($feature['files'] as $file) {
                if (in_array($file['path'], $paths, true)) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $scored[] = ['id' => $feature['id'], 'label' => $feature['label'], 'hits' => $hits];
            }
        }

        usort($scored, static fn ($a, $b) => $b['hits'] <=> $a['hits']);

        return array_map(
            static fn (array $f) => ['id' => $f['id'], 'label' => $f['label']],
            array_slice($scored, 0, 5)
        );
    }

    // ---------------------------------------------------------------- feature

    /**
     * Accepts a feature id (`coupons.create`), a feature label, or a group name
     * (`coupons`) — in which case every capability in that group comes back.
     *
     * @return array<string,mixed>
     */
    public function feature(string $needle): array
    {
        $needle = trim($needle);
        $lower = mb_strtolower($needle);
        $features = $this->features();

        $exact = array_values(array_filter(
            $features,
            static fn (array $f) => mb_strtolower($f['id']) === $lower || mb_strtolower($f['label']) === $lower
        ));

        if ($exact !== []) {
            return ['match' => 'exact', 'features' => array_map(fn ($f) => $this->presentFeature($f), $exact)];
        }

        $group = array_values(array_filter(
            $features,
            static fn (array $f) => mb_strtolower($f['group']) === $lower
                || str_starts_with(mb_strtolower($f['id']), $lower.'.')
        ));

        if ($group !== []) {
            // Sliced, like every other collection this class returns. `API ·
            // Driver` has enough capabilities to encode at 60 KB whole, which
            // is three times the MCP cap — the biggest and most useful groups
            // were the ones that came back unusable.
            $shown = array_slice($group, 0, 10);

            return array_filter([
                'match' => 'group',
                'group' => $group[0]['group'],
                'capabilities' => array_map(static fn (array $f) => $f['capability'], $group),
                'features' => array_map(fn ($f) => $this->presentFeature($f), $shown),
                'omitted' => count($group) > count($shown)
                    ? (count($group) - count($shown)).' further capabilities — ask for one by id to see it in full'
                    : null,
            ], static fn ($value) => $value !== null);
        }

        // Fall back to the search index rather than returning nothing — a
        // near-miss on a feature name is common and a dead end is expensive.
        $fuzzy = [];
        foreach ($features as $feature) {
            similar_text($lower, mb_strtolower($feature['group']), $percent);
            if ($percent > 55 || str_contains(mb_strtolower($feature['group']), $lower)) {
                $fuzzy[] = ['id' => $feature['id'], 'label' => $feature['label'], 'score' => round($percent / 100, 2)];
            }
        }

        usort($fuzzy, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'match' => 'none',
            'asked' => $needle,
            'did_you_mean' => array_slice($fuzzy, 0, 8),
            'hint' => 'Call second_brain_search with the same words to find the code directly.',
        ];
    }

    /**
     * @param  array<string,mixed>  $feature
     * @return array<string,mixed>
     */
    private function presentFeature(array $feature): array
    {
        $graph = $this->graph();

        $entries = [];
        foreach ($feature['entry_points'] as $entryId) {
            $node = $graph->node($entryId);
            if ($node === null) {
                continue;
            }
            $entries[] = array_filter([
                'kind' => $node['type'],
                'name' => $node['name'] ?? $entryId,
                'uri' => $node['uri'] ?? null,
                'methods' => $node['methods'] ?? null,
                'handler' => $node['action'] ?? null,
                'permission' => $node['permission'] ?? null,
            ], static fn ($v) => $v !== null && $v !== []);
        }

        return array_filter([
            'id' => $feature['id'],
            'label' => $feature['label'],
            // `group` and `capability` are the two halves of the label and both
            // are what a caller filters on — "give me the Create one". Leaving
            // them out made the label the only way to tell capabilities apart,
            // which means string-matching prose.
            'group' => $feature['group'],
            'capability' => $feature['capability'],
            'summary' => $graph->node('feature:'.$feature['id'])['summary'] ?? '',
            'kind' => $feature['kind'],
            'surface' => $feature['surface'],
            'module' => $feature['module'],
            'community' => $feature['community'],
            'entry_points' => array_slice($entries, 0, 12),
            'permissions' => $feature['permissions'],
            'files' => $feature['files'],
            'views' => $feature['views'],
            'tables' => $feature['tables'],
            'tests' => array_map(static fn (string $t) => str_replace('test:', '', $t), $feature['tests']),
            'evidence' => $feature['evidence'],
        ], static fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    // ----------------------------------------------------------------- module

    /**
     * @return array<string,mixed>
     */
    public function module(string $name): array
    {
        $modules = $this->load('architecture/modules.json', []);
        $lower = mb_strtolower(trim($name));

        foreach ($modules as $module) {
            if (mb_strtolower($module['name']) !== $lower) {
                continue;
            }

            $features = array_values(array_filter(
                $this->features(),
                static fn (array $f) => $f['module'] === $module['name']
            ));

            return [
                'name' => $module['name'],
                'community' => $module['community'],
                'summary' => $module['summary'],
                'path' => $module['path'],
                'files' => $module['files'],
                'lines' => $module['lines'],
                'layers' => $module['layers'],
                'key_classes' => array_slice(array_map(
                    static fn (array $c) => ['fqcn' => $c['fqcn'], 'layer' => $c['layer'], 'path' => $c['path'], 'summary' => $c['summary']],
                    $module['classes']
                ), 0, 25),
                'models' => $module['models'],
                'owns_tables' => $module['owns_tables'],
                'routes' => array_slice($module['routes'], 0, 40),
                'route_count' => count($module['routes']),
                'permissions' => $module['permissions'],
                'depends_on' => $module['depends_on'],
                'depended_on_by' => $module['depended_on_by'],
                'tests' => $module['tests'],
                'features' => array_map(
                    static fn (array $f) => ['id' => $f['id'], 'label' => $f['label']],
                    array_slice($features, 0, 30)
                ),
            ];
        }

        $names = array_column($modules, 'name');
        $close = array_values(array_filter($names, static fn (string $n) => str_contains(mb_strtolower($n), $lower)));

        return [
            'match' => 'none',
            'asked' => $name,
            'did_you_mean' => $close !== [] ? $close : $names,
        ];
    }

    // ------------------------------------------------------------ depedencies

    /**
     * @return array<string,mixed>
     */
    public function dependencies(string $target, string $direction = 'both', int $depth = 1): array
    {
        $node = $this->resolve($target);

        if ($node === null) {
            return ['match' => 'none', 'asked' => $target, 'hint' => 'Try second_brain_search first.'];
        }

        $graph = $this->graph();
        $id = $node['id'];

        $out = $direction === 'inbound' ? [] : $this->walk($graph, $id, 'out', $depth);
        $in = $direction === 'outbound' ? [] : $this->walk($graph, $id, 'in', $depth);

        return array_filter([
            'subject' => [
                'id' => $id,
                'name' => $node['name'] ?? $id,
                'type' => $node['type'],
                'path' => $node['path'] ?? null,
                'layer' => $node['layer'] ?? null,
                'module' => $node['module'] ?? null,
                'summary' => $node['summary'] ?? null,
            ],
            'depends_on' => $out,
            'depended_on_by' => $in,
            'note' => 'Edges are what the source shows: constructor injection, resolved calls, imports, inheritance. Framework classes are not in the graph.',
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function walk(Graph $graph, string $id, string $direction, int $depth): array
    {
        $seen = [$id => true];
        $frontier = [$id];
        $found = [];

        // A class's dependencies are also its methods' dependencies, so the
        // walk starts by stepping through `contains` once. Without it,
        // asking about `CouponService` would show only what its constructor
        // takes and nothing any method calls.
        foreach ($graph->out($id, ['contains']) as $child) {
            $frontier[] = $child['id'];
            $seen[$child['id']] = true;
        }

        for ($level = 0; $level < max(1, $depth); $level++) {
            $next = [];

            foreach ($frontier as $current) {
                $edges = $direction === 'out'
                    ? $graph->out($current, Vocabulary::DEPENDENCY_EDGES)
                    : $graph->in($current, Vocabulary::DEPENDENCY_EDGES);

                foreach ($edges as $edge) {
                    $target = $graph->node($edge['id']);
                    if ($target === null) {
                        continue;
                    }

                    // Collapse a method onto its class: the reader wants the
                    // file, not the twelve methods in it.
                    $key = $target['type'] === 'method'
                        ? 'class:'.substr((string) $target['fqcn'], 0, (int) strrpos((string) $target['fqcn'], '::'))
                        : $edge['id'];

                    if ($key === $id) {
                        continue;
                    }

                    $owner = $graph->node($key) ?? $target;

                    $found[$key] ??= [
                        'id' => $key,
                        'name' => $owner['name'] ?? $key,
                        'type' => $owner['type'] ?? 'class',
                        'path' => $owner['path'] ?? null,
                        'layer' => $owner['layer'] ?? null,
                        'module' => $owner['module'] ?? null,
                        'via' => [],
                        'weight' => 0,
                        'depth' => $level + 1,
                    ];

                    $found[$key]['via'][$edge['type']] = true;
                    $found[$key]['weight'] += $edge['weight'];

                    if (! isset($seen[$edge['id']])) {
                        $seen[$edge['id']] = true;
                        $next[] = $edge['id'];
                    }
                }
            }

            $frontier = $next;
        }

        $found = array_values(array_map(static function (array $entry) {
            $entry['via'] = array_keys($entry['via']);

            return $entry;
        }, $found));

        usort($found, static fn ($a, $b) => [$a['depth'], -$b['weight']] <=> [$b['depth'], -$a['weight']]);

        return array_slice($found, 0, 40);
    }

    // ---------------------------------------------------------------- related

    /**
     * @return array<string,mixed>
     */
    public function related(string $target, int $limit = 12): array
    {
        $node = $this->resolve($target);

        if ($node === null) {
            $found = $this->engine()->search($target, 1);
            $first = $found['results'][0] ?? null;
            if ($first === null) {
                return ['match' => 'none', 'asked' => $target];
            }
            $node = $this->graph()->node($first['id']);
        }

        $graph = $this->graph();
        $id = $node['id'];
        $scored = [];

        $collect = function (array $edges, string $label, float $weight) use ($graph, &$scored, $id): void {
            foreach ($edges as $edge) {
                if ($edge['id'] === $id) {
                    continue;
                }
                $target = $graph->node($edge['id']);
                if ($target === null || ($target['path'] ?? null) === null) {
                    continue;
                }

                $path = $target['path'];
                $scored[$path] ??= ['path' => $path, 'type' => $target['type'], 'layer' => $target['layer'] ?? null, 'module' => $target['module'] ?? null, 'why' => [], 'score' => 0.0];
                $scored[$path]['why'][$label.':'.$edge['type']] = true;
                $scored[$path]['score'] += $weight * min(4, $edge['weight']);
            }
        };

        $frontier = [$id];
        foreach ($graph->out($id, ['contains']) as $child) {
            $frontier[] = $child['id'];
        }

        foreach ($frontier as $current) {
            $collect($graph->out($current, Vocabulary::RELATION_EDGES), 'uses', 1.0);
            $collect($graph->in($current, Vocabulary::RELATION_EDGES), 'used-by', 0.9);
            $collect($graph->out($current, ['co_changes']), 'co-changed', 0.55);
            $collect($graph->in($current, ['co_changes']), 'co-changed', 0.55);
        }

        $results = array_values(array_map(static function (array $entry) {
            $entry['why'] = array_slice(array_keys($entry['why']), 0, 4);

            return $entry;
        }, $scored));

        usort($results, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($results, 0, $limit);

        $best = $results[0]['score'] ?? 1.0;
        foreach ($results as $i => $result) {
            $results[$i]['relevance'] = $best > 0 ? round($result['score'] / $best, 2) : 0.0;
            unset($results[$i]['score']);
        }

        return [
            'subject' => ['id' => $id, 'name' => $node['name'] ?? $id, 'path' => $node['path'] ?? null, 'type' => $node['type']],
            'related' => $results,
        ];
    }

    // ----------------------------------------------------------- architecture

    /**
     * @return array<string,mixed>
     */
    public function architecture(?string $section = null): array
    {
        $overview = $this->load('architecture/overview.json', []);

        if ($section === null || $section === '' || $section === 'overview') {
            return $overview + ['manifest' => $this->manifest()];
        }

        return match ($section) {
            // Projected like every sibling section. The raw file is 47 KB —
            // twice the MCP cap — because it carries each community's full
            // member list; the caller of this section wants the shape, and
            // reaches for `get_module` when they want the members.
            'communities' => ['communities' => array_map(static fn (array $c) => array_filter([
                'slug' => $c['slug'],
                'title' => $c['title'],
                'source' => $c['source'],
                'evidence' => $c['evidence'],
                'modules' => $c['modules'],
                'inferred_modules' => $c['inferred_modules'] ?? [],
                'screens' => array_map(
                    static fn (array $s) => $s['key'],
                    array_slice($c['screens'] ?? [], 0, 20)
                ),
                'tables' => array_slice($c['tables'], 0, 20),
                'routes' => $c['route_count'],
                'nodes' => $c['node_count'],
                'cohesion' => $c['cohesion'],
            ], static fn ($value) => $value !== [] && $value !== null), $this->load('architecture/communities.json', []))],
            'modules' => ['modules' => array_map(static fn (array $m) => [
                'name' => $m['name'], 'community' => $m['community'], 'files' => $m['files'],
                'summary' => $m['summary'], 'models' => count($m['models']), 'routes' => count($m['routes']),
            ], $this->load('architecture/modules.json', []))],
            'database' => ['tables' => array_map(static fn (array $t) => [
                'name' => $t['name'], 'columns' => count($t['columns']), 'summary' => $t['summary'],
            ], $this->load('database/tables.json', []))],
            'routes' => $this->routeSummary(),
            'git' => $this->load('git/history.json', []),
            default => ['error' => "Unknown section [{$section}].", 'sections' => ['overview', 'communities', 'modules', 'database', 'routes', 'git']],
        };
    }

    /** @return array<string,mixed> */
    private function routeSummary(): array
    {
        $routes = $this->load('routes/routes.json', ['routes' => []]);
        $bySurface = [];

        foreach ($routes['routes'] as $route) {
            $bySurface[$route['surface']][] = $route['name'] ?? $route['uri'];
        }

        return [
            'source' => $routes['source'] ?? 'unknown',
            'count' => $routes['count'] ?? 0,
            'by_surface' => array_map(static fn (array $list) => count($list), $bySurface),
        ];
    }

    // --------------------------------------------------------------- internals

    /**
     * A name, a path, an FQCN, a node id or a route name — all resolve here.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $needle): ?array
    {
        $needle = trim($needle);
        $graph = $this->graph();

        foreach ([$needle, 'class:'.$needle, 'route:'.$needle, 'table:'.$needle, 'feature:'.$needle, 'file:'.$needle, 'view:'.$needle, 'command:'.$needle] as $candidate) {
            if ($graph->hasNode($candidate)) {
                return $graph->node($candidate);
            }
        }

        // By short name, preferring a class over a method of the same name.
        $matches = [];
        $lower = mb_strtolower($needle);

        foreach ($graph->nodes() as $id => $node) {
            if (mb_strtolower((string) ($node['name'] ?? '')) === $lower
                || mb_strtolower((string) ($node['fqcn'] ?? '')) === $lower
                || mb_strtolower((string) ($node['path'] ?? '')) === $lower) {
                $matches[] = $node;
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn ($a, $b) => self::rank($a['type']) <=> self::rank($b['type']));

        return $matches[0];
    }

    private static function rank(string $type): int
    {
        return match ($type) {
            'class' => 0, 'enum' => 1, 'trait' => 2, 'interface' => 3,
            'feature' => 4, 'module' => 5, 'route' => 6, 'table' => 7,
            'view' => 8, 'test' => 9, 'method' => 10, default => 20,
        };
    }

    public function graph(): Graph
    {
        if ($this->graph !== null) {
            return $this->graph;
        }

        $graph = new Graph;

        foreach ($this->load('graph/nodes.json', []) as $id => $node) {
            $type = $node['type'] ?? 'file';
            unset($node['type']);
            $graph->addNode((string) $id, $type, $node);
        }

        foreach ($this->load('graph/edges.json', []) as $edge) {
            $graph->addEdge($edge['from'], $edge['to'], $edge['type'], $edge['meta'] ?? []);
        }

        return $this->graph = $graph;
    }

    public function engine(): SearchEngine
    {
        return $this->engine ??= new SearchEngine(
            $this->load('index/lexical.json', ['docs' => [], 'df' => [], 'postings' => [], 'total' => 0, 'avgdl' => 1, 'symbols' => [], 'paths' => []]),
            $this->paths->config()['synonyms'],
        );
    }

    /** @return list<array<string,mixed>> */
    public function features(): array
    {
        return $this->load('architecture/features.json', []);
    }

    public function paths(): Paths
    {
        return $this->paths;
    }

    /** @return mixed */
    private function load(string $file, mixed $default = null): mixed
    {
        return $this->loaded[$file] ??= Json::read($this->paths->data($file), $default);
    }
}
