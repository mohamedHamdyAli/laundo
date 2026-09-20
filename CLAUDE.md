# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
```

Single test: `php artisan test --filter=TestName` · one file: `php artisan test tests/Feature/Api/OrderTest.php` · one suite: `php artisan test --testsuite=Unit`. One browser spec: `npx playwright test tests/Browser/<name>.spec.js`.

The full PHPUnit suite takes **about eight minutes**. That is long enough to
plan around: use `--filter` while iterating and run the whole thing once, before
you call the task done. It has grown steadily — if it feels much longer than
this, re-measure and correct the figure here rather than working around it.

PHPUnit runs against in-memory SQLite (`phpunit.xml`); the app itself runs on MySQL. Anything relying on MySQL-only SQL will pass in tests and fail in the app.

## Stack

Laravel 13 · PHP ^8.3 · MySQL · Sanctum (mobile API) · `laravel/ui` (Bootstrap auth scaffolding) · Vite 6 · Playwright (browser tests).

## Architecture

**Two surfaces over one codebase**: a Blade admin panel under `/admin`, and a stateless JSON API under `/api/v1` serving a customer app and a driver app. They share the modules, the models and the repositories; they do not share controllers, requests or response conventions.

### Module structure

`app/Modules/{Name}/` — Controllers, Models, Repositories, Services, Requests, and often `Enums`. 31 modules: Address, Banner, City, Complaint, Country, Coupon, Driver, Faq, Intro, Item, ItemCategory, JourneyStep, Laundry, LaundryService, LaundryStaff, LaundryZone, Moderator, Notification, Offer, Order, Payment, Pricing, Rating, Recurrence, Report, Service, Setting, TimeSlot, User, Wallet, Zone.

**A sidebar screen is not a module directory.** There are 31 module dirs and 42
panel screens, and the newer ones live inside an existing module rather than
getting their own — so **do not go looking for `app/Modules/Settlement/`**:

| Screen | Lives in |
| --- | --- |
| `payment`, `driver_earning`, `refund`, `order_settlement`, `commission_rule`, invoices | `app/Modules/Payment/` |
| `dispatch`, `order_task` | `app/Modules/Order/` |
| `laundry_slot_capacity` (intake capacity) | `app/Modules/Laundry/` |
| `driver_application`, `driver_bonus_rule`, `driver_bonus_award` | `app/Modules/Driver/` |
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

The domain core. Full reference in **`docs/order-lifecycle.md`** (Arabic, written
from the code) and **`docs/order-cycle-explained.md`**; the rules that bind code:

- **`OrderStateMachine::transition()` is the only way a status changes.** Writing
  `$order->status` directly skips validation *and* leaves `order_status_logs`
  short — and the customer app's tracking screen is built from that log, not from
  the current status, so a hand-moved order renders with missing steps.
- `OrderStatus::allowedNext()` is the single transition table; `isCancellable()`
  is derived from it, so no endpoint can permit a cancel the table forbids.
  Cancelling stops at `picked_up` — once we hold the pieces the order runs out.
- **`returned` is not `cancelled`**: the pieces come back but the delivery fee is
  still owed, and collapsing the two loses that.
- A driver's work is **four legs** owned by `TaskType` (`pickup_from_customer`,
  `deliver_to_laundry`, `collect_from_laundry`, `deliver_to_customer`), each with
  `start` / `verify` / `complete` / `fail`. `verify` (the QR scan) is separate
  from `complete` on purpose — merging them meant a failed photo upload threw
  away a good scan. `TaskType::startsInto()` / `completesInto()` decide which leg
  moves the *order*; the others move only the task.
- A leg's own status is `TaskStatus`, and it has **six** cases:
  `pending` · `assigned` · `started` · `completed` · `failed` · `cancelled`.
  **`cancelled` is not `failed`.** A failure is a driver who went and could not
  do it: it counts an attempt, feeds the monthly bonus gates, and returns the leg
  to the queue with `driver_id` nulled. A cancellation is a leg nobody is going
  to drive because the order stopped — so it keeps its `driver_id`, which is what
  leaves it in that driver's history where «ملغاة» can explain where the job went.
- **An order that stops closes its open legs**, in `OrderStateMachine::standDownTasks()`
  and in the same transaction as the status change. Completed legs are left alone:
  on a `returned` order the pieces really were collected and really did reach the
  laundry, and that is work the driver is owed for. Before this, cancelling moved
  the money and left every leg exactly where it was — the holder kept a task they
  could not finish, and an unassigned one sat on the dispatch board as work
  waiting for somebody who was never coming.
- `cleaning`, `ready_for_delivery`, `completed` and `returned` currently have
  **no endpoint driving them** — a known gap, not something to paper over.

### Which laundry gets the order

`Order/Services/LaundryAssigner` decides, at placement and again whenever an
operator reassigns. Three filters then two rules, and the filters are not
negotiable:

1. **active**, 2. **has claimed the pickup address's zone** (`laundry_zones`),
3. **offers the requested service** (`laundry_services`, active). Nothing that
fails these is ever chosen, by any path, including the panel's picker.

Then, among what is left: **the nearest by road wins, unless a nearly-as-close
one has more room.**

- **Distance is the road, through `app/Services/Routing/`.** It was a straight
  line for the life of the project, which is the wrong answer on any map with a
  river in it. `RoutingService` resolves the driver, caches per
  origin/destination pair and falls back; `Router` is the contract,
  `HaversineRouter` the local arithmetic and the fallback,
  `GoogleDistanceMatrixRouter` the real measurement. **`phpunit.xml` pins
  `ROUTING_DRIVER=haversine`** so the suite runs offline and a fee a test
  asserts is arithmetic.
- **It is the Routes API**, `routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix`
  — the legacy `maps.googleapis.com/maps/api/distancematrix` endpoint **cannot
  be enabled on a recently created Google project at all**. Its elements come
  back **unordered**, carrying their own `destinationIndex`, so results are
  matched on that and never on array position.
- **The key is a settings row, `Google_Maps_Key`**, so it can be rotated from
  the panel. `config('routing.google.key')` wins when set. It is deliberately
  not in the seeder.
- **A fallback is never cached.** Caching a straight line for the 24-hour TTL
  turns a brief outage into a day of wrong distances and — since the fee
  measures the road too — a day of undercharged deliveries that nothing flags.
- **`Balance_Tolerance_Km`** (default **0**, which is the old behaviour exactly):
  everything within that distance **of the nearest** forms one group, and inside
  it the laundry with the **most free places** in the customer's pickup window
  takes the order. Measured from the nearest and never pairwise — a chain each
  within the tolerance of the last would let an order drift arbitrarily far.
- **Free places, not fewest orders.** Four of twenty beats none of two.

**Capacity is per laundry per window** — `laundry_slot_capacities`, edited from
`admin/laundry-slot-capacity` and from a tab on the laundry's edit screen, both
through one `sync()`. Blank is uncapped, `0` is «closed for this window», and
they are different answers. It counts the **pickup** leg only: it is about
washing machines, where `time_slots.capacity` is about vans and counts both
legs across the whole platform. The two do not constrain each other.

**Nothing here ever refuses an order at checkout.** A zone whose laundries are
all full is the same shape of problem as a zone nothing covers, and
`SlotOverflowBehavior` (`Slot_Overflow_Behavior`) is where an operator says what
to do: accept it unassigned (default), give it to the nearest anyway, or hide
the window from the customer. The last needs `GET /api/v1/time-slots` to be sent
an `address_id` — until an app sends one it behaves like the first, which the
settings field says on its face.

**Capacity gates on `laundry_slot_capacity.update`, not `laundry.update`** — a
laundry owner holds the latter by design, and capacity decides how much work
they are handed. Same boundary as the commission rate.

`LaundryAssigner::evaluate()` is the whole decision — each candidate's leg, load
and the reason it lost — and `assign()` is that method keeping only the answer.
The order screen renders the rest, so the picker and the router cannot disagree.

### Money: who owns which share

Added after the rest of the panel; `docs/order-cycle-explained.md` covers it in
prose. The total is composed in exactly one place, `OrderPricing::compose()`:
`subtotal − discount + delivery_fee + cash_surcharge = pre_tax_total`, then
`+ tax` (the rate is **copied onto the order at placement and never re-read**).

It splits four ways: **tax** → the state, never divided and never commissioned;
**delivery fee + cash surcharge** → the platform, which pays the driver out of
them; and **cleaning revenue** (`Order::cleaningRevenue()` = subtotal − discount)
is the only part the platform and the laundry divide.

| Class | Role |
| --- | --- |
| `Payment/Services/SettlementService` | resolves rules, computes the split, moves money |
| `Payment/Models/OrderSettlement` (+ `Line`) | one order's division — `pending\|settled\|cancelled` |
| `Payment/Models/CommissionRule` | one platform charge, percent or fixed |
| `Payment/Services/EarningService` + `Models/DriverEarning` | per-leg driver bonus — `pending\|released\|cancelled` |
| `Driver/Services/BonusResolver` | which rule a driver is on, what one leg pays |
| `Driver/Services/MonthlyBonusService` | measures a month, applies gates, picks a tier |
| `Driver/Models/DriverBonusAward` | one driver-month — `due\|approved\|rejected` |
| `Support/PlatformAccount` | the platform's wallet = the **oldest super admin** |

At `Confirmed` a `pending` settlement is written (visible, nothing moved). At
`Completed`, `OrderStateMachine::settleMoney()` releases the driver's bonus and,
in one transaction, credits the platform its commission and the laundry owner the
remainder. `Cancelled`/`Returned` cancels both.

Rules that are easy to break by accident:

- **The commission basis is `cleaningRevenue()`, not `preTaxTotal()`.** Widening
  it re-creates the bug where the platform booked a cut of the delivery fee while
  also paying the driver out of it. See `SettlementService::basisFor()`.
- **A null rule means opposite things on each side.** No commission rule → falls
  back to the `Commission_Rate` setting; a laundry that truly pays nothing needs
  an attached 0% rule. No driver bonus rule → **no bonus at all**. Inactive
  counts as absent on both.
- **Pending recomputes, settled/approved is frozen.** A `pending` settlement and a
  `due` award are rewritten on every recompute; once money moved the row is
  immutable and stores its measurements rather than deriving them.
- **Nothing pays on a schedule.** `drivers:close-bonus-month` (1st, 07:00, last
  month) computes and raises one notification — it *never* approves. Approval is
  a human act behind `driver_bonus_award.update`.
- **Money permissions are `setting.update`, not `laundry.update`/`driver.update`.**
  A laundry owner holds `laundry.update` by design, so gating its commission on
  it would hand the payer the dial. Same boundary for a driver's bonus rule.
- `per_order` bonuses pay only on `DeliverToCustomer` (four legs would pay 4×);
  tiers pay the **highest reached**, never summed; the laundry share is computed
  by subtraction so halves always reconcile.

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
Pricing, Zone, Setting, Coupon), `User`, and **`OrderTask` — driver work carries
no `laundry_id` at all**, so the home page withholds the dispatch and driver-money
panels *by permission* instead. Adding a `laundry_id` model without the trait
leaks across tenants; `withoutGlobalScopes()` is legitimate in dozens of places and is
exactly the line that gets copy-pasted into a tenant-facing query.
`TenancyIsolationTest` and `tests/Browser/tenancy.spec.js` guard this.

### Notifications and the queue

A service calls a notifier (`OrderNotifier`, `DriverApplicationNotifier`,
`LaundryApplicationNotifier`, …) → **`NotificationDispatcher::send()`**. Every
event delivers on **both** channels — `NotificationEvent::channels()` returns
`['database','push']`; SMS is reserved for auth.

- **Business actions are synchronous, and that is deliberate.** An HTTP request
  pays the FCM round-trip inline, because a worker that dies is invisible and a
  delivery that silently did not happen is worse than a slow one. Push failures
  are swallowed and logged so a vendor outage never rolls back the business
  action.
- **One exception, and only one**: `app/Jobs/SendManualNotification.php`, the
  hand-written broadcast from `admin.notification.compose`. An announcement to
  three thousand customers cannot be done inside a request at all — each
  recipient is a round-trip — and the alternative was a cap on how many people
  could be told. **One job per recipient, never one per chunk**: a chunk failing
  at the sixtieth of a hundred would, on retry, reach the first fifty-nine a
  second time, and a duplicate notification cannot be taken back. Below
  `push.manual_inline_limit` (5) it still sends inline and reports «sent»; above
  it the flash says «sending», because telling somebody a message has gone while
  it sits on a queue is how a stopped worker becomes invisible.
- **The worker is a cron entry, not a supervisor daemon.** The box runs
  `queue:work --stop-when-empty --max-time=55` every minute under `flock -n`
  (lock at `/home/nahrnet/.laundo-queue.lock`), which needs no root
  and cannot leave a dead process behind — a stopped daemon looks exactly like an
  empty queue. `QUEUE_CONNECTION` is `database`; `composer dev` runs
  `queue:listen` locally. Worst-case latency on a broadcast is a minute, which is
  a minute nobody is waiting on.
- **Do not reach for a job for anything else.** If a new feature seems to need
  one, the question to answer first is what happens when the worker is behind,
  and for every other path in this codebase the answer is «the business action
  silently did not happen».
- **Only `push` is mutable** (`MUTABLE_CHANNELS`). `database` is a record, not a
  delivery: muting it emptied the in-app list *and* froze the rate-limit counter
  at zero, which handed the muted user unlimited push. Absent preference = on.
- Rate limit `config('push.rate_limit_per_hour', 3)` per subject, counted off the
  `database` rows; `isTransactional()` events bypass both the cap and the mute.
- **The FCM gotcha**: `data` is a *map* in FCM v1 and PHP's empty array encodes as
  `[]`, so Google 400'd every data-less message — and the driver read 400 as
  "device gone", so the dispatcher deleted every handset it notified. `data` is
  now set only when non-empty with string values, and **400 is no longer
  treated as permanent** (only 403/404). Don't reintroduce it.

### List pages: server render + AJAX search

Every list module has both `index` and `search` routes:

- `index` returns the full view, or `response($view)` when `$request->ajax()`.
- `search` (AJAX only) returns JSON `{table, pagination}` by rendering `admin/{module}/partials/_{module}_table_body.blade.php`.

The client half — **`setupAjaxSearch({inputSelector, tableBodySelector, paginationWrapperSelector, url, colspan, errorHtml, extraParams})`** and the `.toggle-status` click handler — is defined **inline in `resources/views/layouts/footer_script.blade.php`**, not in `public/assets/js/custom/`. Index views wire it up in a `@push('scripts')` block (`layouts/main.blade.php` renders `@stack('scripts')`).

It binds **`keyup`**. Driving it from a test with Playwright's `page.fill()` sets the value without firing that event and the search looks broken — use `page.type()`.

**`extraParams`** (object or function) is how the twelve filtered screens keep
their dropdown selected across a search and a page change — orders, payments,
wallet, settlement, refund, complaint, rating, recurrence, notification,
dispatch, earning, driver_bonus. Pass a *function* so the value is read at
request time rather than at wire-up time.

The term is sent as **`query`**, and a `search()` action must read it as
`$request->get('query')` — **never `$request->query`**, which is Symfony's
public ParameterBag property and hands you the bag, not the term.

**`Searchable` (`app/Trait/Scopes/Searchable.php`) reaches across relations.**
Columns may be dotted paths (`profile.vehicle_type`, `laundry.city.name`),
resolved with `whereHas`, plus an `$expressions` list for a cell no column holds.
The rule the owner set is that **anything a list screen displays is searchable**
— so when you add a column to a table body, add it to the model's search list
too. Aggregates and `humanDate()` columns are deliberately excluded.

### Two search patterns — picking the wrong one blanks data

`setupAjaxSearch` re-renders rows **from the server**. On a screen that is one
big bulk-edit form — the price grid, the roles permission matrix — the cells the
partial does not render come back empty and **are blanked on save**.

Those screens use **`setupClientFilter({inputSelector, itemSelector, groupSelector, siblingHeadingSelector, emptySelector, countSelector})`** instead (same file): it
hides non-matching rows with `style.display`, fetches nothing, and leaves every
input in the DOM so a save still posts the whole grid. `ClientFilterWiringTest`
guards which screens are wired to which.

### Stack lists, not tables

Newer list screens are card rows, not `<table>`: declare `$stackCols` once in the view and inject it as `--stack-cols` on **both** the `.stack-head` strip and the `.data-stack` container, one `<span>` per row `<div>`. These pass `errorHtml` to `setupAjaxSearch` and **no `colspan`**. Copy `resources/views/admin/offer/` rather than an older table-based module.

### Multi-language data (the biggest gotcha)

Translatable columns hold JSON `{"en":"…","ar":"…"}` in a **`text`** column, handled **manually — there is no `$casts` entry**:

- **Write**: the service does `json_encode($request['name'], JSON_UNESCAPED_UNICODE)` before hitting the repository.
- **Read**: the model defines `getNameAttribute($v) => json_decode((string) $v)` — returning a **`stdClass`, not an array**. So it's `$row->name->en`, never `$row->name['en']`.
- Models also override `asJson()` to keep `JSON_UNESCAPED_UNICODE` (otherwise Arabic is stored as `\uXXXX`).
- Form inputs are **arrays keyed by language code**; requests validate `'name' => 'required|array'` plus `'name.*'`.
- In Blade use `getLocalizedValueDashboard($model, 'name')` (default language) or `getLocalizedValue()` (request locale from the `lang` header).

**At least one language, not all of them.** Requests validate that *some* language was filled rather than requiring every one — a client writing Arabic-only copy is normal. Reading falls back preferred → default → any non-empty via `pickTranslation()`, using `filled()` so a whitespace-only value doesn't win. When relaxing this on a form, remove the client-side `required` too, or the browser still blocks submit.

Adding a translatable field means touching four places: migration, `$fillable`, the `getXAttribute` accessor, and the `json_encode` in the service.

`languages.default` and `languages.is_rtl` are **enum string `'true'`/`'false'`**, not booleans — `where('default', 'true')`.

**The Web File — where the public site's copy lives.** Four JSON files per
language: `{code}.json` (the panel's own, ~1,800 hand-authored entries),
`{code}_panel.json`, `{code}_mobile.json` and `{code}_web.json`.

```
storage/app/webFile.php         the key list + English defaults (~190 keys)
   -> php artisan laundo:sync-web-lang    merges new keys into every language
