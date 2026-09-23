# The Second Brain

A queryable map of this codebase, so that "where does this go?" is answered
before anybody reads three hundred files looking for it.

It is **infrastructure, not application code**. Nothing under `app/`,
`routes/`, `database/` or `resources/` was changed to make it work, except two
new artisan commands that are thin wrappers over the CLI. The application runs
exactly as it did.

---

## What it is for

The brain exists to answer one question cheaply:

> Where exactly should I go to do this task?

It returns **file paths, relationships and one-line summaries — never source
code.** You then open the two or three files it named. That is the whole idea:
*find first, read second*.

Measured on this repository across six real tasks (`brain.php bench`), that is
**~230,000 estimated tokens of exploration reduced to ~45,000 — about 80%** —
with the expected answer in the top three results for five of the six. The
sixth is honest: the question used no vocabulary the codebase contains. See
[Benchmark](#benchmark).

---

## Quick start

```bash
php .second-brain/bin/brain.php index         # build it (2s cold, safe to repeat)
php .second-brain/bin/brain.php search "where is the delivery fee calculated"
php .second-brain/bin/brain.php feature "Discount Codes"
php .second-brain/bin/brain.php module Payment
php .second-brain/bin/brain.php deps SettlementService
php .second-brain/bin/brain.php doctor
```

Claude Code picks the brain up automatically through `.mcp.json` in the
repository root — no setup beyond building it once.

---

## The architecture it was built around

The brain was designed *after* reading this repository, not from a template.
What it found, and what it therefore models:

| Finding | Consequence for the brain |
| --- | --- |
| Hand-rolled modules at `app/Modules/{Name}/`, no modular package | modules are read from the directory tree, not from a package manifest |
| Three surfaces over one codebase — `/admin` panel, `/api/v1`, landing page | every route carries a `surface`, and search can be filtered by it |
| Layer contract: Controller → Service → Repository → Model | the `depends_on` edge follows constructor injection, which *is* that contract |
| `config/menu.php` already groups every screen into eight named domains | **communities come from that file**, not from a clustering algorithm |
| A sidebar screen is often not its own module (`driver_earning` is Payment) | menu keys are resolved to modules through both the model *and* the controller |
| **No** Policies, Events, Listeners or API Resources anywhere | no node types for them; the overview says so explicitly, so nobody looks |
| `php artisan` cannot boot without MySQL (`AppServiceProvider` reads `languages`) | the indexer needs **no framework and no database**; artisan is optional |
| Docblocks are long, substantive and often Arabic | the first sentence of each is the summary, weighted above the file path |

That last row is why the search works as well as it does. This codebase
documents *why*, not *what*, and that prose is the most informative field there
is.

---

## Layout

```
.second-brain/
  README.md              this file
  config.php             roots, deny list, layer map, domain glossary
  autoload.php           four-line PSR-4 loader for Laundo\SecondBrain\
  bin/
    brain.php            the CLI
    mcp-server.php       the MCP stdio entry point
  src/
    Brain.php            the read side — one class, used by CLI and MCP alike
    Support/             Paths, Json, Text, Process
    Parse/               FileScanner, PhpParser, ModelAnalyzer,
                         MigrationAnalyzer, RouteCollector, BladeAnalyzer,
                         ConfigReader, GitHistory
    Graph/               Graph, Vocabulary
    Build/               FileIndexer, GraphBuilder, Indexer, Doctor
    Detect/              CommunityDetector, ModuleDetector, FeatureDetector
    Index/               Lexicon
    Search/              SearchEngine
    Export/              Exporter
    Bench/               Benchmark
    Mcp/                 Server, Tools
  data/                  generated — commit this, it is the brain
    manifest.json
    architecture/        overview, communities, modules, features
    graph/               nodes, edges, dependencies
    routes/              routes
    database/            tables, relationships
    git/                 history
    index/               lexical
    export/              architecture.mmd, architecture.html, graph-summary.json
  cache/                 generated — gitignored
    artifacts.json       per-file parse results, keyed by content hash
    routes.json          route table, keyed by the hash of the route files
```

---

## Why this storage, and not SQLite

Plain JSON files, one per concern, loaded lazily.

The whole graph is 5,500 nodes and 22,000 edges — about 6 MB. Reading the
files an answer needs takes milliseconds, and `Brain` loads them one at a time,
so a search never touches the module detail and `get_module` never touches the
search index. SQLite would add a schema, a migration story and a binary file in
git, and would buy nothing at this size: there is no query here that a hash
lookup and an adjacency list do not answer.

The one thing performance did need was an **adjacency index** on the graph.
`out()` and `in()` originally scanned all 22,000 edges per call, and the feature
detector calls them thousands of times; building the index once took a full
rebuild from **42 seconds to 1.1**.

If this repository grows an order of magnitude, the thing to change is the
lexical index — not the storage.

---

## How indexing works

```
                  ┌── artisan route:list --json  (preferred)
  files ──▶ parse │                                            ──▶ graph ──▶ detectors ──▶ lexicon ──▶ data/
   ▲              └── static reader of routes/*.php (fallback)
   │
  cache/artifacts.json (per file, keyed by sha1 of its content)
```

1. **Scan.** `FileScanner` walks the configured roots and applies the deny list
   twice — once while descending, once per file — so a path reached any other
   way meets the same rule.
2. **Parse.** One pass per file with PHP's own `token_get_all`. Each file's
   result is cached against its content hash.
3. **Routes.** `php artisan route:list --json`, run with a **sqlite override**
   so it boots without MySQL. It carries the middleware stack, which is where
   the `permission:` slug lives. If artisan cannot run, a static reader walks
   `routes/*.php` with brace counting to resolve `prefix()`, `name()` and
   `controller()` groups — it finds 414 of the 428 routes and the manifest says
   `route_source: static` so the degradation is visible.
4. **Graph.** Nodes and edges, every one of them witnessed by the source.
5. **Detect.** Communities, then modules, then features — in that order,
   because each uses the previous one.
6. **Index.** BM25 documents, one per node.
7. **Write.** Deterministic, sorted output: two builds of the same tree produce
   byte-identical files.

### Why no framework dependency

`php artisan --version` fails on this project when MySQL is down, because
`AppServiceProvider::boot()` reads the `languages` table. A code map that only
rebuilds when the database happens to be up is a code map that goes stale, so
the core is plain PHP with `ext-tokenizer` and nothing else. The artisan
commands are wrappers for convenience.

---

## The graph

### Node types

`community` · `module` · `feature` · `file` · `class` · `interface` · `trait` ·
`enum` · `method` · `route` · `table` · `permission` · `view` · `command` ·
`test` · `integration`

Public methods only become nodes. A private helper is an implementation detail,
and 1,500 of them would treble the graph and dilute every search result.

### Edge types

`contains` · `extends` · `implements` · `uses_trait` · `imports` ·
`depends_on` · `calls` · `references` · `route_to` · `guarded_by` · `renders` ·
`includes` · `validates` · `maps_to` · `belongs_to` · `has_many` · `has_one` ·
`belongs_to_many` · `foreign_key` · `writes_table` · `tested_by` ·
`integrates` · `co_changes` · `entry_point` · `implemented_by` · `touches`

### What is deliberately approximate

Honesty about this matters more than the edge count:

- **Calls are resolved only where the receiver is knowable without running
  anything**: `new X`, `X::y()`, `$this->method()`, and `$this->prop->method()`
  where `prop` came from a typed constructor parameter. That last one *is* the
  layer contract in this project, so it covers what matters.
- **A call on a local variable is not guessed at.** A wrong edge in a graph
  somebody is about to trust is worse than a missing one.
- **Framework classes are not nodes.** `Illuminate\…` is recorded on the
  artifact but pruned from the graph, so a dependency answer is this project's
  own code rather than a list of facades.
- **Model relationships** are paired by the Laravel idiom: a relation method
  containing `$this->belongsTo(` followed by `Target::class`. A relation built
  another way is recorded with a null target rather than a plausible one.

---

## Communities

The primary source is **`config/menu.php`**, whose own header explains the
arrangement ("the list reads in the order the platform is built"). Somebody who
knows the business wrote that down; running modularity detection beside it would
replace evidence with a guess — and the guess would be worse, because
`DriverEarning` is a `Payment` model and `PaymentLedgerController` serves two
different screens.

Seventeen communities: eight menu dropdowns, three menu singles, and six
derived from the directory layout for the surfaces the sidebar never mentions
(the API, authentication, notifications, the public site, routing, the
database, tests and docs).

Each carries:

- `source` — `menu` (declared), `derived` (directory layout) or `orphan`
- `evidence` — the exact file and key it came from
- `cohesion` — internal edges over all edges touching it. A number, not a
  verdict: `money` scoring low is correct, because settlement reaches into
  orders, laundries and drivers by design.
- `inferred_modules` — modules attached by call-graph weight rather than by the
  menu, kept separate so the weaker claim stays visible.

A module's **primary** community is decided by weight, not by whichever
community was walked first. That mattered: `delivery` lists `order_task` and
`driver_earning`, so first-come gave it the entire order lifecycle *and* the
settlement engine.

---

## Features

A feature is a capability with an **entry point the source can point at**.
Three kinds, and each feature says which one made it:

| Kind | Evidence |
| --- | --- |
| `route` | a registered route, grouped into a capability by its action (`store` → Create) |
| `command` | an artisan command |
| `service` | a domain service class — not a `{name}CrudService` |

Each is then filled in by walking outward: the request that validates it, the
service, the repository, the models, the tables those models map to, the view
it renders, the tests that name any of them.

Nothing is inferred from a name alone. A feature list somebody cannot verify is
worse than none — it sends a reader to a capability that does not exist and
they lose the time twice.

`doctor` reports domain services no route or command reaches. That is not a
bug; several are real (the routing drivers, `MenuBadges`, `PermissionGenerator`),
and CLAUDE.md already notes that four order statuses have no endpoint driving
them.

---

## Search

BM25 over weighted fields, plus four things that matter more than the ranking
function:

1. **Intent routing.** "What depends on CouponService?" is a graph traversal,
   not a search. Six question shapes are recognised and handed to the right
   tool.
2. **Query expansion through a domain glossary** (`config.php → synonyms`).
   "courier" reaches the Driver module; "discount" reaches Coupon and Offer.
   Only the query is expanded — expanding the index would make every laundry
   document match "washing".
3. **Exact-symbol short-circuit**, with a node whose *own name* is the query
   beating one that merely lists it as a secondary symbol. (Searching
   `order_settlements` used to return the `OrderSettlement` model, because the
   model carries its table name too.)
4. **Clause weighting.** "Where does an order status change, **and what
   validates it**?" is two questions; the head of the sentence is what the
   asker is looking at. Weighting them equally let `validate` — a rare word,
   so a high IDF — carry three `FormRequest` classes to the top.

Field weights: `name` 6.0 · `symbol` 4.0 · `facet` 2.5 · `summary` 2.2 ·
`path` 1.5. Summary above path because a path repeats its module name two or
three times and means it once.

Results are **diversified to one hit per file**, and scored with a type prior
(a class outranks its own methods) and a layer prior (a service outranks a
test that merely names the concept).

### It is not embeddings, and does not pretend to be

There is no vector model here. Embeddings would need either a network call on
every query — putting this codebase's identifiers through a third party and
breaking offline use — or a local model, which is a dependency nobody asked
for. What is being searched is *identifiers*, and in this codebase they are
unusually informative.

The limitation is real and worth knowing: **a question that uses none of the
project's vocabulary degrades.** "How are laundry owners prevented from seeing
each other's orders?" does not reach `LaundryContext`, because neither
"prevented" nor "seeing" appears anywhere near it. Asking about "tenant scope"
finds it immediately.

---

## MCP integration

`.mcp.json` at the repository root registers the server. Six tools:

| Tool | Answers |
| --- | --- |
| `second_brain_search` | Where does this live? |
| `second_brain_get_feature` | One capability end to end — routes, permission, files, tables, tests |
| `second_brain_get_module` | One module's shape and its dependents |
| `second_brain_get_dependencies` | Blast radius: what needs this, what does it need |
| `second_brain_get_related_files` | What travels with this file |
| `second_brain_get_architecture` | The shape of the whole thing, read once per unfamiliar task |

Six, not twenty: every tool's schema sits in the model's context on every turn
whether it is used or not.

Responses are capped at 24 KB and truncate with a note saying how to narrow the
question.

Check it by hand:

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php .second-brain/bin/mcp-server.php
```

---

## Incremental updates

```bash
php .second-brain/bin/brain.php update          # against HEAD
php .second-brain/bin/brain.php update --since=origin/main
php artisan second-brain:update
```

Changed files come from git — working tree, index **and** untracked, because
work in progress is what somebody re-indexing wants picked up. Those paths are
still filtered through the deny list before anything is opened.

**Only parsing is incremental. Everything downstream is rebuilt in full** from
the cached artifacts, which costs about a second. Patching nodes in place would
be faster and would drift: an edge produced by *another* file that named this
one would be left behind, and the drift is invisible until somebody trusts a
stale answer.

---

## Security

The brain must never carry a secret, and the rule is enforced in three places
rather than trusted once:

- `config.php → deny` and `deny_files` — `.env*`, `*.key`, `*.pem`, `*.log`,
  `auth.json`, `storage/`, `vendor/`, `node_modules/`, build output.
- `FileScanner::accepts()` is the single gate; `read()` throws on a denied path
  even when handed one directly, and reads the **normalised** path so the gate
  cannot check one path while the filesystem opens another.
- **Paths are resolved lexically before the deny list is applied.** The list is
  a prefix test, so without this `app/../vendor/autoload.php` does not start
  with `vendor/` and walks straight through. Nothing reaches the gate with a
  `..` today — the walker emits real paths and `git diff` never does — but a
  deny list that `..` gets around is not a deny list. A path that climbs above
  the repository root, or carries a null byte, is refused outright.
- **Raw string literals are never persisted.** The parser captures them because
  `ModelAnalyzer` needs one shape of them (a `*_id` foreign key) and they are
  dropped as soon as it has run. Keeping them would have put every literal in
  every scanned file — `config/` included — into a cache file, read by nothing.
- The autoloader only turns a well-formed class name into a path, so nothing
  can be smuggled through a `require`.
- `Process::run()` passes an **argument array** to `proc_open`, never a string,
  so no shell is involved anywhere. `changedSince()` additionally refuses a
  reference that is not shaped like one, so a value beginning with `-` cannot
  be read by git as an option.
- `doctor` re-tests every indexed path against the deny list **and** scans the
  generated files *and the parse cache* for the shapes a credential takes (AWS
  keys, Google API keys, private key blocks, Laravel app keys, Stripe live
  keys).

`IndexerTest` plants a `.env` with real-looking secrets in its fixture and
asserts that none of them reaches any generated file; a second test walks eight
traversal attempts against the gate.

---

## Commands

### CLI (no database, no framework)

| Command | What it does |
| --- | --- |
| `index` | Full build. Safe to repeat; deterministic. |
| `update [--since=REF]` | Re-parse only what git says changed. |
| `search "<question>"` | `--limit` `--type` `--module` |
| `feature <id\|group>` | One capability, or every capability in a group. |
| `module <Name>` | One module. |
| `deps <symbol>` | `--direction=inbound\|outbound\|both` `--depth=1\|2` |
| `related <symbol\|path>` | Files that travel with this one. |
| `arch [section]` | `overview` `communities` `modules` `database` `routes` `git` |
| `export [--format=…]` | `mermaid` `html` `json` `all` |
| `bench` | Token cost with the brain and without. |
| `doctor` | Staleness, secrets, vocabulary, degraded routes, orphans. |
| `stats` | The manifest. |

`--json` on any command for machine-readable output.

### Artisan (wrappers)

```bash
php artisan second-brain:index
php artisan second-brain:update [--since=HEAD]
```

These need artisan to boot. On a machine without MySQL, use the CLI.

---

## Benchmark

`php .second-brain/bin/brain.php bench`

For each task the baseline is measured, not assumed: the naive grep is actually
run over the repository, and the files it hits are summed at their real byte
size (capped at twelve files, which is generous — it assumes the right ones are
among them). The brain side is the exact bytes of its tool response plus the
top three files it named.

Tokens are **estimated at four characters per token**. There is no tokeniser
here and adding one would be a dependency; the character counts are reported
beside the estimate so the conversion can be checked, and the ratio — which is
what the benchmark is for — does not depend on the constant.

Current result on this repository: **80.3% reduction, answer in the top three
for 5 of 6 tasks.**

---

## Troubleshooting

**"The brain has not been built."**
`php .second-brain/bin/brain.php index`

**`doctor` says routes came from the static reader.**
`php artisan route:list` cannot run. The static reader finds 414 of 428 routes
but carries no middleware, so `permission` is null everywhere. Fix artisan and
re-index.

**A search returns something that no longer exists.**
The brain is stale. `php .second-brain/bin/brain.php update`.

**The MCP server "hangs".**
Something wrote to stdout that was not JSON-RPC. Run it by hand and look at
stderr:
`echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php .second-brain/bin/mcp-server.php`

**A rebuild reuses stale artifacts.**
`rm -rf .second-brain/cache` then index. (The cache lives *inside* `cache/`;
an earlier path bug put it beside the directory, which is why this is worth
saying.)

---

## Tests

```bash
php artisan test --filter=SecondBrain
```

55 tests, 416 assertions, in three files:

- `IndexerTest` — detection against a fixture repository built in a temp
  directory, where the answers are known by construction: modules, routes,
  layers, the Controller→Service→Repository chain, form-request wiring, model
  relationships, migration replay including a `down()` that must be ignored,
  features, tests, views, secret exclusion, deny-list traversal, cache reuse
  and its invalidation on a config change, incremental update, determinism.
- `SearchTest` — the same code against **this** repository's real modules:
  exact, keyword, glossary, relationship, feature, module and architecture
  queries, each naming a file that exists.
- `McpServerTest` — the server started as a real subprocess and spoken to over
  stdio: handshake, tool list, error codes, response caps (including the five
  answers large enough to need shortening, which must stay valid JSON), clamped
  arguments, no source and no secrets in any response, plus the doctor's checks.

---

## Known limitations

1. **Search degrades on vocabulary the codebase does not use.** Documented
   above with the example that fails.
2. **Call edges stop at the resolvable receiver.** A call through a local
   variable or a container `make()` is not an edge.
3. **`tested_by` is import-based**, so a test that names a class it does not
   really exercise still produces an edge.
4. **Blade is read with regular expressions**, not compiled. A dynamically
   named `@include` is missed.
5. **The static route fallback carries no middleware**, so `permission` is
   null when artisan cannot run. The manifest says so.
6. **Features are route-shaped.** A capability with no route, no command and no
   domain service of its own does not appear.
7. **Git history is capped at 400 commits.** Co-change coupling is drawn from
   that window only.
8. **Token figures are estimates** at four characters per token.
9. **An answer over 24 KB is shortened, not paged.** The MCP layer halves the
   longest lists until it fits and says which ones it cut under `_shortened`;
   there is no cursor to ask for the rest. Ask about one feature or module at a
   time instead.
