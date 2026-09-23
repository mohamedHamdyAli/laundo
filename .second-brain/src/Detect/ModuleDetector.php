<?php

namespace Laundo\SecondBrain\Detect;

use Laundo\SecondBrain\Graph\Graph;

/**
 * What one module contains and what it touches.
 *
 * The module *set* is not detected — this project declares it, one directory
 * per module under `app/Modules`, and a detector that re-derived it by
 * clustering would sometimes disagree with the directory the code is in. What
 * is detected is the shape: which layers a module actually has (several have no
 * repository and two have no model), which tables it owns, which it only reads,
 * and which other modules it cannot work without.
 *
 * The "owns / reads" split matters more than it looks. A module owning a table
 * is where a migration for it belongs; a module merely reading one is where a
 * change to that table will break something quietly.
 */
final class ModuleDetector
{
    private ?string $root = null;

    /**
     * The module's directory, **checked against the filesystem**.
     *
     * The previous version pattern-matched the name against an exclusion list,
     * so `Language` and `Roles` — pseudo-modules named after a view directory
     * and a controller, not after a module — were published as
     * `app/Modules/Language` and `app/Modules/Roles`. Neither exists, and a map
     * that sends somebody to a directory that is not there is worse than one
     * that says nothing.
     */
    private function moduleDirectory(string $name): ?string
    {
        $relative = 'app/Modules/'.$name;

        if ($this->root === null) {
            return null;
        }

        return is_dir(rtrim($this->root, '/').'/'.$relative) ? $relative : null;
    }