resources/lang/{code}_web.json  edited from the languages row's dropdown
   -> webText('landing.hero.title')       locale -> default -> template -> key
```

`webText()` is the reader. Its last-but-one rung is the template, which is what
makes adding a key safe: it renders its English default everywhere immediately
and each language overrides it when somebody translates it. The dashboard's
`updateWeb()` runs `array_filter()`, so blanking a value there *deletes* the key
and the template default takes over — which is the behaviour you want from a
"reset this string" box.

**Never run `LanguageHelper::generateJsonLanguageFiles()` to add web keys to an
existing language.** It merges panel + mobile + web into `{code}.json` too, and
`TranslationCoverageTest::no_arabic_value_is_left_in_english` fails the build on
any `ar.json` value holding no Arabic — so it would tip a hundred English
marketing strings into the panel's translation and redden the suite. That is why
`laundo:sync-web-lang` exists and writes `{code}_web.json` **only**; a test
asserts the six other files are byte-identical after a sync.

`GET /api/v1/translations/web` serves the same file to the apps. The landing
page is its second consumer, not a replacement.

**Two editors, one file.** «تعديل محتوى الصفحة التعريفية»
(`admin.language.landing`) groups the `landing.*` keys by section in page order
with readable headings and the shipped default as each placeholder — that is the
one to use for marketing copy. «Edit Web Json» is the flat key/value list, still
right for the app-override keys it was built for. Both write
`{code}_web.json`; the landing screen can only write the `landing.` namespace,
so it cannot touch the ten keys the apps read.

All four editors hang off `admin/language/shared/controlBut`. They used to hang
off `x-action-button-lang`, **which nothing renders** — so `admin.language.panel`,
`.mobile` and `.web` were reachable only by typing the URL. Do not "tidy" that
partial back to the component: its action trio is ungated and the language
list's actions go through `canDo()`.

### Permissions

Slugs are `{model}.{action}` with actions fixed at **view, create, update, delete, toggle** (`PermissionGenerator::$actions`).

Permissions are **generated, not hand-listed**: `PermissionSeeder` runs `PermissionGenerator`, which walks `config/dashboard.php`'s `models` array, keeps only classes using the **`App\Trait\DashboardModel`** trait, and derives the slug from `Str::snake(class_basename())`. A new model gets permissions only after it is added to `config/dashboard.php` **and** uses that trait. The generator only ever creates — it never prunes, so a removed model leaves its permissions behind.

Three enforcement points, all bypassing checks for `role.slug === 'super_admin'`:

| Where | How |
| --- | --- |
| Routes | `middleware('permission:category.view')` (`CheckPermission`) |
| Blade | `canDo('category.create')` helper |
| Sidebar | `MenuBuilder` derives visible items from the user's `*.view` permissions |

`EnsureDashboardRole` (`dashboard.only`) additionally gates all `/admin` routes on `role.type`, which must be **`dashboard` or `laundry`** — not `dashboard` alone. A laundry owner and their staff sign in to the same panel and are confined by their permission set and by the tenant scope, not by the gate; `app` (customers, drivers) stays locked out. System roles/permissions are flagged `is_system = true` and should not be deleted.

Laundries therefore have a **second front door**: `GET /laundry/login`, whose form posts to the same `login` route — one authentication path, so throttling, the session and the `/admin/home` redirect cannot drift. `/login` still works for them; the separate page exists because it was headed «Admin Control Panel» and nothing told an owner the account they were handed belonged there. `auth/passwords/{email,reset}` and the admin login extend **`layouts.auth`** — the shell lifted out of the old `login.blade.php`, deliberately *not* `layouts.app`, which is the panel's only Vite chain.

The **laundry** pages (`/laundry/login`, `/laundry/register`, `/laundry/applied`) extend **`layouts.auth-card`** instead: a card on a navy ground, loading `landing.css` + `auth-card.css` and nothing else. They read as a continuation of the marketing site the applicant arrived from, and they get IBM Plex Sans Arabic — the panel's own shell still has no Arabic webfont. `class="landing"` on `<html>` is load-bearing there: landing.css scopes its dark tokens and its 100% root font size to it.

Laundries can also **apply for themselves**: `GET /laundry/register` files the laundry `inactive` with `approved_at` null and the owner `inactive`, and `admin/laundry/pending` is where an operator approves or rejects. Approval flips both halves *and every staff account on the laundry* — turning on one of two is a half-open door. **Pending is a null `approved_at`, never a third `status` value**: `status` is the binary the toggle button drives and a dozen queries filter on, and a pending laundry is simply `inactive`, which they already exclude. A laundry the panel creates is stamped approved on the spot, because an operator creating one *is* the approval.

**Sign-in is gated on `status = active`** (`LoginController::credentials()`). It was not, for the whole life of the panel — `AuthenticatesUsers` matches email and password and nothing else, so any inactive account signed straight in while the API refused it. A pending laundry gets a «still being reviewed» message rather than the generic failure; everyone else gets the generic one, so the form cannot be used to discover which addresses hold accounts.

A locked-out owner is given a new password from the **laundry edit screen** (`owner_password`, blank means unchanged) — `Laundry::owner()` is the relation that finds them. Note it is a plain constrained `hasOne` ordered by id: `latestOfMany()` builds its aggregate subquery *without* the constraints declared before it, so on a laundry that also has staff it picks the newest staff row and the role filter then discards it, returning null.

**Drivers apply too, but differently.** `POST /drivers/apply` (public, no GET —
the form is on the landing page) files a `DriverApplication` row, which is a
*lead*, not an account: the operator reads it on `admin/driver-application`,
marks it handled (`toggleHandled`), and creates the driver by hand. A laundry
application creates real inactive rows; a driver application does not. Don't
unify the two paths without knowing that.

### Sidebar

**Queue counts.** `App\Services\MenuBadges::for($model)` returns a number or
null, and `MenuBuilder` hangs it on every item; a closed dropdown carries the
sum of its children. Two rules, both tested in `MenuBadgeTest`: **only work
waiting on a person** (never a row count — a badge beside Zones reading 25
teaches an operator to stop reading the ones that matter), and **zero draws
nothing**. It is a class and not a config entry because a closure in
`config/menu.php` does not survive `config:cache`, and it is uncached because a
stale badge on a queue reads as "nothing waiting" to somebody who then does not
look.

`config/menu.php` drives everything. `MenuBuilder` intersects its keys with the
user's `*.view` permissions. Four top-level keys:

- **`groups`** — the dropdowns, each `{order, title, icon, items: [model keys]}`.
  Eight of them: `locations`(1), `catalog`(2), `laundries`(3), `delivery`(4),
  `marketing`(6), `operations`(8), `money`(9), `system`(99).
- **`singles`** — a `model => order` **map** (`user`:5, `order`:7, `report`:10),
  interleaved with the groups by that number.
- **`icons` / `titles` / `routes`** — three parallel maps keyed by model name,
  one entry each per screen (42 today, matching `groups` + `singles` exactly).
  A key present in two of the three renders with a null in the third, so the
  three counts agreeing is the cheap check that a new module is fully wired.

A new module needs an entry in a group's `items` (or in `singles`) **and** in all
three UI maps, or it renders with nulls. **Menu keys are not always the module
name**: `order_task`, `driver_earning`, `order_settlement`, `order_rating`,
`order_recurrence`, `item_price` and `notification_log` are menu/permission keys
whose code lives in a differently-named module.

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

`permission` takes a **literal string — no leading `:`**. Writing `:permission="category.toggle"` makes Blade evaluate it as PHP and 500s the whole page as soon as the table has one row (this has already been fixed once across five modules).

### The public site

A third surface, added after the panel and the API: a marketing page at `/`,
`/ar` and `/en`, plus `/{locale}/terms` and `/{locale}/privacy`. `/` used to
return `view('auth.login')`; the login form is at `/login`, which
`Auth::routes()` has always registered. A signed-in user hitting bare `/` still
redirects to `/admin/home` — `/ar` does not, because that is an explicit request
for a language.

**It does not extend `layouts.main`.** That chain loads ~22 stylesheets and ~30
scripts (`app.css` 399 KB, `theme.css` 93 KB, `bootstrap-icons.woff2` 110 KB,
ApexCharts, TinyMCE, select2, FilePond, jsTree, jQuery UI). `layouts/landing`
loads `landing.css` and a deferred `landing.js` and nothing else; icons are
inline SVG. `LandingPageTest` and `landing.spec.js` both assert none of the
admin assets is requested — **do not add one to this layout.**

- `landing.css` **re-declares the design tokens** rather than importing
  `theme.css`. `theme.css` stays canonical, and `tests/Unit/LandingTokenParityTest.php`
  fails the build if a shared token's value drifts. Change a brand colour there
  and this file has to follow.
- `html.landing { font-size: 100% }` undoes the panel's `87.5%` density zoom, so
  landing CSS is authored against a 16px root. Only tokens are shared, never
  layout classes.
- Logical properties throughout (`margin-inline`, `inset-inline-start`), so one
  stylesheet serves both directions. `rtl.css` is **not** loaded.
- **Arabic type**: IBM Plex Sans Arabic, self-hosted, two weights, Arabic subset
  only. Latin stays Nunito and `unicode-range` routes each glyph. Before this
  the project shipped **no Arabic webfont at all** — `--bs-body-font-family:
  Nunito` with no fallback stack.
- `landing.css` / `landing.js` are fingerprinted with `filemtime()` via
  `landingAssetVersion()`. They have no build step, so nothing else busts a
  visitor's cache after a deploy.

**All marketing copy is Web File-driven** — see the localization section below.
Nothing user-facing is hardcoded in a landing Blade file.

`LandingContentService::pageData()` is cached for an hour, and its **cache key
carries `filemtime()` of the service**. That is not decoration: adding a key to
the payload without it leaves the previous array in place and the view dies on
an undefined variable — a 500 on the front page for up to an hour after the
deploy, looking like a bad release rather than a stale cache. Keep the stamp if
you change the assembler.

`journey_steps` and live `offers` render from their own dashboard screens. An
offer's badge is withheld when the linked coupon `looksLikeTestCode()`, because
`Offer::badge()` publishes the coupon's discount and this install's only offer
links to `SMOKE10`.

`LandingContentService` supplies the facts (services, prices, coverage, windows,
the timeline) and enforces two rules that are easy to undo by accident:

- **No development data on a public page.** Every settings read goes through
  `realSetting()` / `isPlaceholderSetting()`, which refuse the values
  `SettingsSeeder` leaves behind — `App_Name = BaseCode`, `nahrPhpTeam@…`, the
  seven social URLs pointing at their networks' front pages, the lorem-ipsum
  `About`. Laundries are never listed (two rows, both fixtures) and **offers are
  not rendered at all**, because the only one links to coupon `SMOKE10` and
  `Offer::badge()` publishes the linked coupon's discount.
- **An empty table is a missing section, not a broken one.** `faqs`, `intros`,
  `banners` and `order_ratings` hold zero rows; the FAQ falls back to Web File
  copy and takes over from it when rows appear.

Also deliberate, and worth knowing before "fixing" it: the page markets **cash
on delivery only**. Card, wallet and InstaPay are `PaymentMethod` cases the app
draws and the only gateway in the codebase is `FakeGateway`.

`LandingController` reads route parameters off the request rather than as
arguments — Laravel binds them **positionally**, and `->defaults()` plus a
`{locale}` segment swaps them. See `tasks/lessons.md`.

Guest language switching is `GET /locale/{code}` (`LocaleController`). This is a
second door on purpose: `admin.language.set-current` sits behind
`['auth','dashboard.only']`, and its URI is hand-written in **14 places across
five Playwright specs** — leave it alone.

### The API layer

`routes/api.php`, 103 endpoints under `/api/v1`, controllers in `app/Http/Controllers/Api/V1/`, requests in `app/Http/Requests/Api/V1/`.

- **Responses** go through `app/Helpers/ApiResponse.php` — `successReturnData()`, `successReturnCreated()`, `successReturnPaginated()`. The envelope is `key`, `status`, `msg`, `code` plus `data`/`errors`/`meta`. **`status` is `success`/`error` derived from the code by `apiResponseStatus()` — never pass it in**, or a call site will eventually disagree with its own HTTP status; `key` is the one that says *which* outcome. The panel's `ResponseService` is a different thing (it `throw`s / returns `never`); don't mix them.
- **`successReturnPaginated($items, $paginator = null, $msg = '')`** — items
  first, paginator second. Getting them the wrong way round is a **silent**
  failure: a paginator has a `__toString()` that renders the Blade pagination
  *view*, so the response came back 200 OK with a page of Bootstrap `<nav>`
  markup in `msg` and an empty `meta`, and nothing in the log. Every call site in
  the API was once written that way. `ApiContractTest` guards it now.
- **Auth** is Sanctum on the `api` guard, one `users` table for both apps. Customer tokens are named `mobile`, driver tokens `driver-app`.
- **Driver endpoints are not gated by middleware.** `$request->user()` returns a plain `User`, so each driver controller resolves the driver record and does `abort_unless($driver !== null, 403, …)` itself. Adding a driver endpoint means repeating that, not adding a middleware.
- **Named rate limiters** beyond `api`: `otp`, `otp-verify`, `login`, `location`. Auth routes carry them individually.
- Controllers keep a private `present*()` method per payload shape (e.g. `OrderController::presentSummary()` vs `presentDetail()`). A field added to a summary must be eager-loaded in the corresponding `index()` or it is an N+1 — there are query-count tests guarding this.
- Domain vocabulary lives in **PHP enums** under `app/Modules/{Name}/Enums/` (`OrderStatus`, `TaskType`, `PaymentMethod`, `PaymentStatus`, `TransactionReason`, …). Prefer these over string literals.
- Password reset is **two steps**, for the panel and both apps: `verify-reset-code` spends the code and issues a single-use ticket, `reset-password` takes the ticket. Shared in `app/Services/Auth/PasswordResetTicket.php`. Never accept code + new password in one call.
- Cross-field rules shared between requests go in `app/Http/Requests/Api/V1/Concerns/` (see `OneDiscountPerOrder`).
- **`POST /complaints` serves both apps, and `order_id` is optional for that reason.** A customer reaches it from an order; a driver reaches it from the account screen with no order in sight. When one *is* named it resolves through `ComplaintService::orderTheyCanName()` — orders the complainant **placed or was given a leg of**. Widening that to any order files a complaint against a stranger's laundry; narrowing it back to `$user->orders()` is the bug it replaced, where a driver naming the job they had just delivered got a 404.

### Money, phones and dates

- `appCurrency()` reads the `Currency` setting, validates `/^[A-Z]{3}$/`, falls back to `EGP`. `moneyFormat($amount, ?string $currency = null)` formats through `NumberFormatter` with a `-u-nu-latn` locale extension, so Arabic renders **Western digits** — Arabic-Indic numerals in prices is a bug, not a locale preference.
- `phoneRegex()` is **E.164** (`/^\+[1-9]\d{7,14}$/`). Stored numbers are normalised to it; don't reintroduce a local-format regex.
- One discount per order: `coupon_code` and `offer_id` are mutually exclusive, the offer wins, and a code sent beside it is **refused with a message** rather than dropped. Enforced at the quote as well as at submit.
- **Timestamps are stored UTC and shifted only at render.** `config('app.timezone')`
  is `UTC`; `displayTimezone()` reads `app.display_timezone` and falls back to it.
  `humanDate()` is where the conversion belongs for anything **rendered** —
  doing it in the application timezone corrupts what gets written back.
  `isoDate()` is the machine-readable counterpart for API payloads. This is also
  why date columns are deliberately not searchable: matching the text somebody
  reads would need a timezone conversion in SQL.
  **There is one other legitimate conversion, and it goes the other way**:
  `DriverTaskController::applyDay()` takes the day the driver picked, resolves it
  in `displayTimezone()` and compares against the UTC range it covers. Filtering
  is not rendering, so `humanDate()` cannot do it — and a `whereDate` on the raw
  column files every hour either side of midnight under the wrong date, which
  looks right in every test written in UTC. Do not simplify it back.

### Helpers (`app/Helpers/`, auto-loaded via composer `files`)

`Helpers.php` (~44 functions) — `uploadOrUpdateImage($file, $dir, $existing = null)` (validates extension + 5MB cap, deletes the old file, returns the stored path, or returns `$existing` when `$file` is null), `DeleteImage()`, `getImageDashboardUrl()` (returns **raw HTML**, use `{!! !!}`), `canDo()`, `getLocalizedValue*()`, `getDefaultLanguage()`, `humanDate()`, `isoDate()`, `displayTimezone()`, `moneyFormat()`, `appCurrency()`, `phoneRegex()`, `getSettingValue()`, `realSetting()` / `isPlaceholderSetting()` (the landing page's dev-data guard), `assetVersion()`, `brandLogo()` / `brandPlaceholder()`, `webText()`, `panelIsRtl()`. **Grep before adding one** — it is large enough that duplicates get written by accident.

`LanguageHelper.php` — generates `resources/lang/{code}{,_panel,_mobile,_web}.json` from the `storage/app/{panel,mobile,web}File.php` templates.

`ApiResponse.php` — the API envelope (above).

### Caching (fragmented — check both systems)

Two overlapping caches exist:

- `CachingService` — keys from `config('constants.CACHE')` (`languages`, `settings`), 1-hour TTL. `getLanguages()` feeds the topbar language switcher via `ViewServiceProvider`'s `layouts.topbar` composer.
- `Helpers.php` — `rememberForever` on `all_languages`, `available_locales`, `default_language`, `languages_without_default`, `language_{code}`, `lang_file_{code}_{type}`, and the settings reads.

`clearLanguageCache($code)` clears the **Helpers** set only — it does **not** touch `config('constants.CACHE.LANGUAGE')`, so the topbar switcher can stay stale for up to an hour after a language change. Clear both when editing languages. Tests call `Cache::flush()` in `setUp` for this reason.

## Testing

Around 1,390 PHPUnit tests and 4,480 assertions, currently green, in roughly
eight minutes. Real coverage exists — treat a failure as a regression, not as a
flaky stub.

- Roughly a hundred PHP test files, the bulk of them in `tests/Feature/Dashboard/`
  and `tests/Feature/Api/`, with `tests/Feature/Landing/`, `tests/Feature/Console/`
  and `tests/Unit/` behind them, plus a couple of dozen Playwright specs in
  **`tests/Browser/`** — capital B, which is what `playwright.config.js` points at
  and what a case-sensitive CI will demand.
- **The browser suite is not isolated.** It drives the real dashboard against the
  **development MySQL database** and the fixtures already in it
  (`DevFixturesSeeder`, `CatalogSeeder`, `GeoSeeder`, `TimeSlotSeeder`), so it
  reads far more than it writes and cleans up or clearly labels anything it
  creates. Keep new specs to that discipline. It runs `workers: 1` and
  `fullyParallel: false` on purpose — status toggles mutate shared rows and
  parallel workers race. The config **boots its own server** (`php artisan serve
  --port=8800`, reusing one already running), so no manual setup is needed;
  override with `APP_TEST_URL`.
- **There are no factories.** `database/factories/` holds only an unused `UserFactory`. Build rows with `Model::create()`, or better with the builders on `tests/TestCase.php`: `seedCore()`, `seedGeo()`, `seedCatalog()`, `cover()`, `addressFor()`, `grant()`, `superAdmin()`, `customer()`, `driverUser()`, `laundryWithOwner()`, `apiHeaders()`.
- **`seedCore()` is mandatory** in `setUp` — the locale helpers throw without a default language row.
- Idiom: `Tests\TestCase`, `RefreshDatabase`, `#[Test]` attributes (not `test_` prefixes), `Cache::flush()` in `setUp`, and a private `tr()` helper that json-encodes translations with `JSON_UNESCAPED_UNICODE`.
- Columns guarded against mass assignment (e.g. `redemptions_count`) need `forceFill()` after create — otherwise the test silently exercises a default row instead of the state it meant to set up.

