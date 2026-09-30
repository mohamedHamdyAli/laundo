# Zones drawn on the map (asked 2026-09-28)

«العميل محتاج يعمل زون تتحدد ف المكان زي رسمه بحدد نطاق المكان دا علي الخريطه».
Today a zone is a name + city + rates, picked from a dropdown by the app; the
server never derives it from the pin and nothing is geometric.

Owner's decisions (2026-09-29): an address outside every drawn zone is
accepted and handled by hand (as today); a driver is only handed trips inside
their zones (dispatch, not live-GPS alerts); a laundry may sit outside the zones
it serves; zones may not overlap.

- [x] Migration `2026_09_29_100000`: `zones.boundary` + indexed bounding box.
- [x] `Support/Geo/Polygon`: contains, overlaps (edge crossing or an interior
      sample well inside), validity (area, spikes, touching edges, ≤ 500),
      10 cm tolerance so shared borders are not overlaps.
- [x] `ZoneLocator`: zoneAt, forAddress / placeAddress (the address API),
      assertNoOverlap (form and save, locked), relocateAround (redraw and
      switch-on; never another switched-off zone's addresses; holds addresses
      with an order under way).
- [x] Zone form: `<x-zone-drawer>` — add / move / remove corners, undo, clear,
      neighbours in grey, snapping onto their corners and edges, 500-corner
      cap. Driven in a browser harness (the panel cannot boot without MySQL).
      Zones list: a map of every drawn zone + «drawn / not drawn yet».
- [x] `/cities` carries `boundary`; Postman + reference; app note
      `docs/mobile-2026-09-29-zones.md`; zones sheet `drawn` column.
- [x] `ZoneBoundaryTest` (23), `PolygonTest` (11). `/code-review` (16): all
      fixed with tests.
- [x] Full suite 1,814 green. `/security-review`: nothing met the bar.
- Not seen: the real zone screen in a browser (MySQL down — the drawing tool
  was driven in a harness page with its own script lifted from the Blade).

# Piece count check (2026-09-28)

