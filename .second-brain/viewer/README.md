# The graph explorer

An interactive picture of the Second Brain graph: 5,580 nodes and 20,052 edges
on a canvas, coloured by community, filterable, searchable, and clickable
through to the file each node came from.

**It is a view, not a source.** Every community, module, layer, feature and
relationship it draws was decided by the PHP indexer and arrives pre-computed.
Nothing in the JavaScript ranks, scores, detects a feature or infers an edge —
if the picture and `brain.php search` ever disagree, the picture is wrong and
the fix is to rebuild it.

---

## Open it

```bash
php .second-brain/bin/brain.php visualize
```

Then open `.second-brain/viewer/index.html` in a browser — by double-clicking
it. **No server is required.**

That is why the payload ships as `data/graph.js` (a `window` assignment loaded
by a `<script>` tag) rather than as JSON fetched at runtime: browsers block
`fetch()` under the `file://` protocol, so a viewer built the obvious way opens
to an empty screen unless somebody remembers to start a web server first.

`php .second-brain/bin/brain.php export` regenerates it too, so anybody who
exports gets a current picture rather than a stale one.

---

## Data source

| File | What it is |
| --- | --- |
| `.second-brain/data/graph/nodes.json` | every node, as built |
| `.second-brain/data/graph/edges.json` | every edge, as built |
| `.second-brain/data/architecture/modules.json` | **module → community**, already assigned by `ModuleDetector` |
| `.second-brain/data/architecture/communities.json` | community titles, source and cohesion |

`src/Export/ViewerData.php` is the whole adapter. It projects those four files
into one payload and computes nothing beyond node degree and a couple of
counts:

- **Community per node** is a lookup through `modules.json`, never a
  re-derivation. A module node is keyed by its own name, because it *is* a
  module and carries no `module` attribute.
- **Strings are interned.** Types, layers, modules, communities and edge types
  become small tables of unique strings, and every node and edge refers to them
  by integer.
- **Edges become integer pairs** `[fromIndex, toIndex, typeIndex]`.

That takes the 5.2 MB the graph occupies on disk down to **1.5 MB**, which is
what makes it reasonable to load at all.

Two artifacts are written, with the same content:

- `.second-brain/data/export/viewer-graph.json` — canonical, for any consumer
- `.second-brain/viewer/data/graph.js` — the `file://`-loadable wrapper

Both are generated. Neither is edited by hand.

---

## Reading the picture

- **Colour is community** — one hue each, seventeen of them.
- **Shape is layer** — service is a diamond, model a hexagon, controller a
  square, repository a triangle, route a pill, view a rounded square, migration
  a cross, test a ring, feature a star. Colour and shape answer two different
  questions ("where does this belong", "what kind of thing is it") and so are
  kept on two different channels.
- **Size is degree** — how many relationships the node has, square-rooted so one
  very connected node cannot swallow the canvas.
- **Position is community** — each community has a fixed anchor on a wide
  circle and its nodes are pulled toward it. Without that force the layout is a
  uniform cloud: the graph is mostly `contains` edges, which form a forest, and
  nothing would express the grouping the indexer worked out.

## Controls

| | |
| --- | --- |
| drag background | pan |
| scroll | zoom about the cursor |
| drag a node | move it (it is released when you let go) |
| click | select — highlights its edges, dims everything else |
| double-click | focus: select and zoom to it |
| hover | tooltip with name, type, module, path |
| search | type two characters or more; Enter takes the first hit |
| click a relationship in the sidebar | jumps to that node |
| click the file path | copies it |

## Search

Matches, in this order of preference: exact name, name prefix, name substring,
**file path**, then the extra identifiers the adapter carries over — fully
qualified class name, route URI, database table, permission slug — and finally
the module name.

So all of these find something: `SettlementService` · `order_settlements` ·
`admin.commission_rule.index` · `LaundryAssigner.php` · `Payment` ·
`/api/v1/orders`.

This ordering arranges a dropdown list. **It is not the brain's ranking** and
does not try to be — for "where should I go to do this task", use
`brain.php search`, which has BM25, the domain glossary, intent routing and
`then_read` behind it.

## Community filtering

Every community has a checkbox with its node count, plus **All** and **None**.
Unchecking one hides its nodes *and* every edge with an endpoint in it, so what
remains is the sub-graph that survives on its own — filtering to Money alone
takes 20,052 edges down to 994, and Money plus Delivery to 1,832.

There is a second set of checkboxes for **node type**. It is there because
`method` nodes are 2,604 of the 5,580 — nearly half — and turning them off is
usually the first thing you want when looking at architecture rather than at
call structure.

Both filters reheat the layout, so clusters re-settle into the space freed up.

---

## Performance, measured

Rendering is Canvas 2D with a single element: no DOM node per graph node, which
is the thing that makes 5,000-node graphs unusable.

The layout is a force simulation with **grid-bucketed repulsion**. A naive
force-directed layout compares every pair of nodes, which here is 31 million
comparisons per tick and stops the tab dead. Nodes are bucketed into a uniform
spatial grid and compared only with neighbours in adjacent cells.

Measured in headless Chromium at 5,580 nodes / 20,052 edges:

| | |
| --- | --- |
| synchronous pan-handler cost | **0.01 ms** per event |
| hover hit-test across all nodes | **0.17 ms** |
| headless `requestAnimationFrame` cadence | 39.8 ms (~25 fps) |

The frame rate measured in that environment is the **harness's** ceiling, not
the viewer's: headless Chromium software-rasterises and fires rAF at ~25 fps
whether the page draws anything or not. A GPU-backed browser will be faster.
The honest statement is that the viewer's own work per frame is well inside the
budget, not that it renders at any particular frame rate.

### Limitations

- The first paint runs up to 600 layout ticks behind a progress overlay. On a
  slow machine that is a second or two.
- The payload is 1.5 MB. It parses quickly but is not instant on a cold cache.
- Labels are hidden when zoomed out — below about 55% only high-degree nodes are
  named, and when a node is selected only it and its neighbours are. Drawing
  5,580 labels at once is illegible as well as slow.
- Hit-testing is a linear scan. At 0.17 ms it is not worth a quadtree; if the
  graph grew tenfold it would be.
- The layout is not deterministic between runs — initial placement uses
  `Math.random()` for jitter. The *clusters* are stable, because the anchors
  are; the arrangement within a cluster is not.

---

## It is additive

Nothing outside `.second-brain/viewer/` and `src/Export/ViewerData.php` changed
behaviour. The indexer, search, ranking, feature detection, graph semantics and
all six MCP tools are untouched, and the frozen benchmarks were re-run to prove
it.