## Docs

`docs/` is maintained by hand and drifts if you don't:

- `docs/postman/Laundo API v1.postman_collection.json` — 103 requests in 6 caller-grouped folders, one per endpoint, with substantive per-request descriptions. An endpoint diff will not catch a **stale request body**; check the bodies when you add a field.
- `docs/postman/generate-reference.py` → `docs/api-reference.html`. **The endpoint list is hand-written Python inside that script**, not derived from the collection or from `route:list`. Run it from the repo root (it writes a relative path).
- `docs/laundo-screen-actions.html` + `.pdf` — every Figma screen against the route its button calls and the panel page staff act from. The HTML is the source; the PDF is rendered from it with headless Chrome `--print-to-pdf`.
- `docs/laundo-qa-guide.html` + `.pdf` — the QA guide, in Arabic: every panel screen, what must exist before it works, what it feeds in the apps, its permission, and the traps a tester would otherwise file as bugs. Ordered by build order, the same order `config/menu.php` uses. Same HTML-is-the-source rule as above; regenerate the PDF with:

  ```bash
  "/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu \
    --virtual-time-budget=20000 --run-all-compositor-stages-before-draw --print-to-pdf-no-header \
    --print-to-pdf="D:\nahr\in-house\laundo\docs\laundo-qa-guide.pdf" \
    "file:///D:/nahr/in-house/laundo/docs/laundo-qa-guide.html"
  ```

  It states **live facts about this install** (which tables are empty, which settings rows are missing), so re-check those numbers when the seed data changes.
