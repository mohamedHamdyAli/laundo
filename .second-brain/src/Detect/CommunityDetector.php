<?php

namespace Laundo\SecondBrain\Detect;

use Laundo\SecondBrain\Graph\Graph;
use Laundo\SecondBrain\Support\Text;

/**
 * Communities — the eight or so neighbourhoods a request belongs to.
 *
 * **The primary source is `config/menu.php`, not a clustering algorithm.** That
 * file already groups every panel screen into named dropdowns, and its own
 * header explains the two rules behind the arrangement ("the list reads in the
 * order the platform is built", "things that depend on each other share a
 * list"). Somebody who knows the business wrote it. Running modularity
 * detection over the call graph and publishing whatever fell out would be
 * replacing that with a guess — and the guess would be worse, because
 * `DriverEarning` is a `Payment` model and `PaymentLedgerController` serves two
 * different screens, so a purely structural clustering puts driver money in the
 * payment bucket and stops there.
 *
 * What the graph *is* used for is the second half: the surfaces the sidebar
 * does not describe (the API, the landing page, authentication, the delivery
 * pipeline's own services) become communities derived from the directory tree,
 * and every community gets a measured cohesion so a reader can see which ones
 * are real neighbourhoods and which are filing cabinets.
 */
final class CommunityDetector
{
    /**
     * Surfaces with no sidebar entry. Each names the modules it owns, and the
     * `evidence` says it came from the directory layout — a weaker claim than
     * the menu ones, and labelled as such.
     */
    private const DERIVED = [
        'api' => [
            'title' => 'Mobile API (customer + driver apps)',
            'modules' => ['Api'],
            'why' => 'app/Http/Controllers/Api/V1 + routes/api.php',
        ],
        'authentication' => [
            'title' => 'Authentication, OTP and sessions',
            'modules' => ['Auth'],
            'why' => 'app/Services/Auth + app/Http/Controllers/Auth',
        ],
        'notifications' => [
            'title' => 'Notifications, push and the queue',
            'modules' => ['Notification'],
            'why' => 'app/Modules/Notification + app/Jobs + app/Services/Push',
        ],
        'public_site' => [
            'title' => 'Public marketing site',
            'modules' => ['Landing'],
            'why' => 'app/Services/Landing + resources/views/landing',
        ],
        'routing_and_distance' => [
            'title' => 'Road distance and routing drivers',
            'modules' => ['Routing'],
            'why' => 'app/Services/Routing',
        ],
        'platform' => [
            'title' => 'Framework plumbing, helpers and traits',
            'modules' => ['Platform', 'AdminShell'],
            'why' => 'app/Helpers, app/Support, app/Trait, app/Http/Middleware',
        ],
        'database' => [
            'title' => 'Schema and seed data',
            'modules' => ['Database'],
            'why' => 'database/migrations + database/seeders',
        ],
        'quality' => [
            'title' => 'Test suites and written documentation',
            'modules' => ['Tests', 'Docs'],
            'why' => 'tests/ + docs/',
        ],
    ];

    /**
     * @param  array<string,mixed>  $menu
     * @param  array{models:list<string>,slugs:array<string,list<string>>}  $dashboard
     * @return list<array<string,mixed>>
     */
    public function detect(Graph $graph, array $menu, array $dashboard): array
    {
        $keyToModule = $this->menuKeyToModule($dashboard, $graph);
        $communities = [];

        // 1. The sidebar's own dropdowns.
        foreach ($menu['groups'] ?? [] as $slug => $group) {
            $keys = array_keys($group['items'] ?? []);

            $communities[] = $this->assemble(
                $graph,
                $slug,
                $group['title'] ?? Text::humanise($slug),
                $keys,
                $keyToModule,
                $menu,
                'config/menu.php → groups.'.$slug,
                'menu',
                $group['order'] ?? 50,
            );
        }

        // 2. The sidebar's top-level singles. `order` is the largest domain in
        //    the codebase and is a single precisely because it is not a subset
        //    of anything else — so it is its own community, not an orphan.
        foreach ($menu['singles'] ?? [] as $key => $order) {
            $communities[] = $this->assemble(
                $graph,
                $key,
                $menu['titles'][$key] ?? Text::humanise($key),
                [$key],
                $keyToModule,
                $menu,
                'config/menu.php → singles.'.$key,
                'menu',
                $order,
            );
        }

        // 3. The surfaces the sidebar never mentions.
        $claimed = [];
        foreach ($communities as $community) {
            $claimed = array_merge($claimed, $community['modules']);
        }

        foreach (self::DERIVED as $slug => $derived) {
            $modules = array_values(array_diff($derived['modules'], $claimed));
            if ($modules === []) {
                continue;
            }

            $communities[] = $this->assembleFromModules(
                $graph,
                $slug,
                $derived['title'],
                $modules,
                'directory layout: '.$derived['why'],
                'derived',
                90,
            );
        }

        // 4. Modules nothing has claimed yet, attached to whichever community
        //    their code actually talks to. Kept in a separate list so the
        //    distinction survives: `modules` is what the menu and the directory
        //    layout say, `inferred_modules` is what the call graph suggests.
        $placed = [];
        foreach ($communities as $community) {
            $placed = array_merge($placed, $community['modules']);
        }

        $unplaced = array_values(array_diff(
            array_map(static fn (array $n) => $n['name'], $graph->ofType('module')),
            $placed
        ));

        foreach ($unplaced as $module) {
            $best = $this->closestCommunity($graph, $module, $communities);
            if ($best === null) {
                continue;
            }

            $communities[$best]['inferred_modules'][] = $module;
            $communities[$best]['modules'][] = $module;
            $placed[] = $module;
        }

        $orphans = array_values(array_diff($unplaced, $placed));

        if ($orphans !== []) {
            $communities[] = $this->assembleFromModules(
                $graph,
                'unplaced',
                'Modules no community claimed',
                $orphans,
                'neither config/menu.php nor the directory rules named these',
                'orphan',
                99,
            );
        }

        usort($communities, static fn ($a, $b) => [$a['order'], $a['slug']] <=> [$b['order'], $b['slug']]);

        return $communities;
    }

