# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

**Two files, one account.** This file holds the rules that bind code. The
reasons behind them, the incidents that set them and the full class and setting
names are in **`docs/architecture.md`**, under the same section names. Read the
section there before changing that area, and keep the two in step when a rule
changes.

## Commands

```bash
composer dev          # server + queue:listen + pail logs + vite, concurrently
composer test         # config:clear then artisan test (PHPUnit 11 — NOT Pest)
composer test:browser # npx playwright test (boots its own server on :8800)
composer test:all     # composer test, then the browser suite
composer stan         # phpstan analyse app --level=5 (via larastan)
./vendor/bin/pint     # formatter (no composer script)
vendor/bin/php-cs-fixer fix          # second formatter, config .php-cs-fixer.dist.php
vendor/bin/rector process --dry-run  # config rector.php
php artisan ide-helper:models -W     # refresh model @property docblocks
php artisan laundo:sync-web-lang     # push new webFile.php keys into each {code}_web.json
npm run build / npm run dev

php .second-brain/bin/brain.php index   # rebuild the codebase map — see "The Second Brain" below
php .second-brain/bin/brain.php update  # …or only what git says changed
```

Single test: `php artisan test --filter=TestName` · one file: `php artisan test tests/Feature/Api/OrderTest.php` · one suite: `php artisan test --testsuite=Unit`. One browser spec: `npx playwright test tests/Browser/<name>.spec.js`.

The full PHPUnit suite takes **four to thirteen minutes** — measured runs on the
same machine came in at 251s, 317s, 477s, (at 1,570 tests, 2026-09-27) 341s
and 521s, (at 1,662 tests, 2026-09-28) 575s, (at 1,814 tests, 2026-09-29,
with another agent busy on the machine) 643s, (at 1,861 tests, 2026-09-30)
317s, and (at 1,863–1,877 tests, 2026-10-01/04) 596s, 747s and 785s, so
budget for the longest.
When MySQL is down, `php artisan test` cannot boot at all (see the Second Brain
note below) — run `vendor/bin/phpunit` directly, which reads `phpunit.xml`'s
SQLite and needs no database server. Use
`--filter` while iterating and run the whole thing once, before you call the
task done. It has grown steadily; if it runs much longer than this, re-measure
and correct the figure here rather than working around it.

PHPUnit runs against in-memory SQLite (`phpunit.xml`); the app itself runs on MySQL. Anything relying on MySQL-only SQL will pass in tests and fail in the app.

## The Second Brain — ask it before exploring

There is a queryable map of this codebase in **`.second-brain/`**, exposed to
Claude Code as the MCP server `second-brain` (registered in `.mcp.json`). It
holds the modules, every route with its permission, the schema, the
Eloquent relationships, a feature map and a dependency graph — and it answers
in file paths and relationships, **never in source code**.

**Use it first for any non-trivial task**, in this order:

1. `second_brain_search` with the task in plain words. Unfamiliar area?
   `second_brain_get_architecture` once, first.
2. Read the community and module it names — that is where the change belongs.
3. `second_brain_get_feature` for the capability: its routes, the permission
   gating them, its files in relevance order, its tables and its tests.
4. `second_brain_get_dependencies` before changing anything shared — it is the
   blast radius, and it is how you find the callers a grep misses.
5. **Read only the files it ranked.** Two or three, not thirty.
6. Implement, then run the tests it named.
7. If the task changes files, routes, models, migrations, permissions, Blade
   views, API payloads or module structure, run
   `php .second-brain/bin/brain.php update` (or `php artisan second-brain:update`)
   afterwards so the map does not go stale.
8. If its results conflict with the source code, trust the source code, finish
   the task, then update the index.

**Do not use it** for a one-line change in a file already open, for copy or a
translation, or for anything where you already know the file. The overhead is
not free and a lookup that tells you what you knew is waste.