- `docs/order-lifecycle.md` — the status table, which request produces each
  status, and the four driver legs. Written from the code, not from the design.
- `docs/order-cycle-explained.md` + `.html` + `.pdf` — the order cycle in prose,
  including the money split and the two flows that confuse people. Same
  HTML-is-the-source, PDF-rendered-from-it rule.
- `docs/mobile-api-changes.md` and `docs/api-change-notification-preferences.md`
  — running notes handed to the app teams when an endpoint's contract changed.
  Append to these rather than rewriting, and only when a mobile client is
  affected.
- **`docs/mobile-{date}-{topic}.md` is the send-as-it-is note**, one file per
  release an app team has to act on — the same content as the append to the
  running log, but standalone so it can be handed over beside the Postman
  collection without a covering explanation. `mobile-2026-09-20-slots-and-capacity.md`
  and `mobile-2026-09-20-driver-app.md` are the pattern.
- `docs/driver-app-backend-answers.md` — the driver app team's `BACKEND_GAPS.md`
  answered against the code. Worth reading before building anything an app team
  reports as missing: about half that report was already shipping and its own DTO
  said it had chosen not to map it.

## Known rough edges

Don't "fix" these blind, but know they're there:

- **The site runs behind Cloudflare, and `trustProxies()` is load-bearing.**
  Cloudflare terminates TLS and forwards to the origin over plain HTTP, so
  without it Laravel generates `http://` absolute URLs for an `https://` page —
  which browsers block as mixed content, taking out **every AJAX call in the
  panel** (search on all list screens, the notification bell) while leaving the
  log clean and `curl` working. It also makes `$request->ip()` Cloudflare's
  address for every visitor, which silently collapses the `otp`, `otp-verify`,
  `login` and `location` rate limiters into one shared bucket. Configured in
  `bootstrap/app.php` with `at: '*'`; `TrustedProxyTest` guards both halves.
  **`trustProxies()` is not sufficient on its own here**: Cloudflare runs in
  Flexible SSL mode, so `X-Forwarded-Proto` truthfully reports `http` for the
  edge-to-origin hop and the visitor's real scheme arrives only in `CF-Visitor`.
  `ResolveCloudflareScheme` is **prepended** to the global stack to normalise one
  into the other before `TrustProxies` reads it. Do not replace it with
  `URL::forceScheme('https')` — that fixes URL generation and leaves
  `$request->isSecure()`, cookie flags and redirects still believing the request
  is insecure.
