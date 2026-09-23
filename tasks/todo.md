# Second Brain — codebase knowledge graph

## Phase 1 — architecture discovered (done, from inspection)

Laravel 13 / PHP 8.3. **No modular package** (no nwidart): a hand-rolled
`app/Modules/{Name}/` tree with PSR-4 `App\Modules\`, 31 module dirs.

Inner folders actually present, with counts:
Controllers 30 · Models 29 · Services 28 · Requests 24 · Repositories 23 ·
Enums 8 · Console 4 · Data 3 · Gateways 1 · Contracts 1.

**Absent, and the brain must not invent them**: `app/Policies`, `app/Events`,
`app/Listeners`, `app/Http/Resources` — none exist. One job
(`app/Jobs/SendManualNotification.php`), one notification class. Authorisation
is `permission:` middleware + `canDo()`, not policies. API payloads are private
`present*()` methods, not Resources.

Three surfaces over one codebase: Blade panel `/admin`, JSON API `/api/v1`,
public landing. 428 registered routes.

Layer contract: Controller (HTTP only) → Service (`{name}CrudService` for CRUD,
PascalCase for domain) → Repository (only place raw Eloquent lives) → Model.
`shredData($id = null)` is the universal view-data assembler.

Owner-declared domains already exist and are the honest source for
"communities": `config/menu.php` `groups` (locations, catalog, laundries,
delivery, marketing, operations, money, system) + `singles` (user, order,
report). `config/dashboard.php` lists the 43 permissioned models.

Constraint found: `php artisan` fails without MySQL (AppServiceProvider reads
`languages` at boot), so **the indexer core must not need the framework or a
database**. `route:list --json` works under a sqlite override and is used as the
preferred route source, with a static parse of `routes/*.php` as fallback.

## Phase 2..11 — plan

- [x] Phase 1 — inspect repository, write this map
- [x] Phase 2 — design `.second-brain/` (PHP, zero new dependencies)
- [x] Phase 3 — tokenizer-based parsers + graph builder
- [x] Phase 4 — BM25 lexical search + synonym expansion + intent routing
- [x] Phase 5 — module / community / feature detection (evidence-carrying)
- [x] Phase 6 — MCP stdio server, 6 tools
- [x] Phase 7 — `.mcp.json` + CLAUDE.md section
- [x] Phase 8 — incremental re-index off git diff + per-file hashes
- [x] Phase 9 — PHPUnit tests under `tests/Feature/SecondBrain/`
- [x] Phase 10 — token benchmark command
- [x] Phase 11 — `.second-brain/README.md`

## Rules held to

- No business logic touched. Nothing renamed. No migration, no schema change.
- No new composer or npm dependency. Pure PHP + ext-tokenizer (already present).
- Secrets never indexed: `.env*`, keys, `storage/`, `vendor/`, `node_modules/`.
- Every feature and community carries `evidence`; nothing is asserted that the
  source does not show.

## Review

Built, and verified against this repository rather than against a description
of it.

**Shape**: 918 files → 5,614 nodes, 19,991 edges. 42 modules in 17 communities,
411 features, 428 routes, 66 tables, 59 models, 130 test files. Cold build 2.1s,
warm 1.1s, deterministic — two builds produce byte-identical files.

**Suites**: 1,532 PHPUnit tests / 5,266 assertions green, of which 55 / 416 are
the brain's own. `doctor` reports healthy.

**Benchmark**: ~230k estimated tokens of grep-and-read exploration reduced to
~45k across six real tasks — **80.2%** — with the expected file in the top three
for five of the six. The sixth is reported as a miss rather than tuned away: the
question used no vocabulary the codebase contains.

**Reviews (§7)**: `/code-review` at high effort returned nine findings, all
reproduced by running the code; `/security-review` hit the session rate limit
mid-run, so the security pass was done directly over the same surfaces. Every
finding was fixed before this was called done — the MCP size cap cutting JSON
mid-structure while reporting success, unsliced feature groups three times the
cap, a deny list that `..` walked around, raw string literals persisted to the
cache, a parse cache keyed without its config, a `static` inside a method
sharing one repository's deny lists with another, two fabricated module paths, a
dead `reads_tables` branch, a fatal default argument, and an undefined array
key. Each has a regression test.

Two notes deliberately left as findings rather than fixed, because they are
facts about this repository: eleven domain services no route or command reaches
(the routing drivers, `MenuBadges`, `PermissionGenerator` and the like), and two
models whose `$table` names a table no migration creates — both of which
`doctor` reports every run.

Full documentation in `.second-brain/README.md`.