    /**
     * One primary community per module, by weight rather than by whoever
     * claimed it first.
     *
     * First-come was wrong in exactly the places that matter. `delivery` lists
     * `order_task` and `driver_earning`, so walking the menu in order gave
     * `delivery` both the Order module and the Payment module — putting the
     * order lifecycle under Delivery and the whole settlement engine with it,
     * while `money` and the `order` single, which between them name six of
     * those screens, got nothing.
     *
     * The weight is how many of a community's screens a module actually backs,
     * and a module whose name *is* the community's key takes it outright: the
     * `Order` module is the `order` community whatever else lists one of its
     * screens.
     *
     * @param  list<array<string,mixed>>  $communities
     * @return array<string,string>  module name => community slug
     */
    public static function primaryCommunities(array $communities): array
    {
        $scores = [];

        foreach ($communities as $index => $community) {
            $screens = $community['screens'] ?? [];

            foreach ($community['modules'] as $module) {
                $weight = 0;

                foreach ($screens as $screen) {
                    if (in_array($module, $screen['modules'] ?? [], true)) {
                        $weight++;
                    }
                }

                // A derived or inferred community has no screens to count.
                $weight = max($weight, 1);

                if (Text::snake($module) === $community['slug']) {
                    $weight += 1000;
                }

                if (in_array($module, $community['inferred_modules'] ?? [], true)) {
                    $weight = 0;    // never beats a declared claim
                }

                $current = $scores[$module] ?? null;

                if ($current === null
                    || $weight > $current['weight']
                    || ($weight === $current['weight'] && $community['order'] < $current['order'])) {
                    $scores[$module] = [
                        'slug' => $community['slug'],
                        'weight' => $weight,
                        'order' => $community['order'],
                        'index' => $index,
                    ];
                }
            }
        }

        return array_map(static fn (array $entry) => $entry['slug'], $scores);
    }

    /**
     * A menu key is a snake_case model basename. `driver_earning` →
     * `App\Modules\Payment\Models\DriverEarning` → the `Payment` module — which
     * is exactly the mapping CLAUDE.md warns has to be looked up rather than
     * assumed ("a sidebar screen is not a module directory").
     *
     * A screen has **two** modules worth recording and they are often different:
     * where its model lives and where its controller lives. `order_rating`'s
     * model is `app/Modules/Order/Models/OrderRating.php` while its screen is
     * driven by `app/Modules/Rating/Controllers/RatingController.php`. Taking
     * only the model left the Rating, Recurrence and Roles directories in no
     * community at all.
     *
     * @param  array{models:list<string>,slugs:array<string,list<string>>}  $dashboard
     * @return array<string,list<string>>
     */
    private function menuKeyToModule(array $dashboard, Graph $graph): array
    {
        $map = [];

        foreach ($dashboard['models'] as $fqcn) {
            $separator = strrpos($fqcn, '\\');
            $basename = $separator === false ? $fqcn : substr($fqcn, $separator + 1);
            $key = Text::snake($basename);

            $node = $graph->node('class:'.$fqcn);
            $map[$key][] = $node['module'] ?? 'Platform';
        }

        // The controller behind each `admin.{key}.*` route.
        foreach ($graph->ofType('route') as $route) {
            if (preg_match('/^admin\.([a-z0-9_]+)\./', (string) ($route['name'] ?? ''), $matches) !== 1) {
                continue;
            }
            if (($route['module'] ?? null) !== null) {
                $map[$matches[1]][] = $route['module'];
            }
        }

        foreach ($map as $key => $modules) {
            $map[$key] = array_values(array_unique($modules));
        }

        return $map;
    }