- **Seven translatable columns are `json`, not `text`.** `cities.name`,
  `zones.name`, `services.name`, `items.name`, `item_categories.name`,
  `laundries.name` and `coupons.name` — while `banners.name`, `faqs.question`,
  `intros.title`, `journey_steps.title` and `offers.title` are `text` with
  `utf8mb4_unicode_ci` as documented above. Production runs **MariaDB**, where
  `json` is `longtext` with the binary collation **`utf8mb4_bin`**, so `LIKE`
  against it compares case-sensitively — searching `c` on Cities returned
  nothing while `C` returned Cairo. `Searchable` fixes it with
  `LOWER(CAST(col AS CHAR))` on both sides.
  `Searchable::scopeSearch()` now folds case in SQL, so the query layer is
  correct either way — **do not "simplify" it back to a bare `orWhere(...,
  'LIKE', ...)`**. `ListSearchTest` asserts the generated SQL, because the
  behaviour cannot fail on SQLite. Converting the columns would need an `ALTER`
  on seven tables holding live data; the query fix reaches the same end.
- Every module's `search()` action is wrapped in `if ($request->ajax())` and
  returns **null** otherwise, so a bare GET to `/admin/{module}/search` is an
  empty 200. Tests hitting it need `X-Requested-With: XMLHttpRequest`.