    /**
     * @param  list<array<string,mixed>>  $communities
     * @return list<array<string,mixed>>
     */
    public function detect(Graph $graph, array $communities, ?string $root = null): array
    {
        // Weighted, never first-come — see CommunityDetector::primaryCommunities().
        $moduleToCommunity = CommunityDetector::primaryCommunities($communities);
        $this->root = $root;

        $modules = [];

        foreach ($graph->ofType('module') as $node) {
            $name = $node['name'];
            $modules[$name] = [
                'name' => $name,
                'community' => $moduleToCommunity[$name] ?? null,
                'path' => $this->moduleDirectory($name),
                'layers' => [],
                'files' => 0,
                'lines' => 0,
                'classes' => [],
                'models' => [],
                'owns_tables' => [],
                'reads_tables' => [],
                'routes' => [],
                'permissions' => [],
                'tests' => [],
                'summary' => '',
                'depends_on' => [],
                'depended_on_by' => [],
            ];
        }

        foreach ($graph->nodes() as $id => $node) {
            $module = $node['module'] ?? null;
            if ($module === null || ! isset($modules[$module])) {
                continue;
            }

            switch ($node['type']) {
                case 'file':
                    $modules[$module]['files']++;
                    $modules[$module]['lines'] += $node['lines'] ?? 0;
                    $layer = $node['layer'] ?? 'other';
                    $modules[$module]['layers'][$layer] = ($modules[$module]['layers'][$layer] ?? 0) + 1;
                    break;

                case 'class':
                case 'enum':
                case 'trait':
                case 'interface':
                    $modules[$module]['classes'][] = [
                        'fqcn' => $node['fqcn'],
                        'layer' => $node['layer'] ?? 'other',
                        'path' => $node['path'] ?? null,
                        'summary' => $node['summary'] ?? '',
                    ];
                    if (($node['is_model'] ?? false) === true) {
                        $modules[$module]['models'][] = $node['fqcn'];
                        if (($node['table'] ?? null) !== null) {
                            $modules[$module]['owns_tables'][] = $node['table'];
                        }
                    }
                    break;

                case 'route':
                    $modules[$module]['routes'][] = $node['name'] ?? $node['uri'];
                    if (($node['permission'] ?? null) !== null) {
                        $modules[$module]['permissions'][] = $node['permission'];
                    }
                    break;

                case 'test':
                    $modules[$module]['tests'][] = $node['path'];
                    break;
            }
        }

        // Cross-module dependencies, taken from class-level edges only. A
        // method-level count would be dominated by whichever service has the
        // most calls rather than by what the module needs.
        foreach ($graph->edges() as $edge) {
            if (! in_array($edge['type'], ['depends_on', 'imports', 'extends', 'uses_trait'], true)) {
                continue;
            }

            $from = $graph->node($edge['from']);
            $to = $graph->node($edge['to']);

            if ($from === null || $to === null) {
                continue;
            }

            $a = $from['module'] ?? null;
            $b = $to['module'] ?? null;

            if ($a === null || $b === null || $a === $b || ! isset($modules[$a], $modules[$b])) {
                continue;
            }

            $modules[$a]['depends_on'][$b] = ($modules[$a]['depends_on'][$b] ?? 0) + 1;
            $modules[$b]['depended_on_by'][$a] = ($modules[$b]['depended_on_by'][$a] ?? 0) + 1;

            // A table this module reads but does not own: it depends on a
            // model that belongs to another module, and that model maps to a
            // table.
            //
            // This used to test `$to['type'] === 'table'` right here, which can
            // never be true — the loop is already filtered to class-level edge
            // types and none of them targets a table. Every module reported an
            // empty `reads_tables`, so the documented "owns / reads" split
            // never once fired.
            if (($to['is_model'] ?? false) === true && ($to['table'] ?? null) !== null) {
                $modules[$a]['reads_tables'][] = $to['table'];
            }
        }

        // Tables reached but not owned.
        foreach ($graph->edges() as $edge) {
            if ($edge['type'] !== 'maps_to') {
                continue;
            }
            $from = $graph->node($edge['from']);
            $to = $graph->node($edge['to']);
            if ($from === null || $to === null) {
                continue;
            }
            $module = $from['module'] ?? null;
            if ($module !== null && isset($modules[$module])) {
                $modules[$module]['owns_tables'][] = $to['name'];
            }
        }

        foreach ($modules as $name => $module) {
            $modules[$name]['owns_tables'] = array_values(array_unique($module['owns_tables']));
            $modules[$name]['reads_tables'] = array_values(array_diff(
                array_unique($module['reads_tables']),
                $modules[$name]['owns_tables']
            ));
            $modules[$name]['routes'] = array_values(array_unique($module['routes']));
            $modules[$name]['permissions'] = array_values(array_unique($module['permissions']));
            $modules[$name]['models'] = array_values(array_unique($module['models']));
            $modules[$name]['tests'] = array_values(array_unique($module['tests']));

            arsort($modules[$name]['depends_on']);
            arsort($modules[$name]['depended_on_by']);
            arsort($modules[$name]['layers']);

            $modules[$name]['summary'] = $this->summaryFor($module);

            usort($modules[$name]['classes'], static fn ($a, $b) => [
                self::layerRank($a['layer']), $a['fqcn'],
            ] <=> [self::layerRank($b['layer']), $b['fqcn']]);
        }

        ksort($modules);

        return array_values($modules);
    }

    /**
     * A module's one-line description, borrowed from the best docblock it has.
     *
     * Preference order is the layer contract read downwards: a service knows
     * why the module exists, a model knows what it is, a controller knows only
     * how it is reached. Nothing is written if none of them says anything —
     * an invented summary would be the one part of this file a reader could
     * not check.
     *
     * @param  array<string,mixed>  $module
     */
    private function summaryFor(array $module): string
    {
        foreach (['service', 'model', 'controller', 'api-controller', 'enum'] as $layer) {
            foreach ($module['classes'] as $class) {
                if ($class['layer'] === $layer && trim($class['summary']) !== '') {
                    return $class['summary'];
                }
            }
        }

        return '';
    }

    private static function layerRank(string $layer): int
    {
        return match ($layer) {
            'controller', 'api-controller' => 1,
            'service' => 2,
            'repository' => 3,
            'model' => 4,
            'request' => 5,
            'enum' => 6,
            default => 9,
        };
    }
}