    /**
     * The community a module's code actually talks to most.
     *
     * Used only for modules the menu never mentions — `Address` has no panel
     * screen at all, it exists because the apps need one. Counting cross-module
     * edges is weaker evidence than a line in `config/menu.php`, which is why
     * the result is recorded under `inferred_modules` rather than merged in
     * silently.
     *
     * @param  list<array<string,mixed>>  $communities
     */
    private function closestCommunity(Graph $graph, string $module, array $communities): ?int
    {
        $memberOf = [];
        foreach ($communities as $index => $community) {
            foreach ($community['modules'] as $name) {
                $memberOf[$name] = $index;
            }
        }

        $votes = [];

        foreach ($graph->edges() as $edge) {
            $from = $graph->node($edge['from']);
            $to = $graph->node($edge['to']);
            if ($from === null || $to === null) {
                continue;
            }

            $a = $from['module'] ?? null;
            $b = $to['module'] ?? null;

            if ($a === $module && $b !== null && isset($memberOf[$b])) {
                $votes[$memberOf[$b]] = ($votes[$memberOf[$b]] ?? 0) + $edge['weight'];
            } elseif ($b === $module && $a !== null && isset($memberOf[$a])) {
                $votes[$memberOf[$a]] = ($votes[$memberOf[$a]] ?? 0) + $edge['weight'];
            }
        }

        if ($votes === []) {
            return null;
        }

        arsort($votes);

        return (int) array_key_first($votes);
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string,string>  $keyToModule
     * @param  array<string,mixed>  $menu
     * @return array<string,mixed>
     */
    private function assemble(
        Graph $graph,
        string $slug,
        string $title,
        array $keys,
        array $keyToModule,
        array $menu,
        string $evidence,
        string $source,
        int $order,
    ): array {
        $modules = [];
        $screens = [];

        foreach ($keys as $key) {
            $owners = $keyToModule[$key] ?? [];

            $screens[] = [
                'key' => $key,
                'title' => $menu['titles'][$key] ?? Text::humanise($key),
                'route' => $menu['routes'][$key] ?? null,
                'modules' => $owners,
            ];

            foreach ($owners as $owner) {
                $modules[] = $owner;
            }
        }

        $community = $this->assembleFromModules(
            $graph,
            $slug,
            $title,
            array_values(array_unique($modules)),
            $evidence,
            $source,
            $order,
        );

        $community['screens'] = $screens;
        $community['menu_keys'] = $keys;

        return $community;
    }

    /**
     * @param  list<string>  $modules
     * @return array<string,mixed>
     */
    private function assembleFromModules(
        Graph $graph,
        string $slug,
        string $title,
        array $modules,
        string $evidence,
        string $source,
        int $order,
    ): array {
        $members = [];
        $tables = [];
        $routes = [];
        $models = [];

        foreach ($graph->nodes() as $id => $node) {
            if (! in_array($node['module'] ?? null, $modules, true)) {
                continue;
            }

            $members[] = $id;

            if ($node['type'] === 'route') {
                $routes[] = $node['name'] ?? $node['uri'];
            }
            if (($node['is_model'] ?? false) === true) {
                $models[] = $node['fqcn'];
                if (($node['table'] ?? null) !== null) {
                    $tables[] = $node['table'];
                }
            }
        }

        return [
            'slug' => $slug,
            'title' => $title,
            'source' => $source,
            'evidence' => $evidence,
            'order' => $order,
            'modules' => $modules,
            'models' => array_values(array_unique($models)),
            'tables' => array_values(array_unique($tables)),
            'route_count' => count($routes),
            'routes' => array_slice(array_values(array_unique($routes)), 0, 40),
            'node_count' => count($members),
            'cohesion' => $this->cohesion($graph, $members),
        ];
    }

    /**
     * Internal edges over total edges touching the community.
     *
     * A number, not a verdict. A high score means the neighbourhood mostly
     * talks to itself; `money` scoring low is not a fault — settlement reaches
     * into orders, laundries and drivers by design — but it does tell a reader
     * that a change here will not stay local.
     *
     * @param  list<string>  $members
     */
    private function cohesion(Graph $graph, array $members): float
    {
        if ($members === []) {
            return 0.0;
        }

        $inside = array_flip($members);
        $internal = 0;
        $external = 0;

        foreach ($graph->edges() as $edge) {
            $from = isset($inside[$edge['from']]);
            $to = isset($inside[$edge['to']]);

            if ($from && $to) {
                $internal++;
            } elseif ($from || $to) {
                $external++;
            }
        }

        $total = $internal + $external;

        return $total === 0 ? 0.0 : round($internal / $total, 3);
    }
}