- `Banner` and `Intro` model **classes are lowercase** (`class banner`, `class intro`) — match existing usage rather than renaming casually.
- `CachingService::getSystemSettings()` plucks by a `name` column; the `settings` table has `key`. It is currently unreferenced — dead code.
- Settings are key/value rows with **PascalCase keys** (`App_Name`, `App_Logo`, `About`, `Privacy_Policy`, `Terms`, `Country_Id`, `Currency`, `Cash_Surcharge`, `Commission_Rate`); `About`/`Privacy_Policy`/`Terms` hold translatable JSON.
- Those three hold **HTML documents, not strings**, and are printed unescaped.
  They are authored in a TinyMCE box (`setupRichText()` in
  `layouts/footer_script.blade.php`), which is used because TinyMCE 5.10.5 is
  **already loaded on every panel page** — the project's brief is to add no
  vendor bundles. Init is per-textarea because `directionality` differs per box:
  the Arabic document is authored RTL even while the panel is in English.
  `assets/js/pages/ckeditor.js` expects a `ClassicEditor` global nothing defines
  and draws nothing — don't reach for it.
- **No Arabic webfont in the panel.** `--bs-body-font-family: Nunito` has no fallback stack, and the shipped Nunito subsets are latin, latin-ext, cyrillic, cyrillic-ext and vietnamese — so every Arabic *panel* screen renders in whatever font the browser picks. The landing page self-hosts IBM Plex Sans Arabic; the panel has not been migrated.
- The **`App_Name` setting row still says `BaseCode`** while `.env` says `Laundo` — and the setting is the one the apps, the invoice and the login alt text read, via `getSettingValue('App_Name')`. `config('app.name')` is only the browser tab title. Left alone deliberately: an invoice may need a registered legal name, so it is the owner's call.
- Terms, privacy and About now hold **real legal copy**, seeded by
  `LegalContentSeeder` (registered in `DatabaseSeeder`) — no longer the draft
  placeholder text. `LegalSettingsTest` covers the editors.