«لو ف اختلاف هحتاج ان الداش بورد سواء السوبر ادمن او المغسله يجيلهه زي تنبيه واضح».
Decisions: each leg compared with the handover before it (customer's order →
pickup count → laundry's review); the alert stays until somebody at the
platform presses «تمت المراجعة» with a note.

- [x] Migration: `order_tasks.expected_piece_count`, `expected_piece_source`,
      `piece_check_resolved_at/_by`, `piece_check_note`,
      `piece_check_confirmed_count`, `piece_check_open` (indexed).
- [x] `PieceCheck` (expected + resolve), stamped in `TaskService::complete()`.
- [x] `PieceCountNotifier` → platform (`order.update`) + the order's laundry,
      through `PanelAudience`, in the panel's language.
- [x] Order screen banner + «تمت المراجعة» (note + real count); transport cell in red.
- [x] Sidebar badge, home queue (both roles), orders filter + sheet filter —
      one `Order::withOpenPieceCheck()`.
- [x] Driver app `expected_pieces` = the same number; mobile note.
- [x] `PieceCheckTest` (19). Full suite before the review fixes: 1,767 green.
      `/code-review` (16): 15 fixed with tests; one is a product question for
      the owner — should a laundry's review count that differs from the count
      handed to it raise the same alert?
- [x] Full suite after the fixes: 1,773 green (472s). `/security-review`:
      nothing met the bar.
- [x] Owner (2026-09-29): the laundry's review count is checked against what
      was handed to it; the driver is not shown the expected count before
      confirming. Reshaped into `piece_discrepancies` (one row per
      disagreement). Second `/code-review` (15) — all fixed with tests.
      `PieceCheckTest` (29).
- [x] Full suite 1,782 green. `/security-review` of the reshape: nothing met
      the bar.

# Validation messages editor (2026-09-28)

«انت نسيت هنا الصفحه الي هقدر اعدل فيها الفالديشين ف اللغه».

- [x] `ValidationOverrideLoader` over `translation.loader` (`extend()`), store
      `{code}_validation.json`, flat dotted keys over the shipped PHP file.
- [x] `Services/languages/ValidationMessages`: groups (messages / field names),
      save — known keys only, placeholders kept (all-or-nothing), blank resets.
- [x] `admin.language.validation{,.update}` (`language.update`), view with a
      client filter, link in the Languages actions menu, deleted with the
      language. `ValidationMessagesEditorTest` (13).
- [x] Docs: CLAUDE.md, Changelog, QA guide + PDF.
- [x] Full suite 1,745 green. `/code-review` (15 findings) — fixed with tests:
      unescaped toast sink (`@json`), store moved to `storage/app/lang`, `en`
      keys merged into the rows, `attributes` wiped for a language with no file,
      Laravel-only placeholder spellings + re-check at load, dotted field names,
      atomic write + UTF-8, rename moves the file, activity-log row, filter on
      wording, `old()` null, FA5 icons, one `pathFor()`.
      `ValidationMessagesEditorTest` (22).
- [x] Full suite again 1,754 green (297s). `/security-review`: no finding met
      the bar.

# Delivery leaves the service its time (2026-09-28)

«خدمة مدتها من يومين لـ 4 والعميل بيختار الاستلام والتسليم في نفس اليوم».
Decisions: the middle of the range; hours exact, days whole days; a postponed
pickup pushes the delivery.

- [x] `Order/Services/Turnaround` + `Concerns/DeliveryAfterTurnaround` on
      `POST /orders`; `too_early` on `/time-slots` and the reschedule options;
      `delivery_after` on `/services`; `RescheduleService` moves the delivery.
- [x] `TurnaroundTest` (13). `/code-review` (10 findings, 9 fixed with tests,
      1 not reproducible); `/security-review`: nothing met the bar.
- [x] Docs: CLAUDE.md, Changelog, QA guide + PDF, Postman, reference, app note
      `docs/mobile-2026-09-28-turnaround.md`. Full suite 1,719 green.

# Next batch — queued 2026-09-28 (start after the activity-log task is shown)

The owner's next four requests, in their words, to discuss and then build. A
fifth point is to be discussed with the owner once these are done.

Decisions taken with the owner (2026-09-28):

- Offer scope lives **on the coupon** (the offer's discount *is* its coupon),
  so a typed code is scoped too; **one kind** (category / service / item) with
  **several values**; the discount comes off the matching pieces only.
- Auto-assign off → the work waits for a person **and** the operators get a
  bell notification each time.
- Red discount on the order screen, the review form **and the invoice**.
- Settings tabs: عام / التواصل / الفلوس / التشغيل; add the missing
  `Cash_Surcharge` input to the money tab.

- [x] **Discounts in red** — order screen pricing card, settlement card,
      review form, invoice. `InvoiceDocumentTest`.
- [x] **Settings page in tabs** — four tabs; a 422 opens the tab holding the
      first bad field; the open tab survives a save; `Cash_Surcharge` input.
      `GeneralSettingTabsTest`; `commission.spec.js` opens the money tab.
- [x] **Auto-assign on/off** — `Auto_Assign_Laundry`, `Auto_Assign_Driver`
      (on by default = today). Laundry: `place()` leaves it unassigned. Driver:
      `TaskGenerator`, `tasks:dispatch` sweep, re-dispatch after a failed
      attempt and after a reschedule all stop; the operator's own «auto pick»
      buttons still work. Bell to whoever holds `order.update` /
      `order_task.update`.
- [x] **Coupon scope** — `coupons.scope_type` (null = whole order / category /
      service / item) + values; `Coupon::discountFor()` on the matching lines'
      subtotal; quote/place pass the lines; `/coupons/check` with items;
      offer form shows the scope; app note + Postman.

Review (2026-09-28):

- Coupon scope: `coupons.scope_type`/`scope_ids`, `Coupon::eligibleSubtotal()`
  / `eligibleFor()`, `OrderPricing::lines()`, `CouponService::check()`;
  copied to `orders.discount_scope` and re-worked at review. `CouponScopeTest`
  (14). App note `docs/mobile-2026-09-28-coupon-scope.md`, Postman + reference.
- Auto-assign: `AutoAssign`, `AssignmentNotifier`,
  `DriverDispatcher::automatically()`; an order with no laundry is never
  dispatched automatically and is offered on assignment. `AutoAssignTest` (9).
- `/code-review` (10 findings) all fixed with tests; `/security-review`: no
  finding met the bar.
- Not run: the browser suite and a look at the new screens — MySQL was down
  for the whole session. Deploy: migrate (two new migrations today).

# Activity log — worded for the owner (2026-09-28)

- [x] `ActivityPresenter`: one sentence per row («تعديل مدينة «القاهرة»»), who by
      role, where from by the screen's name, only meaningful fields in words.
- [x] Order history and the Excel export worded through it.
- [x] Permissions no longer recorded or listed; panel sign-in is «لوحة التحكم».
- [x] Record names kept in every language, read in the reader's.
- [x] `/code-review` (8 findings) — all fixed with tests: coupon bearer via
      query/JSON; period rise made permanent charged twice on in-flight orders
      (re-stamp on permanent/undo); landing cache missed permanent rise/undo;
      bulk writes invisible to the log; in-flight discounted orders' payout
      (migration 2026_09_28_100000); today's search; stale settlement docblock;
      driver pay in the order history.
- [x] `/security-review` (2 MEDIUM) — fixed with tests: Excel formula injection
      (every string a StringCell); complaints and internal notes in the order
      history for laundry roles (allow-list `OPEN` + `GATED`).
- [x] Docs (CLAUDE.md, Changelog, QA guide + PDF). Full suite green: 1,677 tests, 337s.

# Laundry share — the laundry takes the percentage (2026-09-27)

Client change: the percentage set on a laundry is what the **laundry receives**
from the washing; the platform keeps the rest. Today it is the reverse (the rule
is the platform's commission). Decisions taken with the owner:

- No rule on a laundry → a general **`Laundry_Share_Rate`** setting applies; with
  neither, the settlement stays **pending** and nothing moves.
- Existing rules are **converted per laundry** on deploy so nobody's payout moves
  (platform 10% → laundry 90%). Settled rows are never touched.
- **Percentage only** — the fixed-per-order basis is retired; a laundry carrying
  a fixed rule is reported, not guessed at.
- **One active share per laundry** — no stacking.
- Per-piece vs per-order: audited by running the real code — identical except
  piastres of rounding; the basis already is the laundry's piece prices after the
  discount. Left per order, as agreed.

## Plan

- [x] Migration: `order_settlements.laundry_share_rate` (nullable) — the % the
      laundry was paid at; null on legacy rows and on a pending row with no share.
- [x] Migration (data): convert rules per laundry, one active share each; fixed
      and stacked handled explicitly; rule-less laundries pinned at 100% so their
      payout does not change; logged.
- [x] `SettlementService`: resolve share (rule → setting → none); laundry =
      round(basis × share), platform = basis − laundry; unresolved → pending,
      settleFor refuses.
- [x] Settle-now action for a pending settlement of a completed order
      (`setting.update`), so a share set later can be paid.
- [x] Rules: percent only, one active rule per laundry (request, toggle,
      laundry modal → single choice).
- [x] Setting `Laundry_Share_Rate` on the general settings form + request.
- [x] Views: settlement list, order show card, laundry list cell, rule form copy.
- [x] Translations (ar), tests updated + new, docs (order-cycle-explained, QA
      guide, CLAUDE.md money section), Changelog, second-brain update.
- [x] Full suite
- [ ] /code-review + /security-review — before commit (client has more changes queued)

## Review

- Split lives in `SettlementService::splitFor()`; laundry amount rounded, platform
  by subtraction. Unresolved share → pending, `settleFor()` refuses; `settleWaiting()`
  + `admin.settlement.settle` pays later, row locked against a double click.
- Migration converts per laundry (single flip / stacked combine / unruled 100% /
  fixed left waiting + logged). `LaundryShareMigrationTest` asserts each laundry is
  paid the same on the same basis before and after.
- Coupon bearer (decided with the owner): per coupon — platform (default) /
  laundry / split — with `Coupon_Laundry_Share` as the default; laundry's share
  measured before the discount; delivery part always the platform's; laundry
  floored at 0, platform may go negative (wallet overdraft, `discount_funded`);
  field gated on `setting.update`. Copied onto the order at placement.
- Price increase: `PriceIncrease` (period = settings rate + optional end,
  read-time; permanent = rewrite `item_prices` + clear). Laundry's price, under
  the fee; stamped on the order for the review. `PriceIncreaseTest`.
- «طلبات اليوم»: `OrderTodayController` / `OrderTodayService` / `OrderRepository::todayBoard()`;
  scopes in_laundry (legs) / delivery_today / pickup_today, nearest first; totals by
  service→item; `menu.permissions` alias → `order.view`. `OrderTodayTest`.
- «طلبات اليوم» moved into an «Orders» sidebar group (with «All orders»); routes stay
  `admin.order_today.*` so the two items light up apart.
- Laundry services need approval: `LaundryServiceRequest` + `LaundryServiceRequestReview`;
  registration names services; review screen `admin.laundry_service_request.*`.
  `LaundryServiceRequestTest`. Deploy: PermissionSeeder.
- Browser specs rewritten but not run: dev MySQL was down this session.
- Excel export/import: `app/Support/Spreadsheet` + 32 sheets; import through each
  screen's FormRequest + crud service, row by row. `Spreadsheet*Test`.
- Price increase history + undo of the latest standing permanent rise
  (`PriceChange` / `PriceChangeItem`); hand-corrected prices kept. 5% backfilled.
- Activity log: `ActivityLogger` on wildcard Eloquent events + sign-in/out,
  `activity_logs.diff`, secrets `••••`, excluded models / ignored attributes in
  `config/activity.php`, source dashboard/api/site/system; screen
  `admin.activity_log.*` (`activity_log.view`); order screen history =
  `OrderHistory` (status logs + rows by `order_id`, ids named); prune 6 months.
  `ActivityLogTest`. Deploy: migrate + PermissionSeeder. Known gap: bulk
  query-builder writes fire no event.

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

## 2026-09-30 — search and shape tools on the zone map

- [x] Place search on the zone map (form and overview): one shared `<x-map-search>`, used by `<x-map-picker>` too; a found place moves the view and is marked, never a corner
- [x] Shape tools: rectangle, circle (32 corners), triangle, freehand (≤100 corners) — a drag that becomes ordinary corners, snapped; replaces the drawing, Undo restores
- [x] Fix: the «at most 500 corners» warning showed on every empty map (`d-block` beat `hidden`)
- [x] City form map (pin + search) checked on add and edit — unchanged, as the owner asked
- [x] Tests: `ZoneBoundaryTest` (+4), `MapPickerTest` (+2), new `tests/Browser/zone-drawer.spec.js` (5, Nominatim stubbed)
- [x] Full suite 1,819 green; /code-review (no findings, one radius nit fixed) and /security-review (no findings)
- [x] Docs: CLAUDE.md, Changelog, QA guide + PDF, `docs/qc-2026-09-30-release`

## 2026-09-30 — the driver's order screen (`GET /driver/orders/{id}`)

The driver app had a task screen and no order screen: the pieces, both ends'
windows, the order's own status and the driver's other legs on it were nowhere.

- [x] `GET /api/v1/driver/orders/{id}` — only an order the driver holds a leg on (404 otherwise, same as tasks)
- [x] Pieces: names always; `qty` / `items_count` **null until the collection from the laundry is complete** — one predicate (`countsRevealed()`) shared with the delivery leg's `expected_pieces`; until then the list is the customer's, never the laundry's
- [x] Each end's address and phone, the laundry's contact, and the payment block only with the leg whose task screen already shows them
- [x] `my_tasks`: the driver's own legs on the order, in the list's shape; `order_id` added to every task row so the app can open the screen
- [x] Tests in `DriverTaskTest` (+6: access, withholding, privacy, a failed leg, query count) — each checked to fail with its rule removed
- [x] Postman + `generate-reference.py` + `docs/mobile-2026-09-30-driver-order-details.md` + `mobile-api-changes.md`
- [x] Changelog, CLAUDE.md, brain update; /code-review + /security-review

### Review

- Driven on the dev database as two real drivers: an order not yet collected from
  the laundry names its shirt with `qty: null`; a completed one shows `qty: 3`.
- /code-review (8 findings): fixed the payment total and the laundry's contact
  reaching drivers whose task screens never show them, the laundry's reviewed
  list hinting at the count before it opens, a redundant query, and the door
  block built twice. Left: the ≤3 `predecessorComplete()` queries (bounded by
  four legs, in a shared model), a shared line presenter across controllers, and
  moving the lookup into a repository — every driver lookup in this controller
  queries directly.
- /security-review: nothing at or above the bar.
- The full suite ran in a clean worktree (HEAD plus this change only), because
  another session's finance work was half-written in the main tree.

## 2026-09-30 — the home page without money, and a finance page of its own

The owner: «العميل مش محتاج يوضح ف الصفحة تفاصيل كتير عن الماليات… صفحة خاصة بالماليات… والصفحة دي احصائيات بس… رسم بياني دايره».
Decisions: a **new permission** for the finance page (super admin only until granted); the laundry's home loses its money too; four charts — stages now, orders per day, orders by service, ratings.

- [x] `Report/Models/Finance` — permission carrier (`finance.*`), like `Report` / `LaundryRevenue`; `config/dashboard.php`; PermissionSeeder
- [x] `Report/Services/FinanceSummary` — today's money taken, the month's net / owed / paid orders, money per day (from `RevenueReport::summary()` / `daily()`, one definition)
- [x] `admin.finance.index` (`permission:finance.view`), `Report/Controllers/FinanceController`, `admin/finance/index`, Money group's first item
- [x] `DashboardSummary`: no money anywhere (today, month, laundry score); + picked up today, completed this month, `ordersByDay()`, `ordersByService()`, `ratingSpread()` — all through the scoped Order
- [x] Home view: stages donut (replaces the tiles, legend keeps icons + counts), orders per day (lines + table view), by service (donut, ≤5 + «other»), ratings (HTML bars)
- [x] Charts: ApexCharts (already on every panel page); palettes validated (categorical 1–3, 1–5 ring, ordinal blue both modes); panel tokens for ink/grid; redraw on `body.theme-dark`; custom tooltips with escaped text (translations are operator-editable)
- [x] Tests: HomeTest (no money on either home, chart series, tenant), FinanceTest (gate, figures, menu), translations
- [x] Browser: light + dark, laundry owner, phone width; full suite; /code-review, /security-review
- [x] Docs: CLAUDE.md, Changelog, QA guide, QC note

### Review

- Done as planned, plus more on the finance page at the owner's «زود احصائيات»: deltas against the same days last month, «who keeps what», payment methods, top services and laundries.
- Security review: nothing exploitable. The two `@json([...])` array literals (which Blade splits on commas, dropping the escaping flags) go through a variable now.

## 2026-09-30 — today's windows on the business clock

The owner: «لما بطلب بميعاد ساعه فاتت بيوصل للمندوب انه متاخر…». Decisions: Cairo; today's window closes an hour before it ends, as a setting.

- [x] `TimeSlot/Services/SlotClock` — the one place a window becomes an instant; `TaskGenerator`, `OrderEta`, `Turnaround`, `GeoController`, reschedule
- [x] `WindowStillOpen` on `OrderRequest`; backstops in `place()` (pickup and delivery) and `RescheduleService`
- [x] `Slot_Booking_Cutoff_Minutes` (Operations tab); `app.display_timezone` defaults to Cairo; phpunit pins UTC
- [x] Data migration re-dating open legs (run `config:clear` **before** `migrate` on deploy)
- [x] Tests, mobile note, QA guide, QC note

### Review

- Code review found `delivery-window` calling a closed chosen delivery valid → `Turnaround::CLOSED`. Security review: clean.
- The full suite caught `TrackPayloadTest` booking yesterday through `place()`; it books ahead and travels now.

## 2026-09-30 — «مفيش اشعارات بتوصل للدرايفر»

- [x] Live: FCM works (111 customer pushes); **0 of 6 drivers ever registered a handset** → 206 `task_assigned` pushes skipped. App work → `docs/mobile-2026-09-30-driver-notifications.md`, Postman 6.4, the 2026-09-20 answer marked overtaken
- [x] The list API already served drivers. But a row was filed under the sending class, so what went through `User` (broadcasts, closed complaints) never reached a driver's list → `User::notifications()` + migration `2026_09_30_130000`
- [x] Tests prove the fix (they fail without it)

### Review

- The backend could not have fixed the push: without a device token there is nobody to send to. What it could fix was the list, and the note that told the app team FCM was off.