**Trust it, but it is not the source.** It is generated: if it names a file that
is not there, it is stale — `update` it rather than working around it. And it
only knows what the source shows, so it has the gaps `.second-brain/README.md`
lists under *Known limitations* (notably: a question phrased in words this
codebase does not use will not find much — say "tenant scope", not "stop owners
seeing each other's data").

```bash
php .second-brain/bin/brain.php index      # full rebuild (~2s, deterministic, safe to repeat)
php .second-brain/bin/brain.php update     # re-parse only what git says changed
php .second-brain/bin/brain.php doctor     # staleness, secrets, degraded routes
php artisan second-brain:index             # the same, when artisan is already to hand
```

The CLI is the real entry point because **`php artisan` cannot boot when MySQL
is down** (`AppServiceProvider::boot()` reads `languages`), and a map that only
rebuilds when the database is up is a map that goes stale. Full documentation:
**`.second-brain/README.md`**.

## Stack

Laravel 13 · PHP ^8.3 · MySQL · Sanctum (mobile API) · `laravel/ui` (Bootstrap auth scaffolding) · Vite 6 · Playwright (browser tests).

## Architecture

**Two surfaces over one codebase**: a Blade admin panel under `/admin`, and a stateless JSON API under `/api/v1` serving a customer app and a driver app. They share the modules, the models and the repositories; they do not share controllers, requests or response conventions. A third, small surface is the public marketing site (see **The public site**).

### Module structure

`app/Modules/{Name}/` — Controllers, Models, Repositories, Services, Requests, and often `Enums`. 31 modules: Address, Banner, City, Complaint, Country, Coupon, Driver, Faq, Intro, Item, ItemCategory, JourneyStep, Laundry, LaundryService, LaundryStaff, LaundryZone, Moderator, Notification, Offer, Order, Payment, Pricing, Rating, Recurrence, Report, Service, Setting, TimeSlot, User, Wallet, Zone.

**A sidebar screen is not a module directory.** There are 31 module dirs and
rather more panel screens than that, and the newer ones live inside an existing
module rather than getting their own — so **do not go looking for `app/Modules/Settlement/`**:

| Screen | Lives in |
| --- | --- |
| `payment`, `driver_earning`, `refund`, `order_settlement`, `commission_rule`, invoices | `app/Modules/Payment/` |
| `dispatch`, `order_task`, `order_today` | `app/Modules/Order/` |
| `laundry_slot_capacity` (intake capacity) | `app/Modules/Laundry/` |
| `driver_application`, `driver_bonus_rule`, `driver_bonus_award` | `app/Modules/Driver/` |
| `finance` («ملخص الماليات»), `report` | `app/Modules/Report/` |
| `role`, `language`, `notification_log` | `app/Http/Controllers/Admin/` + `app/Models/` |

`app/Modules/Payment/` alone backs six panel screens plus the API's payment and
refund controllers — and one controller can back several: `PaymentLedgerController`
serves both `admin.payment.*` and `admin.earning.*`, and `DriverEarning` is a
Payment model despite the name. **Grep for the controller before assuming a
directory**, and note the menu/permission key (`driver_earning`) need not match
either the route name (`admin.earning.index`) or the owning module.

Read **`app/Modules/Offer/`** end to end for the module contract at its cleanest; **`app/Modules/Coupon/`** for a service with real business rules in it.

### The layer contract

Controllers do HTTP only and delegate to the service; **repositories are the only place raw Eloquent queries live**; services own business logic and wrap every write in `DB::transaction`.

Every CRUD service exposes **`shredData($id = null)`** — the universal view-data assembler. It returns the list under a plural key and, when `$id` is given, the single record under **`row`**. Controllers pass its result straight to the view; Blade partials expect `$row`. New modules must follow this or the shared partials/components won't fit.

### The order lifecycle

The domain core. Status table and requests in **`docs/order-lifecycle.md`**
(Arabic) and **`docs/order-cycle-explained.md`**; the full rules in
`docs/architecture.md`.

- **`OrderStateMachine::transition()` is the only way a status changes.** Writing
  `$order->status` directly skips validation and leaves `order_status_logs`
  short — the customer app's tracking screen is built from that log.
- `OrderStatus::allowedNext()` is the single transition table; `isCancellable()`
  derives from it. Cancelling stops at `picked_up`. **`returned` is not
  `cancelled`**: the delivery fee is still owed.
- A driver's work is **four legs** (`TaskType`: `pickup_from_customer`,
  `deliver_to_laundry`, `collect_from_laundry`, `deliver_to_customer`), each with
  `start` / `verify` (the QR scan) / `complete` / `fail`. Verify stays separate
  from complete. `TaskType::startsInto()` / `completesInto()` decide which leg
  moves the order.
- `TaskStatus` has six cases, and **`cancelled` is not `failed`**: a failure
  counts an attempt and returns the leg to the queue with `driver_id` nulled; a
  cancellation keeps its driver. **An order that stops closes its open legs**
  (`OrderStateMachine::standDownTasks()`, same transaction); completed legs stay.
- **Nothing leaves the laundry before its price is agreed**
  (`OrderTask::orderAllows()`: `collect_from_laundry` waits for `cleaning`).
  Confirming the price with the pieces already at the laundry moves the order to
  `cleaning` (`OrderReviewService::confirm()`); both sides lock the order row.
- **Every count of an order's pieces is checked** (`Order/Services/PieceCheck`):
  a disagreement is one `PieceDiscrepancy` row, **nothing is ever refused**, and
  only the platform closes it. The driver app is not shown an expected count
  before it could copy it (`DriverTaskController::countsRevealed()`).
- `cleaning`, `ready_for_delivery`, `completed` and `returned` have **no endpoint
  driving them** — a known gap, not something to paper over.

### What a driver may change about themselves

`POST /api/v1/driver/profile` **writes none of** the vehicle, the licence or the
six documents: they are staged in `driver_record_submissions` and apply when
somebody approves them (`Driver/Services/DriverRecordReview`, screen
`admin/driver-record-submission`, `driver_record_submission.*`). Name, email and
photo apply at once. Zones are refused outright. The dashboard writes directly.
One pending row per driver; the diff is computed at review time; a rejection
requires a note; `GET /driver/profile` carries `pending_review`.

### Which laundry gets the order

`Order/Services/LaundryAssigner` decides, at placement and on every reassign.

- **Three filters, never bypassed by any path:** active, has claimed the pickup
  address's zone (`laundry_zones`), offers the service (`laundry_services`).
  Then **the nearest by road wins, unless a nearly-as-close one has more room**
  (`Balance_Tolerance_Km`, measured from the nearest, never pairwise).
  `evaluate()` is the whole decision; the order screen renders it.
- **A zone is drawn on the map** (`zones.boundary`, `<x-zone-drawer>`).
  `Zone/Services/ZoneLocator` decides an address's zone from its pin; drawings
  may not overlap (`Support/Geo/Polygon`, ~10 cm tolerance). Laundries and
  drivers are still matched by `zone_id`. The place search (`<x-map-search>`) is
  OpenStreetMap Nominatim **by the owner's choice — do not switch to Google
  without asking**; test Arabic queries from a UTF-8 file, never the Windows
  shell.
- **Distance is the road** (`app/Services/Routing/`, Google's Routes API, matched
  on `destinationIndex`). The key is the `Google_Maps_Key` setting. **A fallback
  is never cached.** `phpunit.xml` pins `ROUTING_DRIVER=haversine`.
- **Capacity is per laundry per window** (`laundry_slot_capacities`; blank =
  uncapped, `0` = closed), gated on `laundry_slot_capacity.update`, not
  `laundry.update`. `time_slots.capacity` is a different thing (vans).
- **The delivery leaves the service its time** (`Order/Services/Turnaround`,
  the one source of the check and its words); `Delivery_Window_Days` is the far
  end, for new bookings only.