- Seven images are still placeholders pending export from Figma (3 onboarding illustrations, 3 journey-step icons, 1 offer image).
- **OTP is the fixed code `123456`.** SMS delivery is not integrated (the driver
  is `LogSmsDriver`), so `OtpService` issues one static value and logs a loud
  `[OTP:STATIC-CODE — NOT RANDOM]` warning each time. Set `OTP_STATIC_CODE=` in
  the env to restore random codes once an SMS provider exists.
- **`app.display_timezone` is unset on the deployed box**, so `displayTimezone()`
  falls back to `UTC` and every `humanDate()` in the panel — and the driver app's
  day filter — renders and matches a UTC day while the business runs on Cairo
  time. Consistent, but three hours out at the edges of each day. It is one env
  line to change and it moves rendering everywhere at once, so it is the owner's
  call rather than a tidy-up. Worth knowing before reading a timestamp on
  production and concluding something is wrong.
- `public/storage` must be the **symlink**, not a real directory. If it is a directory, every uploaded file 404s and signed routes 403; fix with `rmdir` then `php artisan storage:link`.

## Frontend

Views are Blade under `resources/views/admin/{module}/` (with `partials/`, `forms/`, `shared/` subfolders), extending **`layouts.main`**.

**Styling is a static vendor admin template, not a build pipeline.** CSS/JS come from `public/assets/**` via `asset()` calls in `layouts/include.blade.php` and `layouts/footer_script.blade.php` — Bootstrap 5, jQuery, Font Awesome, bootstrap-icons, select2, sweetalert2, toastify, filepond, bootstrap-table, leaflet. RTL swaps to `assets/css/main/rtl.css` based on the session language. Project overrides go in `public/assets/css/theme.css` and `custom.css`; the vendor `main/app.css` often out-specifies them, so **match its selector specificity instead of relying on load order** — and before changing a property, grep for *every* rule that sets it, in both override files and the vendor CSS. More than one "fix" here has been a no-op because a second `!important` rule was still winning.

