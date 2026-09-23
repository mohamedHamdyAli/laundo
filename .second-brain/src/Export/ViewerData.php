<?php

namespace Laundo\SecondBrain\Export;

use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Support\Json;

/**
 * A read-only projection of the built graph, shaped for a browser.
 *
 * **This is an adapter, not a second graph.** It computes nothing: every
 * community, module, layer and edge type here was decided by the indexer and
 * is copied across. A node's community, in particular, is looked up through
 * `modules.json`, which already carries the community `ModuleDetector` assigned
 * it — the viewer must never re-derive that, or the picture and the answers
 * would start disagreeing about the same codebase.
 *
 * What it does do is make the payload small enough to be worth loading. The
 * built graph is 5.2 MB of JSON, most of it fields a picture has no use for —
 * method lists, fully-qualified names, column detail, per-migration history. A
 * projection to short keys, interned strings and integer edge endpoints brings
 * it to roughly a tenth of that.
 *
 * Emitted twice, on purpose:
 *
 *  - `data/export/viewer-graph.json` — the canonical artifact, for anything
 *    that wants the data.
 *  - `viewer/data/graph.js` — the same payload as a `window` assignment,
 *    because `fetch()` is blocked under `file://` and the viewer has to open
 *    by double-clicking the HTML without anybody starting a server first.
 */
final class ViewerData
{
    /** Summaries are for a tooltip, not for reading. */
    private const SUMMARY_LIMIT = 160;

    public function __construct(private readonly Brain $brain) {}

    /**
     * @return array{json:string,js:string,nodes:int,edges:int,bytes:int}
     */
    public function write(): array
    {
        $payload = $this->build();

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $json = $json === false ? '{}' : $json;

        $paths = $this->brain->paths();

        $jsonPath = $paths->data('export/viewer-graph.json');
        $this->put($jsonPath, $json."\n");

        // The `window` wrapper. A plain `<script src>` is the only way to get
        // several megabytes of data into a page opened from the filesystem.
        $jsPath = $paths->brain('viewer/data/graph.js');
        $this->put($jsPath, "window.__SECOND_BRAIN__ = ".$json.";\n");

        return [
            'json' => $paths->relative($jsonPath),
            'js' => $paths->relative($jsPath),
            'nodes' => count($payload['nodes']),
            'edges' => count($payload['edges']),
            'bytes' => strlen($json),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $graph = $this->brain->graph();
        $manifest = $this->brain->manifest();

        // module => community, exactly as the indexer decided it. Not recomputed.
        $moduleCommunity = [];
        foreach ($this->brain->architecture('modules')['modules'] ?? [] as $module) {
            $moduleCommunity[$module['name']] = $module['community'];
        }

        $communityMeta = [];
        foreach (Json::read($this->brain->paths()->data('architecture/communities.json'), []) as $community) {
            $communityMeta[$community['slug']] = [
                'slug' => $community['slug'],
                'title' => $community['title'],
                'source' => $community['source'],
                'cohesion' => $community['cohesion'],
            ];
        }

        // ---- nodes, interned -------------------------------------------------

        $types = [];
        $layers = [];
        $modules = [];
        $communities = [];

        $intern = static function (string $value, array &$table): int {
            $key = array_search($value, $table, true);
            if ($key === false) {
                $table[] = $value;
                $key = count($table) - 1;
            }

            return (int) $key;
        };

        $index = [];
        $nodes = [];

        foreach ($graph->nodes() as $id => $node) {
            // A module node carries no `module` attribute — it *is* one — so it
            // is keyed by its own name. Without this the 42 module nodes were
            // the only things on the canvas with no colour and no community
            // checkbox to filter them by.
            $module = $node['type'] === 'module'
                ? (string) ($node['name'] ?? '')
                : (string) ($node['module'] ?? '');

            $community = (string) ($moduleCommunity[$module] ?? '');

            $index[$id] = count($nodes);

            $nodes[] = [
                'id' => $id,
                'n' => (string) ($node['name'] ?? $id),
                't' => $intern((string) $node['type'], $types),
                'l' => $intern((string) ($node['layer'] ?? ''), $layers),
                'm' => $intern($module, $modules),
                'c' => $intern($community, $communities),
                'p' => $node['path'] ?? null,
                's' => $this->shorten((string) ($node['summary'] ?? '')),
                // Extra identifiers the search box should reach: a route's URI,
                // a model's table, a class's FQCN.
                'k' => $this->searchExtras($node),
                'd' => 0,   // degree, filled below
            ];
        }

        // ---- edges as integer pairs -----------------------------------------

        $edgeTypes = [];
        $edges = [];

        foreach ($graph->edges() as $edge) {
            $from = $index[$edge['from']] ?? null;
            $to = $index[$edge['to']] ?? null;

            if ($from === null || $to === null) {
                continue;   // pruned; should not happen on a healthy graph
            }

            $edges[] = [$from, $to, $intern((string) $edge['type'], $edgeTypes)];

            $nodes[$from]['d']++;
            $nodes[$to]['d']++;
        }

        // ---- counts the sidebar shows ---------------------------------------

        $communityCounts = array_fill(0, max(1, count($communities)), 0);
        foreach ($nodes as $node) {
            $communityCounts[$node['c']]++;
        }

        $typeCounts = array_fill(0, max(1, count($types)), 0);
        foreach ($nodes as $node) {
            $typeCounts[$node['t']]++;
        }

        return [
            'generated_at' => date('c'),
            'built_at' => $manifest['built_at'] ?? null,
            'repository' => basename($this->brain->paths()->root()),
            'root' => $this->brain->paths()->root(),
            'tables' => [
                'type' => $types,
                'layer' => $layers,
                'module' => $modules,
                'community' => $communities,
                'edge' => $edgeTypes,
            ],
            'counts' => [
                'community' => $communityCounts,
                'type' => $typeCounts,
            ],
            'communities' => $communityMeta,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * The identifiers a search should reach beyond the display name — a route's
     * URI, a model's table, a class's fully-qualified name.
     *
     * @param  array<string,mixed>  $node
     */
    private function searchExtras(array $node): string
    {
        $parts = array_filter([
            $node['fqcn'] ?? null,
            $node['uri'] ?? null,
            $node['table'] ?? null,
            $node['permission'] ?? null,
        ]);

        return $parts === [] ? '' : implode(' ', array_map('strval', $parts));
    }

    private function shorten(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > self::SUMMARY_LIMIT
            ? rtrim(mb_substr($text, 0, self::SUMMARY_LIMIT - 1)).'…'
            : $text;
    }

    private function put(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }
}