- **Nothing refuses an order at checkout**: `Slot_Overflow_Behavior` decides.
  **Automatic assignment can be switched off** (`AutoAssign`); an automatic path
  goes through `DriverDispatcher::automatically()`, never `dispatch()`, and an
  order with no laundry is never dispatched automatically.

### Money: who owns which share

The full account is long and every rule in it was set by an incident — read
`docs/architecture.md` before touching settlement, coupons, prices or cash.

- **The total is composed in one place**, `OrderPricing::compose()`; the tax rate
  is copied onto the order at placement and never re-read.
- **Four-way split:** tax → the state; delivery fee + cash surcharge → the
  platform, which pays the driver from them; **cleaning revenue**
  (`Order::cleaningRevenue()`) is the only part platform and laundry divide.
- **A laundry's percentage is what the *laundry* receives** (reversed
  2026-09-27). `commission_amount` is still the platform's part.
- **A coupon's bearer** (`coupons.discount_laundry_share`) and **its scope**
  (`scope_type` / `scope_ids`) are copied onto the order at placement. The
  platform's part can be negative (`TransactionReason::DiscountFunded`, the only
  overdraft). A code on some pieces never discounts the delivery fee.
- **A catalogue-wide price rise** is `Pricing/Services/PriceIncrease`, stamped
  on the order as `price_increase_rate`; a permanent one can be undone.
- **Cash at the door is a payment.** The delivery leg on an unpaid order requires
  `collected_amount` (0 allowed, never above `payableTotal()`); anything above 0
  is a captured `cash` payment with `collected_by`, «with the driver» until
  `Payment/Services/CashCustody::receive()` (up to `up_to`, `payment.update`,
  refused inside a laundry). A cash payment refunds to the wallet only. **No
  `payment_method` is filed as cash, but the cash fee is charged only when cash
  was chosen.** Revenue is still read off the orders (`RevenueReport`).
- A `pending` settlement at `Confirmed`; money moves at `Completed`
  (`OrderStateMachine::settleMoney()`); `Cancelled`/`Returned` cancel both.

Rules that are easy to break:

- **The commission basis is `cleaningRevenue()`, not `preTaxTotal()`**
  (`SettlementService::basisFor()`).
- **No share rule → `Laundry_Share_Rate`; with neither, nothing is divided** and
  `settleFor()` refuses. `Commission_Rate` is the *customer's* fee. No driver
  bonus rule → no bonus. Inactive counts as absent.
- **One active share per laundry.** Pending recomputes; settled/approved is
  frozen. **Nothing pays on a schedule** — approval is a human act.
- **Money permissions are `setting.update`**, never `laundry.update` /
  `driver.update`, which the payee holds.
- `per_order` bonuses pay only on `DeliverToCustomer`; tiers pay the highest
  reached; the laundry's share is rounded and the platform's taken by
  subtraction.

| Class | Role |
| --- | --- |
| `Payment/Services/SettlementService` | resolves rules, computes the split, moves money |
| `Payment/Models/OrderSettlement` (+ `Line`) | one order's division — `pending\|settled\|cancelled` |
| `Payment/Models/CommissionRule` | a laundry's share — percent only, one active per laundry |
| `Payment/Services/EarningService` + `Models/DriverEarning` | per-leg driver bonus — `pending\|released\|cancelled` |
| `Payment/Services/CashCustody` | cash with drivers, and receiving it |
| `Driver/Services/BonusResolver` | which rule a driver is on, what one leg pays |
| `Driver/Services/MonthlyBonusService` | measures a month, applies gates, picks a tier |
| `Driver/Models/DriverBonusAward` | one driver-month — `due\|approved\|rejected` |
| `Support/PlatformAccount` | the platform's wallet = the **oldest super admin** |

### The home page counts; «ملخص الماليات» has the money

**No money on the home page** — it has no permission, so every panel account
opens it. `DashboardSummary` counts; `Report/Services/FinanceSummary` holds the
money on `admin.finance.index` behind **`finance.view`** (carrier
`Report/Models/Finance`; deliberately not `report.view`, which every laundry
owner holds). Every revenue figure is `RevenueReport`'s. The charts are
`admin/partials/_viz` (ApexCharts, already on every page): validated colour roles
as CSS variables, every tooltip string through `esc()`, data as
`<script type="application/json">@json($var)</script>` (a variable, never an
array literal — Blade drops the escaping flags), and the numbers always in text.

### Tenant scoping (laundry owners share the panel)

Two cooperating pieces, no middleware and no repository filtering:

- **`app/Support/LaundryContext.php`** — `currentId()` returns `null`, meaning *no
  restriction*, for console/queue/seeders, for `super_admin`, and for any actor
  whose `users.laundry_id` is null (moderators, customers). Otherwise it returns
  that id. **That null is the entire super-admin bypass.**
- **`app/Trait/BelongsToLaundry.php`** — a global scope filtering a
  *table-qualified* `laundry_id` (so it survives joins), plus a `creating` hook
  that **overwrites** `laundry_id` so a forged payload cannot plant a row in
  another tenant.

Scoped: `Order`, `OrderRating`, `LaundryService`, `LaundryZone`, `LaundryStaff`,
`OrderSettlement`. `Laundry` is scoped by its own `own_laundry` scope on `id`.
**Deliberately unscoped**: the global catalogue (Item, ItemCategory, Service,
Pricing, Zone, Setting, Coupon), `User`, `Payment`, and **`OrderTask` — driver
work carries no `laundry_id` at all**, so it is reached through the scoped
`Order` or withheld *by permission*. Adding a `laundry_id` model without the
trait leaks across tenants; `withoutGlobalScopes()` is legitimate in dozens of
places and is exactly the line that gets copy-pasted into a tenant-facing query.
An action a laundry must never take even when granted the permission refuses
inside the service (`LaundryContext::currentId() !== null`), not only at the
route. `TenancyIsolationTest` and `tests/Browser/tenancy.spec.js` guard this.

### Notifications and the queue