**Hand-edited panel assets must be cache-busted by hand.** `theme.css` and
`custom.js` have no build step and no content hash, and sit behind Cloudflare —
so a release that edits them ships Blade referring to rules the cached files do
not have (this already shipped a full-size splash image across every page).
Reference them through **`assetVersion('css/theme.css')`** — the path is
relative to `assets/`, and passing the prefix makes it look for
`assets/assets/…`, miss, and fall back to `app()->version()`, which is a
constant and busts nothing. It stamps
`filemtime()`. `landingAssetVersion()` is the same function under a narrower
name, kept because four views call it.

Vite/Tailwind are near-unused but **not dead**: `@vite` appears only in `layouts/app.blade.php` — and seven views do extend it (`auth/passwords/*`, `auth/register`, `auth/verify`, `home`, `welcome`). `Auth::routes(['register' => false])` in `routes/web.php` makes `/password/reset` reachable, so **`npm run build` is required before deploying** or that page 500s with a missing Vite manifest. It appears to work locally only because `npm run dev` leaves a gitignored `public/hot` behind. Don't route new styles through Vite unless you're deliberately migrating.

### Dashboard forms keep what you typed

`public/assets/js/custom/form-validation.js` binds to **`form.needs-validation`**
— the class every create and edit form already carried — intercepts the
submit, and posts in the background. On a 422 it paints each message beside its
field and scrolls to the first; on success it follows the controller's redirect.

**No controller was changed and none should need to be**: Laravel already
answers a request that wants JSON with `422 {message, errors}` instead of a
redirect. Before this, a failed validation was a full page load and everything
`old()` cannot carry — every file chosen, both passwords, every select2
selection — was lost.

The small action forms (approve, toggle, delete) deliberately do **not** carry
the class: there is nothing typed in them to lose, and a background submit would
only hide the page they lead to.

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
2. Migration (`status` enum `active|inactive`, translatable columns as `text` — **not `json`**, see the rough edges), then `php artisan migrate`. Add `laundry_id` + `use BelongsToLaundry` if a laundry owner must only see their own rows.
3. Register the model class in `config/dashboard.php` so `PermissionSeeder` generates its five permissions, then `php artisan db:seed --class=PermissionSeeder`.
4. Route group in `routes/web.php` with `permission:` middleware on each action, including `search` and `status`. **Money terms gate on `setting.update`**, not the module's own `update`.
5. `config/menu.php`: add the key to a group's **`items`** array (or to the `singles` map with an order number) **plus** `icons`, `titles`, `routes`.
6. Views under `resources/views/admin/{name}/` — `index` (with `setupAjaxSearch` in `@push('scripts')`, or `setupClientFilter` if it is a bulk-edit grid), `create`, `edit`, `show`, `partials/_{name}_table_body`, `forms/formInput`, `shared/controlBut`.
7. Add every displayed column to the model's searchable list, including dotted relation paths — the owner's rule is that anything shown can be searched.
8. If it is exposed to the apps: endpoint in `routes/api.php`, a `present*()` method, an entry in the Postman collection **and** in `generate-reference.py`.

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

## Workflow Orchestration

### 1. Plan Node Default

- Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions).
- If something goes sideways, STOP and re-plan immediately—don't keep pushing.
- Use plan mode for verification steps, not just building.
- Write detailed specs upfront to reduce ambiguity.

### 2. Subagent Strategy

- Use subagents liberally to keep main context window clean.
- Offload research, exploration, and parallel analysis to subagents.
- For complex problems, throw more compute at it via subagents.
- One **task** per subagent for focused execution.

### 3. Self-Improvement Loop

- After ANY correction from the user: update `tasks/lessons.md` with the pattern.
- Write rules for yourself that prevent the same mistake.
- Ruthlessly iterate on these lessons until mistake rate drops.
- Review lessons at session start for relevant project.

### 4. Verification Before Done

- Never mark a task complete without proving it works.
- Diff behavior between main and your changes when relevant.
- Ask yourself: "Would a staff engineer approve this?"
- Run tests, check logs, demonstrate correctness. The suite is real now — run it, and drive the actual page or endpoint as well.

### 5. Demand Elegance (Balanced)

- For non-trivial changes: pause and ask "is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution."
- Skip this for simple, obvious fixes—don't over-engineer.
- Challenge your own work before presenting it.

### 6. Autonomous Bug Fixing

- When given a bug report: just fix it. Don't ask for hand-holding.
- Point at logs, errors, failing tests—then resolve them.
- Go fix failing CI tests without being told how.

## Task Management

1. **Plan First**: Write plan to `tasks/todo.md` with checkable items.
2. **Verify Plan**: Check in before starting implementation.
3. **Track Progress**: Mark items complete as you go.
4. **Explain Changes**: High-level summary at each step.
5. **Document Results**: Add review section to `tasks/todo.md`.
6. **Capture Lessons**: Update `tasks/lessons.md` after corrections.

## Core Principles

- **Simplicity First**: Make every change as simple as possible. Impact minimal code.
- **No Laziness**: Find root causes. No temporary fixes. Senior developer standards.
- **Minimal Impact**: Changes should only touch what's necessary. Avoid introducing bugs.

## Data Safety

When cleaning up probe or test rows in the **development database**, delete by the primary key you got back from the insert. A `where(...)->like(...)` cleanup once deleted a real governorate alongside the probe city it was aimed at. If a delete reports removing more rows than you created, that is the warning — stop and restore. Put restore steps in a `finally`, so a script that throws midway does not leave a live setting blank.