A service calls a notifier → **`NotificationDispatcher::send()`**, on both
channels (`database`, `push`).

- **Business actions are synchronous, on purpose**; push failures are swallowed
  and logged. **One exception:** `app/Jobs/SendManualNotification.php`, the
  hand-written broadcast — **one job per recipient, never per chunk**. The worker
  is a per-minute cron entry (`queue:work --stop-when-empty` under `flock`).
  **Do not reach for a job for anything else.**
- **Only `push` is mutable**; `database` is the record. **One account, one list**
  (`User::notifications()` pins the type across `Driver`/`Moderator`/
  `LaundryStaff`). A user with no registered handset is pushed nothing —
  `notification_logs.failure_reason` says why.
- **A notification's `url` is a path, never `route()`** (`NotificationUrlTest`).
- **FCM `data` is a map**: set only when non-empty, string values; **400 is not
  permanent** (only 403/404 delete a handset).

### Excel export and import

`app/Support/Spreadsheet/`: one engine, one `Sheet` class per screen in
`Sheets/`, one controller (`Admin\SpreadsheetController`), one component
(`<x-spreadsheet-actions …/>`). Export is the screen's own scoped query (no
`orderBy` or join — it is walked with `lazyById`). Import builds **the screen's
own FormRequest** per row, saves good rows and reports bad ones, never deletes,
and is refused inside a laundry. Money and operations sheets are export-only.
**Every string is written as a text cell** — never `Row::fromValues()`, which
turns `=…` into a live formula.

### The activity log — who changed what

`App\Services\ActivityLogger`, fed by wildcard Eloquent events: **a bulk write
that loads no model is invisible**, so route a change a person makes through a
model. A logging failure never fails the change. Secrets are recorded as changed,
never by value (`config/activity.php`: `redacted`, `redacted_setting_keys` — add
a new credential there). It is worded for the owner by `ActivityPresenter`:
**add a noun to `activity.nouns` (and id columns to `activity.references`) when
you add a model.** The order screen's history is an **allow-list**
(`OrderHistory::OPEN` / `GATED`), not a way round other permissions.

### List pages: server render + AJAX search

Every list module has both `index` and `search` routes:

- `index` returns the full view, or `response($view)` when `$request->ajax()`.
- `search` (AJAX only) returns JSON `{table, pagination}` by rendering `admin/{module}/partials/_{module}_table_body.blade.php`.

The client half — **`setupAjaxSearch({inputSelector, tableBodySelector, paginationWrapperSelector, url, colspan, errorHtml, extraParams})`** and the `.toggle-status` click handler — is defined **inline in `resources/views/layouts/footer_script.blade.php`**, not in `public/assets/js/custom/`. Index views wire it up in a `@push('scripts')` block.

- It binds **`keyup`**: drive it from Playwright with `page.type()`, never `page.fill()`.
- **`extraParams`** keeps a filtered screen's dropdown across a search and a page change. Pass a *function*, so the value is read at request time.
- The term is sent as **`query`** and read as `$request->get('query')` — **never `$request->query`**, Symfony's ParameterBag.
- **`Searchable` (`app/Trait/Scopes/Searchable.php`) reaches across relations** (dotted paths, `$expressions`). **Anything a list screen displays is searchable** (the owner's rule): add a new column to the model's search list too. Aggregates and `humanDate()` columns are excluded.

### Two search patterns — picking the wrong one blanks data

`setupAjaxSearch` re-renders rows **from the server**. On a screen that is one
big bulk-edit form — the price grid, the roles permission matrix — the cells the
partial does not render come back empty and **are blanked on save**. Those
screens use **`setupClientFilter({…})`** (same file), which hides rows with
`style.display` and fetches nothing. `ClientFilterWiringTest` guards the wiring.

### Stack lists, not tables

Newer list screens are card rows, not `<table>`: declare `$stackCols` once in the view (in a `@php … @endphp` block — see `tasks/lessons.md`) and inject it as `--stack-cols` on **both** the `.stack-head` strip and the `.data-stack` container, one `<span>` per row `<div>`. These pass `errorHtml` to `setupAjaxSearch` and **no `colspan`**. Copy `resources/views/admin/offer/` rather than an older table-based module.

### Multi-language data (the biggest gotcha)

Translatable columns hold JSON `{"en":"…","ar":"…"}` in a **`text`** column, handled **manually — there is no `$casts` entry**:

- **Write**: the service does `json_encode($request['name'], JSON_UNESCAPED_UNICODE)` before hitting the repository.
- **Read**: the model defines `getNameAttribute($v) => json_decode((string) $v)` — a **`stdClass`, not an array**. So it's `$row->name->en`, never `$row->name['en']`.
- Models override `asJson()` to keep `JSON_UNESCAPED_UNICODE`.
- Form inputs are **arrays keyed by language code**; requests validate `'name' => 'required|array'` plus `'name.*'`. **At least one language, not all**: reading falls back preferred → default → any non-empty (`pickTranslation()`).
- In Blade use `getLocalizedValueDashboard($model, 'name')` (default language) or `getLocalizedValue()` (request locale from the `lang` header).
- A new translatable field touches four places: migration, `$fillable`, the `getXAttribute` accessor, and the `json_encode` in the service.
- `languages.default` and `languages.is_rtl` are **enum strings `'true'`/`'false'`** — `where('default', 'true')`.

Translation files: `resources/lang/{code}.json` (the panel's own),
`{code}_panel.json`, `{code}_mobile.json`, `{code}_web.json`. The public site's
copy is the Web File, read by `webText()`; add its keys with
`php artisan laundo:sync-web-lang` — **never `LanguageHelper::generateJsonLanguageFiles()`**,
which tips English into `ar.json` and reddens `TranslationCoverageTest`.
Validation messages are overridden from the panel into
`storage/app/lang/{code}_validation.json`; **no validation text may be printed
unescaped**. Every validated field needs an Arabic name in
`resources/lang/ar/validation.php` `attributes` (`ValidationLanguageTest`).

### Permissions

Slugs are `{model}.{action}`, actions fixed at **view, create, update, delete,
toggle**. They are **generated**: `PermissionSeeder` runs `PermissionGenerator`
over `config/dashboard.php`'s `models`, keeping classes that use
**`App\Trait\DashboardModel`**; it never prunes.

| Where | How |
| --- | --- |
| Routes | `middleware('permission:category.view')` (`CheckPermission`) |
| Blade | `canDo('category.create')` helper |
| Sidebar | `MenuBuilder` derives visible items from the user's `*.view` permissions |

All three bypass for `role.slug === 'super_admin'`. `EnsureDashboardRole`
(`dashboard.only`) gates `/admin` on `role.type` **`dashboard` or `laundry`**
(`User::canReachPanel()`); `app` (customers, drivers) stays out. A laundry owner
is confined by permissions and the tenant scope. Laundries sign in at
`/laundry/login`, apply at `/laundry/register` (**pending is a null
`approved_at`, never a third status**), and change their services only through a
platform-approved `LaundryServiceRequest`. Sign-in requires `status = active`.
A driver application is a lead, not an account. `driver_supervisor` carries no
money permission.

### Sidebar

`config/menu.php` drives it: `groups` (dropdowns with an order), `singles`,
`permissions` (a key borrowing another model's `.view`, e.g. `order_today` →
`order.view`), and three parallel maps `icons` / `titles` / `routes`. **A new
screen needs a group item (or a single) and an entry in all three maps**, or it
renders with nulls. Menu keys are not always module names. Badges come from
`App\Services\MenuBadges::for()`: **only work waiting on a person, and zero
draws nothing** (`MenuBadgeTest`).

### Routing

Admin routes in `routes/web.php`, prefixed `/admin`, mostly `admin.{module}.{action}`. URIs are kebab-case while route names and menu keys are snake_case (`/admin/journey-step` → `admin.journey_step.index`). Real exceptions worth knowing before calling `route()`:

- `home`, `change-password.index`, `change-password.update`, `language.set-current` — **no `admin.` prefix**
- roles are **plural**: `admin.roles.index`, `admin.roles.permissions.update`
- settings: `admin.generalSetting.viewGeneralSetting` / `updateGeneralSetting` / `viewPrivacyAndTerms` / `updatePrivacyAndTerms`
- status toggle: `POST /{module}/status/{id}` named `admin.{module}.toggleStatus`
- delete routes are named `.delete` while the controller method is `destroy`, because `x-action-buttons` calls `route("$routePrefix.delete", $id)`

### Status fields

`status` is the **string `'active'`/`'inactive'`**, not a boolean. `toggleStatus` returns JSON `{success, status}` and is rendered via:

```blade
<x-status-toggle-button :id="$row->id" :status="$row->status"
    endpoint="{{ route('admin.category.toggleStatus', $row->id) }}" permission="category.toggle" />
```

`permission` takes a **literal string — no leading `:`**. Writing `:permission="category.toggle"` makes Blade evaluate it as PHP and 500s the whole page as soon as the table has one row.

### The public site

A marketing page at `/`, `/ar`, `/en`, plus `/{locale}/terms` and
`/{locale}/privacy`; the login form is at `/login`. A signed-in user is shown
the page. **It does not extend `layouts.main` and must load no admin asset**
(`layouts/landing`: `landing.css` + deferred `landing.js`; `LandingPageTest` and
`landing.spec.js` assert it). `landing.css` re-declares the design tokens
(`LandingTokenParityTest`). **All copy is Web File-driven.**
`LandingContentService::pageData()` is cached with `filemtime()` of the service
in its key — keep the stamp. It never shows development data (`realSetting()`),
withholds an offer's badge for a test coupon, and markets **cash on delivery
only**. `LandingController` reads route parameters off the request.

### The API layer

`routes/api.php`, a hundred-odd endpoints under `/api/v1`, controllers in `app/Http/Controllers/Api/V1/`, requests in `app/Http/Requests/Api/V1/`. `php artisan route:list --path=api/v1` is the count; `docs/postman/generate-reference.py` prints it on every run.

- **Responses** go through `app/Helpers/ApiResponse.php` — `successReturnData()`, `successReturnCreated()`, `successReturnPaginated()`. The envelope is `key`, `status`, `msg`, `code` plus `data`/`errors`/`meta`. **`status` is derived from the code by `apiResponseStatus()` — never pass it in**; `key` says *which* outcome. The panel's `ResponseService` is a different thing; don't mix them.
- **`successReturnPaginated($items, $paginator = null, $msg = '')`** — items first, paginator second; the wrong way round is a silent failure (`ApiContractTest`).
- **Auth** is Sanctum on the `api` guard, one `users` table for both apps. Customer tokens are named `mobile`, driver tokens `driver-app`.
- **Driver endpoints are not gated by middleware.** Each driver controller resolves `Driver::find($request->user()->id)` and does `abort_unless($driver !== null, 403, …)` itself, which is what stops a customer's token (a plain `User`) operating them. Adding a driver endpoint means repeating that.
- **`$request->user()` is not the same class in both apps.** A driver's token is minted on **`Driver`**, a customer's on `User`. Anything keyed on the caller's class (`getMorphClass()`, a morph relation) splits one account in two.
- **Named rate limiters** beyond `api`: `otp`, `otp-verify`, `login`, `location` (60/minute — the driver reports every four seconds), `tracking`.
- **`ComplaintCategory` has two sets**: `offeredTo($audience)` (the picker) and the wider `acceptedFrom($audience)` (submit). `POST /complaints` serves both apps; a named order resolves through `ComplaintService::orderTheyCanName()`.
- `GET /orders/{id}/driver-location` is the moving marker's own endpoint; `config/tracking.php` holds the freshness window and poll cadence — **the window comes down only after the apps report faster**. `?audience=driver` on `/app-settings` swaps in the driver's support lines.
- Controllers keep a private `present*()` method per payload shape; a field added to a summary must be eager-loaded in the matching `index()` (query-count tests).
- Domain vocabulary lives in **PHP enums** under `app/Modules/{Name}/Enums/`. Prefer them over string literals.
- Password reset is **two steps** (`verify-reset-code` → single-use ticket → `reset-password`, `app/Services/Auth/PasswordResetTicket.php`). Never accept code + new password in one call.
- Cross-field rules shared between requests go in `app/Http/Requests/Api/V1/Concerns/`.

### Money, phones and dates

- `appCurrency()` / `moneyFormat()` — Arabic renders **Western digits**.
- `phoneRegex()` is **E.164**; stored numbers are normalised to it.
- **One discount per order**: `coupon_code` and `offer_id` are exclusive, the offer wins, a code beside it is refused with a message.
- **Timestamps are stored UTC and shifted only at render** (`humanDate()`; `isoDate()` for API payloads). Two other legitimate conversions: `DriverTaskController::applyDay()` (filtering by the driver's day) and **`TimeSlot/Services/SlotClock`**, the one place a window becomes an instant — every leg's `due_at`, the arrival estimate, and «can today's window still be booked» (closes `Slot_Booking_Cutoff_Minutes` before its end).

### Helpers (`app/Helpers/`, auto-loaded via composer `files`)

`Helpers.php` (~44 functions) — `uploadOrUpdateImage($file, $dir, $existing = null)` (validates extension + 5MB cap, deletes the old file, returns the stored path, or returns `$existing` when `$file` is null), `DeleteImage()`, `getImageDashboardUrl()` (returns **raw HTML**, use `{!! !!}`), `canDo()`, `getLocalizedValue*()`, `getDefaultLanguage()`, `humanDate()`, `isoDate()`, `displayTimezone()`, `moneyFormat()`, `appCurrency()`, `phoneRegex()`, `getSettingValue()`, `realSetting()` / `isPlaceholderSetting()`, `assetVersion()`, `brandLogo()` / `brandPlaceholder()`, `webText()`, `panelIsRtl()`. **Grep before adding one** — duplicates get written by accident.

`LanguageHelper.php` — generates `resources/lang/{code}{,_panel,_mobile,_web}.json` from the `storage/app/{panel,mobile,web}File.php` templates. `ApiResponse.php` — the API envelope.

### Caching (fragmented — check both systems)

- `CachingService` — keys from `config('constants.CACHE')` (`languages`, `settings`), 1-hour TTL; feeds the topbar language switcher.
- `Helpers.php` — `rememberForever` on `all_languages`, `available_locales`, `default_language`, `languages_without_default`, `language_{code}`, `lang_file_{code}_{type}`, and the settings reads.

`clearLanguageCache($code)` clears the **Helpers** set only, so clear both when editing languages. Tests call `Cache::flush()` in `setUp` for this reason.

## Testing

Around eighteen hundred PHPUnit tests (1,877 on 2026-10-04, data providers
included), currently green. Treat a failure as a regression, not a flaky stub.

- About 130 PHP test files, mostly in `tests/Feature/Dashboard/` and
  `tests/Feature/Api/`, plus a couple of dozen Playwright specs in
  **`tests/Browser/`** — capital B, which `playwright.config.js` points at.
- **The browser suite is not isolated.** It drives the real dashboard against
  the **development MySQL database** and its fixtures, so it reads far more than
  it writes and cleans up or clearly labels anything it creates. It runs
  `workers: 1`, `fullyParallel: false` on purpose, and boots its own server
  (`php artisan serve --port=8800`; override with `APP_TEST_URL`).
- **There are no factories.** Build rows with `Model::create()`, or the builders on `tests/TestCase.php`: `seedCore()`, `seedGeo()`, `seedCatalog()`, `cover()`, `addressFor()`, `grant()`, `superAdmin()`, `customer()`, `driverUser()`, `laundryWithOwner()`, `apiHeaders()`.
- **`seedCore()` is mandatory** in `setUp` — the locale helpers throw without a default language row.
- Idiom: `Tests\TestCase`, `RefreshDatabase`, `#[Test]` attributes (not `test_` prefixes), `Cache::flush()` in `setUp`, and a private `tr()` helper that json-encodes translations with `JSON_UNESCAPED_UNICODE`.
- Columns guarded against mass assignment (e.g. `redemptions_count`) need `forceFill()` after create.

## Docs

`docs/` is maintained by hand and drifts if you don't. The full list, with what
each file is for, is in `docs/architecture.md` (**Docs**). The ones a change
usually touches:

- **`docs/postman/Laundo API v1.postman_collection.json`** (114 requests in six
  caller-grouped folders) and **`docs/postman/generate-reference.py`** →
  `docs/api-reference.html`, whose endpoint list is hand-written Python. Check
  request *bodies* when a field changes.
- **`docs/laundo-qa-guide.html` + `.pdf`** — the Arabic QA guide. The HTML is the
  source; the PDF is rendered from it:

  ```bash
  "/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu \
    --virtual-time-budget=20000 --run-all-compositor-stages-before-draw --print-to-pdf-no-header \
    --print-to-pdf="D:\nahr\in-house\laundo\docs\laundo-qa-guide.pdf" \
    "file:///D:/nahr/in-house/laundo/docs/laundo-qa-guide.html"
  ```

- **`docs/mobile-{date}-{topic}.md`** — the send-as-it-is note for an app team,
  one per release they must act on, plus an append to `docs/mobile-api-changes.md`.
  Keep its **الحالة** line current until it is sent; **once the owner says it
  has been sent, delete it** — the running log keeps the summary and git history
  the text (the notes of 2026-09-20 → 10-01 are at `b403985`).
  `docs/driver-app-backend-answers.md` is a dated record: mark an overtaken
  answer in its banner rather than editing it.
- **`docs/qc-{date}-release.html` + `.pdf`** — the Arabic note for QC, one per
  deploy, with a numbered «جرّب / المفروض يحصل / ✓» table per change.

## Deploying

The live site is **laundo.nahrdev.net**, behind Cloudflare; the app lives at
`~/laundo.nahrdev.net` on the box and MySQL is up there, so `php artisan`
works. How to reach it is not written here.

1. **The tree must be clean before the pull.** `resources/lang/*.json` are
   tracked *and* written at runtime by the language editors, so an operator's
   edit leaves the tree dirty and `git pull` refuses. Back `resources/lang` up
   first, then merge — never `checkout` or `reset --hard` those files.
2. **Dump the database first when a migration rewrites data**, and check the
   result against the dump afterwards. A data migration that reads config
   (the display timezone) needs `config:clear` **before** `migrate`.
3. `php artisan down` → `git pull --ff-only origin main` →
   `composer install --no-dev --optimize-autoloader` (when `composer.lock`
   moved) → `php artisan migrate --force` →
   `php artisan db:seed --class=PermissionSeeder --force` (when
   `config/dashboard.php` gained a model) → `config:clear`, **`route:clear`**,
   `view:clear`, `cache:clear` → `php artisan up`. `route:clear` is not
   optional: with a stale route cache a new route fails as
   `Route [admin.x] not defined` *inside a view that did deploy*.
4. Anything Vite builds (`resources/js|css`, `vite.config.*`, `package*.json`)
   needs `npm ci --maxsockets 3 && npm run build` on the host — it caps file
   descriptors at 150 and a plain `npm ci` fails with EMFILE.
5. No `queue:restart`: the worker is a per-minute cron entry, a fresh process
   each run. Hand-edited assets referenced through `assetVersion()` bust
   themselves; anything else needs a hard refresh.
6. Verify with `php artisan route:list --name=…`, a `tinker --execute` on real
   rows, and `storage/logs/laravel-YYYY-MM-DD.log` (`LOG_STACK` is `daily`
   there — grep the dated file, not `laravel.log`).
   `git rev-parse HEAD` alone proves nothing about the caches.

## Known rough edges

Don't "fix" these blind. The reasons are in `docs/architecture.md`.

- **Cloudflare (Flexible SSL): `trustProxies()` and the prepended
  `ResolveCloudflareScheme` are load-bearing** — without them every AJAX call
  breaks on mixed content and the rate limiters share one bucket. Never replace
  them with `URL::forceScheme('https')` (`TrustedProxyTest`).
- **Seven translatable columns are `json`, not `text`** (`cities`, `zones`,
  `services`, `items`, `item_categories`, `laundries`, `coupons` — `name`). On
  production MariaDB they compare case-sensitively; `Searchable` folds case with
  `LOWER(CAST(… AS CHAR))` — **do not simplify it back** (`ListSearchTest`).
- Every module's `search()` returns **null** unless the request is AJAX; tests need `X-Requested-With: XMLHttpRequest`.
- `Banner` and `Intro` model **classes are lowercase**. `CachingService::getSystemSettings()` is dead code.
- Settings are key/value rows with **PascalCase keys**. **The settings screen is four tabs on one form**; a switch setting needs the hidden-`0` input before its checkbox. `About` / `Privacy_Policy` / `Terms` hold **HTML** authored in TinyMCE (already loaded on every page) and are printed unescaped; `assets/js/pages/ckeditor.js` draws nothing.
- **No Arabic webfont in the panel** (Nunito only); the landing page self-hosts IBM Plex Sans Arabic.
- The **`App_Name` setting still says `BaseCode`** — the owner's call (an invoice may need a legal name).
- Seven images are still placeholders pending export from Figma.
- **OTP is the fixed code `123456`** until an SMS provider exists (`OTP_STATIC_CODE`).
- **The business clock is Cairo**: `config('app.display_timezone')` defaults to `Africa/Cairo` and the `SetTimezone` middleware sets it per request. `phpunit.xml` pins UTC, but a feature test that sends a request gets Cairo — assert instants against `displayTimezone()`. «Today» in the home page and the reports is still a UTC day.
- `public/storage` must be the **symlink**, not a real directory.

## Frontend

Views are Blade under `resources/views/admin/{module}/` (with `partials/`, `forms/`, `shared/` subfolders), extending **`layouts.main`**.

**Styling is a static vendor admin template, not a build pipeline.** CSS/JS come from `public/assets/**` via `asset()` calls in `layouts/include.blade.php` and `layouts/footer_script.blade.php` — Bootstrap 5, jQuery, Font Awesome, bootstrap-icons, select2, sweetalert2, toastify, filepond, bootstrap-table, leaflet, ApexCharts, TinyMCE. RTL swaps to `assets/css/main/rtl.css` based on the session language. Project overrides go in `public/assets/css/theme.css` and `custom.css`; the vendor `main/app.css` often out-specifies them, so **match its selector specificity instead of relying on load order** — and before changing a property, grep for *every* rule that sets it.

**Bootstrap's display utilities beat the `hidden` attribute** (`d-block` etc. are `!important`). Give a toggled element its own class with a `[hidden] { display: none }` rule.

**Hand-edited panel assets must be cache-busted by hand**: reference `theme.css` and `custom.js` through **`assetVersion('css/theme.css')`** (path relative to `assets/`; with the prefix it falls back to a constant and busts nothing).

Vite/Tailwind are near-unused but **not dead**: `@vite` appears only in `layouts/app.blade.php`, which seven auth views extend, so **`npm run build` is required before deploying** or `/password/reset` 500s with a missing manifest. Don't route new styles through Vite unless you're deliberately migrating.

### Dashboard forms keep what you typed

`public/assets/js/custom/form-validation.js` binds to **`form.needs-validation`**,
posts in the background, and paints a 422's messages beside each field. No
controller needs changing — Laravel already answers a JSON request with
`422 {message, errors}`. The small action forms (approve, toggle, delete)
deliberately do **not** carry the class.

## Naming Conventions

| Element | Convention | Example |
| --- | --- | --- |
| Service class + file | camelCase | `offerCrudService.php` |
| Route URI / route name | kebab-case URI, snake_case name | `/admin/journey-step` → `admin.journey_step.index` |
| Permission slugs | `{model}.{action}` | `offer.delete` |
| Table body partial | `partials/_{module}_table_body.blade.php` | `_offer_table_body.blade.php` |
| JSON translation columns | `{"en":…,"ar":…}` in `text`, `stdClass` on read | `name`, `description` |
| Cache keys | `config/constants.php` + literals in `Helpers.php` | `CACHE.LANGUAGE` |

## Adding a New Module

First decide whether it *is* a module: a screen that belongs to an existing
domain goes inside that module (see the table under **Module structure**), and
only a genuinely new domain earns a directory.

1. `app/Modules/{Name}/` — Controller, Model (+ `App\Trait\DashboardModel` and `App\Trait\Scopes\Searchable`), Repository, `{name}CrudService.php` (with `shredData()`), Request (branch rules on `$this->getMethod() === 'PUT'`: required on create, nullable on update), plus `Enums/` if it has a closed vocabulary.
2. Migration (`status` enum `active|inactive`, translatable columns as `text` — **not `json`**), then `php artisan migrate`. Add `laundry_id` + `use BelongsToLaundry` if a laundry owner must only see their own rows.
3. Register the model class in `config/dashboard.php` so `PermissionSeeder` generates its five permissions, then `php artisan db:seed --class=PermissionSeeder`.
4. Route group in `routes/web.php` with `permission:` middleware on each action, including `search` and `status`. **Money terms gate on `setting.update`**, not the module's own `update`.
5. `config/menu.php`: add the key to a group's **`items`** array (or to the `singles` map with an order number) **plus** `icons`, `titles`, `routes`.
6. Views under `resources/views/admin/{name}/` — `index` (with `setupAjaxSearch` in `@push('scripts')`, or `setupClientFilter` if it is a bulk-edit grid), `create`, `edit`, `show`, `partials/_{name}_table_body`, `forms/formInput`, `shared/controlBut`.
7. Add every displayed column to the model's searchable list, including dotted relation paths.
8. Add a noun to `activity.nouns` and any id columns to `activity.references`, and an Arabic name for every validated field.
9. If it is exposed to the apps: endpoint in `routes/api.php`, a `present*()` method, an entry in the Postman collection **and** in `generate-reference.py`.
10. `php .second-brain/bin/brain.php update`.

## Changelog Policy (MANDATORY)

A `Changelog.md` file must exist at the root of the repository. If it does not exist, create it before completing the task.

After completing ANY task (feature, fix, refactor, migration, configuration change, performance improvement, etc.) you MUST update `Changelog.md`. Failure to update the Changelog is considered an incomplete task.

### Update Rules

1. Append changes under the current date (`YYYY-MM-DD`).
2. Do NOT delete or modify previous entries.
3. Do NOT rewrite history.
4. If an entry for the current date already exists, append under it.
5. Keep entries concise but clearly descriptive.
6. Specify the affected layer when relevant (Service, Repository, Blade, Database, Infrastructure, etc.).

### Entry Categories

Use only the sections that apply: `### Feature`, `### Fix`, `### Refactor`, `### Improvement`, `### Migration`.

### Required Format

    # Changelog

    ## YYYY-MM-DD

    ### Feature
    - Added new endpoint for managing committees (API / Service).

    ### Fix
    - Fixed null reference in UsersService when filtering by status.

Never leave the changelog empty after a task, never batch unrelated days into one date, and keep it chronologically ordered.

## How to work here

- **Plan first.** For any non-trivial task (three or more steps, or an
  architectural choice), write the plan to `tasks/todo.md` as checkable items
  and check in before implementing. Tick items as you go, and add a review
  section when done. If something goes sideways, stop and re-plan.
- **Learn from corrections.** After any correction from the user, record the
  pattern in `tasks/lessons.md`, and read it at the start of a session.
- **Done means proven.** Run the suite — and drive the actual page or endpoint —
  before calling anything done; then **§7**.
- **Bug reports:** find the cause from the logs and the failing tests and fix
  it, without asking for hand-holding.
- **Root causes, minimal impact.** The simplest change that fixes the cause;
  touch only what is necessary. Use subagents for wide research so the main
  context stays clean.

### §7 Review before it ships (MANDATORY)

**Run `/code-review` and `/security-review` on the diff when the work is
finished and the suite is green — and before it is committed, pushed or
deployed.** Both, not one: the first looks for what the change got wrong, the
second for what it exposed, and they do not find the same things.

«Would a staff engineer approve this?» is answered by the head that wrote the
code, which has exactly the blind spots that produced it. A test proves what was
thought of; a review is for what was not.

**Always, no judgement call:**

- permissions, roles, or anything gating a screen or an endpoint
- anything that accepts input from either app — a new field counts
- file uploads, and anywhere a stored path is written
- money: settlement, commission, earnings, refunds, wallet, cash
- the tenant scope, or a model gaining `laundry_id`
- who receives a notification
- a new endpoint, or an existing one taking a new parameter

**Not worth it:** copy, a translation, a comment, a changelog line, a rename
with no behaviour behind it.

**Findings are fixed before the deploy, not filed after it.** On this project
«later» means the finding ships first.

## Data Safety

When cleaning up probe or test rows in the **development database**, delete by the primary key you got back from the insert. A `where(...)->like(...)` cleanup once deleted a real governorate alongside the probe city it was aimed at. If a delete reports removing more rows than you created, that is the warning — stop and restore. Put restore steps in a `finally`, so a script that throws midway does not leave a live setting blank.
