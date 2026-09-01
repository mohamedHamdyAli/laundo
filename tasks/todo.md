# Dashboard Overhaul — Execution Checklist

Plan reference: see conversation / `sharded-humming-sifakis.md` plan file for full rationale.

## Workstream A — Role name uniqueness fix (DONE)
- [x] Create `app/Http/Requests/RoleRequest.php` (unique name + slug-collision check)
- [x] Update `RoleController::store()` to use `RoleRequest`
- [x] Add try/catch around `Role::create()` for race-condition defense
- [x] New migration: unique constraint on `roles.name`
- [x] Test: duplicate name caught by validation (verified via tinker), not 500 page

## Workstream B — Moderator feature (DONE)
- [x] `app/Modules/Moderator/Models/Moderator.php` (extends User, `$table='users'`, global scope, `use DashboardModel` directly since `class_uses()` isn't inherited)
- [x] Register Moderator in `config/dashboard.php`, run permission seeder (moderator.view/create/update/delete/toggle generated)
- [x] `ModeratorRequest` (validation incl. Kuwaiti phone regex, role_id restricted to type=dashboard/non-super_admin)
- [x] `ModeratorRepository` / `moderatorCrudService`
- [x] `ModeratorController` (index/search/create/store/show/edit/update/destroy/toggleStatus)
- [x] Routes in `routes/web.php` with `permission:moderator.*` middleware
- [x] Sidebar entry in `config/menu.php`
- [x] Views: index, create, edit, show, forms/formInput, partials/_moderator_table_body, shared/controlBut
- [x] Test: create Role w/ limited permissions -> assign to Moderator -> confirmed gating works (tinker) + all 4 views render without error

## Workstream C — Notifications (DONE)
- [x] `notifications` table migration
- [x] `app/Notifications/AdminNotification.php` (no ShouldQueue)
- [x] Trigger in `ModeratorController::store()` -> notify super_admin + admin roles
- [x] `NotificationController` (unread + mark-read + mark-all-read endpoints)
- [x] Routes for notification endpoints
- [x] Topbar bell UI in `layouts/topbar.blade.php`
- [x] `public/assets/js/custom/notifications.js` polling script (15s interval)
- [x] Test: create Moderator via controller -> confirmed both super_admin and admin roles received notification, unread/mark-read endpoints verified via tinker

## Workstream D — Country/timezone setting (DONE)
- [x] Migration: add `timezone` column to `countries`
- [x] Static country->timezone map (`app/Support/CountryTimezones.php`), auto-applied in `CountryRepository` create/update when left blank
- [x] Add `timezone` field to Country create/edit form (editable override)
- [x] `GeneralSettingRequest`: add `Country_Id` rule
- [x] Add Country `<select>` to General Settings form
- [x] New `app/Http/Middleware/SetTimezone.php`, registered in `bootstrap/app.php`
- [x] Test: created Kuwait country (auto-filled Asia/Kuwait), set as Country_Id setting, confirmed middleware sets `config('app.timezone')` + `date_default_timezone_set()` correctly

## Workstream E — Theme/UI overhaul (DONE, pending user visual sign-off)
- [x] Vendor Alpine.js (`public/assets/js/vendor/alpinejs/alpine.min.js`), include in footer_script
- [x] New `public/assets/css/theme.css`, registered in include.blade.php (both branches) + login
- [x] Dark mode toggle button in topbar (wired to existing `#toggle-dark` JS engine in app.js)
- [x] Dark mode CSS overrides (sidebar/topbar/cards/tables/dropdowns)
- [x] Sidebar active-state fix (`request()->routeIs()` prefix match) + navy/gold restyle
- [x] Login page rebuild (split-screen, removed dead demo_mode block, added RTL/dir support)
- [x] Home page: `$dashboardStats` array (Customers/Categories/Banners/Countries), fixed `<a>` nesting via loop
- [x] Home page: removed broken Featured Sections table + dead chart/map script blocks
- [x] Home page: CSS-only 3D hero element (Alpine mousemove tilt) with reduced-motion fallback
- [x] Functional QA: verified via real HTTP requests (curl, logged-in session) that /login, /admin/home, /admin/moderator, /admin/generalSetting, /admin/country/create, /admin/roles all return 200 and contain expected new markup; static assets (theme.css, alpine.min.js, notifications.js) all reachable
- [x] Visual QA round 2 (2026-08-12): fixed sidebar icon/text CSS specificity bug (invisible on navy bg), redesigned scattered hero-3d tiles into a contained grid, rounded table containers to match card corners.
- [x] Visual QA round 3 (2026-08-12, user reported table still square + topbar icons invisible): root cause was that Country/City/Banner/Intro/Category/Language render `<table>` directly in `.card-body` with no `.table-responsive` wrapper, so round 2's table fix never reached them — added a matching `.card-body > table.table` rule. Also fixed the topbar dark-mode toggle + notification bell icons, which weren't wrapped in `.nav-link` so never got a real color.
- [x] Visual QA round 4 (2026-08-12, user reported bell icon sitting lower than its neighbors + dark-mode icon still not standing out): bell had no sized circle backdrop (`.avatar` alone doesn't set height/width/flex-centering), so it sat at its natural inline baseline instead of centered like the dark-mode toggle. Gave it the same 38px circle treatment, and switched both icons to a bolder navy/gold instead of muted gray.
- [x] Visual QA round 5 (2026-08-12, user zoomed in and showed the glyph itself off-center inside the circle even after round 4): root cause is bootstrap-icons' vendor `vertical-align: -0.125em` on every glyph, meant for inline text, fighting the flex `align-items: center`. Zeroed it out for just the dark-mode/bell icons. Select2+SweetAlert2 dark-mode clash still NOT verified, no browser automation tool available in this environment — user should double check that specific combination visually.

## Workstream F — Status-toggle Blade bug (found during 2026-08-12 QA, DONE)
- [x] Found `:permission="x.toggle"` (missing quotes, leading `:` makes Blade eval it as PHP) in Category/City/Banner/Country/Intro table-body partials — crashes list page with 500 once a row exists. Fixed to `permission="x.toggle"` matching the working User/Moderator pattern.
- [x] Verified via live HTTP round trip (login as seeded admin, create → list → edit → toggle → delete) against Category module.

## Final steps (all workstreams)
- [ ] Code review pass
- [ ] Security review pass
- [x] Update `Changelog.md`

---

# Laundo — P0: API Foundation & Breakage Fixes

Plan reference: خطة داشبورد Laundo (12 مرحلة). المرحلة P0 لا تعتمد على شيء وكل ما بعدها يقف عليها.
Status: **DONE 2026-08-25** — approved, implemented, verified against the running app.

## A — API transport layer
- [x] `composer require laravel/sanctum` (verified: v4.3.3 resolves clean on Laravel 13)
- [x] publish sanctum config + migration; run migrate (adds `personal_access_tokens`)
- [x] `HasApiTokens` on `App\Modules\User\Models\User`
- [x] add `api` guard (driver: sanctum, provider: users) to `config/auth.php`
- [x] register api routes in `bootstrap/app.php`: `api: routes/api.php`, `apiPrefix: 'api/v1'`
- [x] create `routes/api.php`
- [x] `RateLimiter` for `api` (60/min per user|ip) + named `otp` limiter reserved for P4

## B — Response + locale contract
- [x] fill `config/constants.php` -> `RESPONSE_CODE` block (unbreaks `ResponseService`)
- [x] extend `app/Helpers/ApiResponse.php`: validation-error shape + paginated shape (keep existing function names — they are global functions)
- [x] new `app/Http/Middleware/ApiLocale.php` -> `app()->setLocale(getCurrentLocale())`, appended to the `api` group only
- [x] do NOT add session-based `SetLocale` to the api group
- [x] JSON exception rendering for `api/*`: 422 validation, 401 auth, 404 model-not-found, 500 generic (details gated on `app.debug`)

## C — Proof endpoints (smoke, not business)
- [x] `GET /api/v1/ping`
- [x] `GET /api/v1/languages` (public) — proves DB + locale + envelope end-to-end

## D — Fix existing breakage
- [x] `routes/web.php:12` — `App\Modules\setting\...` -> `Setting` (class declares capital S; breaks on Linux)
- [x] `userAuth()` / `isAdmin()` / `isEmployee()` — unbroken by the new `api` guard; verify
- [x] generate `resources/lang/ar*.json` via existing `LanguageHelper`
- [x] `.env` `APP_NAME=Laravel` -> `Laundo`
- [x] delete stale `PROJECT_DOCUMENTATION.md` (Clean-X doc) + `CLAUDE.md.bak`
- [x] DO NOT rename `DB_DATABASE=templete` — live DB with data, needs a real migration

## E — Verification
- [x] `php artisan route:list --path=api`
- [x] curl both endpoints: 200 + envelope shape, with and without `lang: ar`
- [x] curl with bogus Bearer token -> 401 JSON envelope, NOT an HTML redirect
- [x] dashboard regression: log in, walk Category list -> create -> toggle -> delete
- [x] `composer stan`, `./vendor/bin/pint`, `composer test`
- [x] update `Changelog.md`

## Decisions taken (defaults from the plan's recommendations)
- [x] response envelope: **kept** the existing `key/data/msg/code` shape, extended not replaced — but the HTTP status now matches the body `code` (it was always 200 before)
- [x] api prefix: **`/api/v1`**
- [x] `composer audit` advisories: **deferred** to a separate task, dependency bumps can carry breaking changes

## Follow-ups this phase surfaced (NOT done, need a decision)
- [x] RESOLVED in P1: `composer stan` is now at **0 errors**. Was 6 pre-existing. Remaining: unimported `Auth`/`Str` facades in `Helpers.php:435` + `MenuBuilder.php:49` (work at runtime via Laravel's class aliases), missing relation return-type hints on `Role::permissions` / `User::role` / `Moderator::role`, and a generic-type issue in `ResponseService.php:54`. All are ~5-minute fixes; the relation hints touch models P1 builds on heavily.
- [ ] `tests/Feature/ExampleTest` fails on `no such table: settings` — **verified failing on pristine HEAD too** (stub test lacks `RefreshDatabase`, and `SetTimezone` queries `settings`). Pre-existing, not caused by P0.
- [x] RESOLVED: the 33-advisory figure was a measurement artifact of a half-installed vendor. Four audit modes report zero.
- [x] Arabic default: **no** — `en` stays default (decided 2026-08-26).
- [ ] `.env DB_DATABASE=templete` left untouched (live DB with data). Rename needs a real data migration.
- [ ] `AppServiceProvider::boot()` runs `Schema::hasTable('languages')` + `Language::all()` **twice** on every request, including API requests that render no view. Pre-existing duplicate block.

---

# Laundo — P1: Multi-Tenancy & Roles

Plan reference: خطة داشبورد Laundo, phase P1. Depends on P0.
Status: **DONE 2026-08-26** — approved, implemented, isolation verified against the running app.

Security-critical: every later phase stores `laundry_id`. If isolation is wrong here,
it is wrong everywhere, and retrofitting means rewriting every repository.

## 0 — Prerequisite: clear the phpstan noise P1 builds on
- [x] add return type hints to `User::role()`, `Moderator::role()`, `Role::permissions()`
- [x] import `Auth` in `Helpers.php`, `Str` in `MenuBuilder.php` (they resolve via Laravel's class aliases at runtime, but hide real errors from stan)
- [x] fix the generic-type issue at `ResponseService.php:54`
- [x] target: `composer stan` at 0 errors, so P1 regressions are visible

## A — Laundry entity
- [x] migration `laundries`: name (json, translatable), phone, email, address, city_id, logo, status enum(active|inactive), timestamps
- [x] migration `laundry_zones` pivot (service areas) — zones table itself lands in P3, so this pivot is deferred to P3
- [x] `app/Modules/Laundry/` — Model (+ `DashboardModel` + `Searchable`), Repository, `laundryCrudService` (with `shredData()`), Request, Controller
- [x] register `Laundry::class` in `config/dashboard.php`; run `PermissionSeeder` (generates laundry.view/create/update/delete/toggle)
- [x] routes group with `permission:laundry.*` on every action incl. `search` + `status`
- [x] `config/menu.php`: entry + `icons` + `titles` + `routes`
- [x] views: index, create, edit, show, forms/formInput, partials/_laundry_table_body

## B — Tenancy primitives (the dangerous part)
- [x] migration: `ALTER TABLE roles MODIFY COLUMN type ENUM('dashboard','app','laundry')` (raw statement — no doctrine/dbal installed)
- [x] migration: `users.laundry_id` nullable FK -> laundries, nullOnDelete
- [x] `App\Trait\BelongsToLaundry`: global scope filtering on the actor's `laundry_id`, PLUS auto-fill of `laundry_id` on create so a laundry user cannot write into another tenant
- [x] scope bypass rules, explicit and tested: no authenticated user (console/seeders/queue) -> no scope; `super_admin` -> no scope; user with `laundry_id` -> forced filter
- [x] `EnsureDashboardRole`: accept `type` in ('dashboard','laundry') — currently hard-fails anything but 'dashboard'
- [x] seed roles `laundry_owner` + `laundry_staff` (type=laundry, is_system=true) with a permission subset
- [x] `moderatorCrudService::shredData()` + `ModeratorRequest` still filter `type='dashboard'`, so laundry roles must NOT appear in the moderator role dropdown — verify

## C — Laundry staff
- [x] `app/Modules/LaundryStaff/` following the Moderator module pattern (subclass of User, `$table='users'`, global scope on role type + own laundry)
- [x] laundry owner manages only their own staff; super admin sees all
- [x] register in `config/dashboard.php` + menu + routes + views

## D — Verification (isolation is the whole point)
- [x] seed 2 laundries with 1 owner each
- [x] as laundry A owner: laundry list shows ONLY laundry A
- [x] as laundry A owner: direct URL to laundry B's show/edit/delete/toggle -> denied, not rendered
- [x] as laundry A owner: staff list shows only A's staff; cannot assign staff to B
- [x] as laundry A owner: sidebar shows only permitted items
- [x] as super_admin: sees both laundries and all staff
- [x] as a customer/driver role (type=app): `/admin/*` still 403
- [x] console + seeders still see all rows (scope must not apply without an actor)
- [x] regression: existing Category / User / Moderator / Settings pages unaffected
- [x] `composer stan`, `pint` on new files, update `Changelog.md`

## Decisions taken (defaults from the plan's recommendations)
- [x] laundry dashboard **shares the `/admin` prefix** — MenuBuilder and CheckPermission consequently needed zero changes
- [x] creating a laundry **also creates its owner** in the same form and transaction
- [x] `laundry_owner` gets laundry.view + laundry.update (own record) + laundry_staff.* ; `laundry_staff` gets laundry.view
- [x] phone validation unified to Egypt via `phoneRegex()`, replacing the Kuwaiti regex in ModeratorRequest

## Verified against the running app
- [x] laundry list: super_admin 2 rows, owner A 1, owner B 1 — each sees only its own
- [x] cross-tenant direct URL (show/edit) -> 404, own record -> 200
- [x] staff list: super_admin 4, owner A 2 (Owner A + Staff A), owner B 2 — no bleed
- [x] cross-tenant staff show/edit -> 404
- [x] **forged `laundry_id` on create was overwritten** (owner A posted laundry_id=2, row landed on 1)
- [x] role escalation rejected (owner A posting role_id=super_admin created nothing)
- [x] destructive endpoints owner A lacks permission for -> 403 (permission layer holds independently of tenancy)
- [x] sidebar: super_admin all items, owner A exactly `Laundries | Laundry Staff`
- [x] customer (role type=app) -> 403 on /admin
- [x] console/unauthenticated sees all rows (scope must not apply without an actor)
- [x] laundry + owner created atomically; duplicate owner email rolled the whole thing back
- [x] Arabic round-trips intact through HTTP and stores without unicode escapes
- [x] all 15 dashboard pages 200; `composer stan` 0 errors; `pint` clean on new files

## Follow-ups
- [ ] `tests/Feature/ExampleTest` still fails (`no such table: settings`) — pre-existing, verified on pristine HEAD in P0
- [x] `composer audit`: **resolved — the 33-advisory figure was wrong** (measured while vendor was half-installed). Four audit modes now report zero. Framework bumped v13.26.1 -> v13.29.0 instead; phpunit 11->12 left alone (major, and CLAUDE.md pins 11)
- [x] Arabic default: **no** — `en` stays the default language (decided 2026-08-26). Arabic remains available as a translation
- [ ] `UserRepository::find()` is unscoped, so `/admin/user/show/{id}` can render a non-customer by direct id. Gated behind `user.view` (moderators/super admin only), so low risk — but worth tightening
- [ ] `AppServiceProvider::boot()` still runs `Schema::hasTable('languages')` + `Language::all()` twice per request
- [x] Dev fixtures promoted to `DevFixturesSeeder` — idempotent, local/testing only, not wired into DatabaseSeeder. Run with `php artisan db:seed --class=DevFixturesSeeder`

---

# Laundo — P2: Catalog & Pricing

Plan reference: خطة داشبورد Laundo, phase P2. Depends on P1.
Status: **DONE 2026-08-26** — approved, implemented, verified against the running app.

The arithmetic base of every order. Order creation (P6) computes the estimated
price from this matrix, so orders are impossible before it exists.

Facts checked: `categories` table exists but holds **0 rows**, `default_price` is
still commented out of the model and request, `cities`/`countries` are also empty.
So there is no data-migration risk in any direction.

## A — Services
- [x] migration `services`: name(json), description(json), image, `pricing_mode` enum(per_item|quote), duration_min, duration_max, duration_unit enum(hour|day), sort_order, status
- [x] `app/Modules/Service/` — Model (+DashboardModel +Searchable), Repository, `serviceCrudService` (shredData), Request, Controller
- [x] register in `config/dashboard.php`, seed permissions, routes, menu, views

## B — Item categories & items
- [x] migration `item_categories`: name(json), sort_order, status
- [x] migration `items`: item_category_id FK, name(json), image, sort_order, status
- [x] `app/Modules/ItemCategory/` and `app/Modules/Item/` on the Category module pattern
- [x] register both, seed permissions, routes, menu, views

## C — Price matrix (the single source of pricing truth)
- [x] migration `item_prices`: service_id FK, item_id FK, price decimal(10,2), unique(service_id,item_id)
- [x] `ItemPrice` model registered in `config/dashboard.php` so `item_price.*` permissions generate automatically
- [x] grid editor screen: rows = items (grouped by category), columns = services, one cell per price
- [x] bulk save in a single transaction; blank cell = service not priced for that item
- [x] a `quote` service takes no per-item prices — the grid must not offer cells for it

## D — Laundry offerings (what each tenant actually provides)
- [x] migration `laundry_services`: laundry_id FK, service_id FK, status, unique(laundry_id,service_id)
- [x] toggle screen in the laundry dashboard — services only, never prices
- [x] add `laundry_service.view` + `laundry_service.update` to the `laundry_owner` role
- [x] tenant-scoped via `BelongsToLaundry`; a super admin sees and edits any laundry's offerings

## E — Public API (guest browsing, from decision 7)
- [x] `GET /api/v1/services` — active services with duration and pricing_mode
- [x] `GET /api/v1/catalog` — the price list the "الاسعار" screen renders: service -> category -> item -> price
- [x] both public, both localized through the `lang` header

## F — Seed the real catalog from Figma
- [x] `CatalogSeeder` with the 4 designed services (غسيل وكي 24–48h, كي فقط ≤24h, تنظيف جاف 48–72h, غسيل المفروشات 2–4d)
- [x] the item categories and items visible in the design, with their prices
      (e.g. القمصان: قميص على شماعة 17, قميص مطوي 19, قميص كتان 24, قميص حرير 30)

## G — Verification
- [x] price matrix round-trips: set prices, reload, values match
- [x] `unique(service_id,item_id)` actually prevents duplicate cells
- [x] a laundry owner can toggle its own services and CANNOT see or edit prices
- [x] a laundry owner cannot touch another laundry's offerings (tenant scope)
- [x] public API returns the catalog with no token, and localizes on `lang: ar`
- [x] Arabic round-trips through the grid editor without unicode escapes
- [x] regression: all dashboard pages, P0 API, P1 isolation matrix
- [x] `composer stan` 0 errors, `pint` clean on new files, update `Changelog.md`

## Decisions taken (defaults from the plan's recommendations)
- [x] legacy `categories`: **hidden from the sidebar only** (removed from `config/menu.php` singles). Table, module, routes and permissions untouched — restoring it is one line
- [x] `غسيل المفروشات`: **quote-based** (`pricing_mode=quote`), no per-item prices, excluded from the grid
- [x] laundry availability: **service level only**; per-item control deferred
- [x] prices: **global**, no speculative city/zone column. Adding one later means a nullable `city_id` plus widening the unique index

## Verified against the running app
- [x] 5 tables created; 25 permissions generated automatically from `config/dashboard.php`
- [x] price grid renders exactly 30 cells (10 items x 3 per-item services); quoted service absent from `<thead>` (0 occurrences)
- [x] grid save: changed cell persisted (17.00 -> 99.50)
- [x] blank cell **deletes** the row rather than storing 0.00 (30 -> 29 rows)
- [x] injected price for a quoted service **rejected** server-side
- [x] `CatalogSeeder` idempotent across three consecutive runs (4/5/10/30 each time)
- [x] no unicode escapes in any seeded JSON column
- [x] public API: `/services` and `/catalog` 200 without a token, localized on `lang: ar` and `lang: en`
- [x] `/catalog` matches the design: القمصان -> على شماعة 17, مطوي 19, كتان 24, حرير 30
- [x] quoted service returns 0 categories / 0 priced items
- [x] laundry owner: `laundry-service` 200, but `service` / `item` / `item-category` / `pricing` all **403**
- [x] laundry owner sidebar: `Laundries | Laundry Staff | My Services` — no Catalog group
- [x] "My Services" page leaks **zero** price values and shows no laundry picker to a tenant
- [x] forged `laundry_id` on the offerings form **ignored** — write landed on the actor's own laundry
- [x] empty offerings submission clears correctly
- [x] regression: 23 dashboard pages 200, P0+P2 API endpoints good, P1 isolation matrix unchanged
- [x] `composer stan` 0 errors, `pint` clean on all new files, `Changelog.md` updated

## Follow-ups
- [ ] Per-item availability for laundries, if service-level proves too coarse
- [ ] Household textiles is quoted — P7's review screen will need a way to enter that quote
- [ ] The grid posts every cell on save; fine at 10 items, worth revisiting past a few hundred
- [ ] Legacy `categories` module is now unreachable from the UI but still routable — decide whether to delete it outright

---

# Laundo — P3: Zones & Time Slots

Plan reference: خطة داشبورد Laundo, phase P3. Depends on P1 (tenancy) and P2 (services).
Status: **DONE 2026-08-26** — approved, implemented, verified against the running app.

Smaller than P2, but it unblocks two things: the laundry service areas deferred
from P1, and the automatic laundry/driver assignment engine in P6/P8.

## 0 — The timezone gap (found while checking state; must be fixed here)
- [x] `countries` and `cities` are both **empty** and the `Country_Id` setting is **unset**
- [x] so `SetTimezone` finds nothing and the app runs on **UTC** — `/api/v1/ping` reports `timezone: UTC`
- [x] every pickup/delivery window would therefore be stored and compared 2–3 hours off from Cairo
- [x] `App\Support\CountryTimezones` already maps `EG => Africa/Cairo`, so seeding Egypt auto-fills it
- [x] seed Egypt + its governorates/cities, set `Country_Id`, clear the `setting_Country_Id` cache
- [x] verify `/api/v1/ping` reports `Africa/Cairo` afterwards

## A — Zones (المنطقة inside a city)
- [x] migration `zones`: city_id FK, name(json), sort_order, status
- [x] `app/Modules/Zone/` on the Category module pattern
- [x] register in `config/dashboard.php`, seed permissions, routes, menu, views
- [x] the design uses a plain dropdown for المنطقة, so a name inside a city is enough — no polygons

## B — Time slots
- [x] migration `time_slots`: start_time, end_time, `applies_to` enum(pickup|delivery|both),
      `days_of_week` json, capacity (nullable = unlimited), sort_order, status
- [x] `app/Modules/TimeSlot/` CRUD
- [x] validation: end_time after start_time; overlapping windows allowed but flagged in the UI
- [x] the design shows 2h and 3h windows ("02:00 مساءً – 05:00 مساءً", "5:00 م - 7:00 م"),
      so slots are templates rather than a fixed grid

## C — Laundry service areas (deferred from P1)
- [x] migration `laundry_zones`: laundry_id FK, zone_id FK, unique(laundry_id, zone_id)
- [x] screen in the laundry dashboard to pick its zones, tenant-scoped via `BelongsToLaundry`
- [x] add `laundry_zone.view` + `laundry_zone.update` to `laundry_owner`
- [x] this is what the P6 assignment engine will match a customer address against

## D — Public API
- [x] `GET /api/v1/cities` — active cities with their zones, for the address form
- [x] `GET /api/v1/time-slots?type=pickup|delivery` — the slot templates
- [x] both public and localized through the `lang` header
- [x] remaining-capacity per slot is deliberately NOT returned yet: it needs order counts, which arrive in P6

## E — Verification
- [x] `/api/v1/ping` reports `Africa/Cairo`, not UTC
- [x] zones scoped to their city; deleting a city cascades its zones
- [x] a laundry owner sees and edits only its own service areas; a forged laundry_id is ignored
- [x] a laundry owner has no `zone.*` or `time_slot.*` permission (those are global, like prices)
- [x] Arabic round-trips through both new modules without unicode escapes
- [x] seeders idempotent across three runs — using JSON-path lookups, per the P2 bug
- [x] regression: all dashboard pages, P0/P2 API, P1 isolation matrix
- [x] `composer stan` 0 errors, `pint` clean, `Changelog.md` updated

## Decisions taken
- [x] time slots: **one set for every day** — `days_of_week` deliberately not added
- [x] capacity: **nullable = unlimited**, column present so a real cap can be set later
- [x] **same set for pickup and delivery** (`applies_to = both`), column present to split later
- [x] **all days are working days**, no holiday model
- [x] seeded **all 27 governorates** as cities; zones for Greater Cairo (Cairo 15, Giza 10)

## Verified against the running app
- [x] `/api/v1/ping` reports `Africa/Cairo` with a `+03:00` offset — was UTC
- [x] `GeoSeeder` idempotent: 1 country / 27 cities / 25 zones on repeat runs
- [x] `TimeSlotSeeder` idempotent: 5 windows on repeat runs
- [x] `/api/v1/cities` returns 27 cities, Cairo with 15 zones, correct Arabic
- [x] `/api/v1/time-slots?type=pickup` returns all 5 windows, capacity `null` = unlimited
- [x] all 5 new dashboard pages 200; zone list paginates 15 rows
- [x] laundry owner: `laundry-zone` 200 but `zone` and `time-slot` both **403**
- [x] laundry owner sidebar: `Laundries | Laundry Staff | My Areas | My Services`
- [x] **forged `laundry_id` on the areas form ignored** — 2 zones landed on laundry 1, 0 on laundry 2
- [x] regression: 28 dashboard pages 200, all 6 public API endpoints 200, `/me` 401, P1 isolation matrix unchanged
- [x] `composer stan` 0 errors, `pint` clean, `Changelog.md` updated

## Follow-ups
- [ ] Zones exist only for Cairo and Giza; the other 25 governorates have none yet (addable from the dashboard)
- [ ] Slot capacity is unenforced until P6 supplies order counts
- [ ] `countries.name` is a `string` column while `cities.name` is `json`, though both models treat the field as JSON. Works, but inconsistent
- [ ] Driver service areas (`driver_zones`) still belong to P5

---

# Laundo — P4: Customers, Addresses & Authentication

Plan reference: خطة داشبورد Laundo, phase P4. Depends on P0 (API layer) and P3 (zones).
Status: **DONE 2026-08-26** — approved, implemented, verified against the running app.

The first phase the mobile apps actually consume for anything but browsing.

## 0 — State checked before planning
- [x] `users` already has `otp` and `otp_expires_at`, so no migration is needed for those
- [x] there is **no `phone_verified_at`** — yet phone is the primary identity here, not email
- [x] there is **no SMS integration at all**, and `MAIL_MAILER=log`. The OTP cannot actually be delivered
- [x] `users.availableUsers()` scopes to `role.slug = user`, so customers stay separate from staff

## A — SMS abstraction (the delivery blocker)
- [x] `App\Services\Sms\SmsSender` contract + a `LogSmsDriver` that writes the code to the log
- [x] `config/sms.php` with a driver switch, so a real Egyptian provider drops in without touching call sites
- [x] this mirrors the payment-driver approach from P9: build the seam now, wire the vendor when chosen

## B — Customer authentication
- [x] migration: `users.phone_verified_at` nullable timestamp
- [x] `POST /register` — name, phone, email (optional), zone, password + confirmation, terms accepted
- [x] `POST /verify-otp` — 6 digits, matching the design's 6-box input and 01:59 countdown
- [x] `POST /resend-otp` — behind the `otp` rate limiter defined in P0 (3/min, 15/day, keyed on phone)
- [x] `POST /login` — phone + password, refuses an unverified or inactive account
- [x] `POST /logout` — revokes the current token only
- [x] `POST /forgot-password` + `POST /reset-password` — OTP to phone, matching the design's phone-first flow
- [x] **hash the OTP** before storing. A plaintext 6-digit code in a column readable by any dashboard query is not acceptable
- [x] **rate-limit verification attempts**, not just sends: 6 digits is a million combinations, which falls in minutes to an unthrottled endpoint

## C — Customer profile
- [x] `GET /me` (extend the P0 stub) · `PUT /profile` · `POST /profile/image` · `PUT /change-password`
- [x] `DELETE /account` — decide soft vs hard delete (see questions)

## D — Addresses
- [x] migration `addresses`: user_id, label, city_id, zone_id, street, building, floor, apartment,
      landmark, notes, contact_phone, lat, lng, is_default
- [x] every field is in the design's "إضافة عنوان جديد" screen — none invented
- [x] `GET /addresses` · `POST /addresses` · `PUT /addresses/{id}` · `DELETE /addresses/{id}` · `PUT /addresses/{id}/default`
- [x] setting one default clears the others, in one transaction
- [x] an address belongs to its owner: a user must never read or write another user's address
- [x] `contact_phone` optional, mirroring the design's "استخدام رقم الحساب" toggle

## E — Customers in the dashboard
- [x] extend the existing User module view to show a customer's addresses and order history placeholder
- [x] `UserRepository::find()` is currently unscoped, so `/admin/user/show/{id}` can render a non-customer.
      Tighten it while here (it was logged as a follow-up in P1)

## F — Verification
- [x] full flow against the running app: register -> OTP in the log -> verify -> login -> token -> `/me`
- [x] login refused before verification, and refused for an inactive account
- [x] wrong OTP refused; expired OTP refused; OTP single-use
- [x] verification attempts throttled; resend throttled
- [x] guest can still reach the public catalogue with no token (decision from P2)
- [x] addresses: cross-user read/write attempt refused
- [x] one default enforced across a user's addresses
- [x] Arabic address fields round-trip without unicode escapes
- [x] regression: dashboard pages, all public API endpoints, P1 isolation matrix
- [x] `composer stan` 0 errors, `pint` clean, `Changelog.md` updated

## Decisions taken
- [x] SMS: **build the seam, stay on the log driver** — vendor wires in later with no code change
- [x] forgot password: **OTP to the phone**
- [x] **multiple devices** allowed; logout revokes only the calling token
- [x] a taken phone is **refused**, verified or not (noted: an unverified registration can park on someone else's number)
- [x] map pin **required** — every address carries lat/lng
- [x] account closure: **soft delete**

## Verified against the running app
- [x] register -> code read from the log -> verify -> token -> `/me` -> `/profile` -> logout -> token dead (401)
- [x] login **before** verification refused (403) and a fresh code re-issued
- [x] wrong code rejected; expired code rejected; **single-use** confirmed (second use -> no_code)
- [x] attempts counted 1..5 and the code **burned on the 6th**; the correct code afterwards is refused
- [x] code stored as **60-char bcrypt**, and `otp` absent from the serialized model
- [x] route throttles engage on both verify and login
- [x] **cross-user addresses: 404 on GET, PUT, DELETE and set-default**, target row untouched, other customer sees 0 addresses
- [x] lat/lng omitted -> 422 with the map message
- [x] first address auto-default; exactly one default maintained; `contact_phone` falls back to the account number
- [x] Arabic label/street/landmark/notes all round-trip byte-exact through the HTTP layer
- [x] soft delete: hidden from normal queries, present `withTrashed()`, tokens revoked, number not re-registerable
- [x] regression: 20 dashboard pages 200, 6 public endpoints 200, protected endpoints 401 without a token, P1 isolation matrix unchanged
- [x] customers page now lists only customers (the P1 follow-up)
- [x] `composer stan` 0 errors, `pint` clean, `Changelog.md` updated

## Follow-ups
- [ ] **No real SMS provider is wired**, so no OTP reaches an actual phone. Needed before any real user testing
- [ ] Changing a phone number has no flow yet — it is the account identity and needs its own verified path
- [ ] The dashboard does not yet show a customer's addresses; deferred with the customer detail page
- [ ] `accepted_terms` is validated but not recorded. If consent has to be provable, it needs a column and a timestamp
- [ ] Guest mode needs nothing server-side (browse-only), but the apps must handle the 401 on protected routes


---

# Laundo — Test Coverage for P0–P4

Requested before starting P5. Status: **DONE 2026-08-26** — both suites green.

## Commands
- `composer test` — 83 PHPUnit tests, 213 assertions
- `composer test:browser` — 52 Playwright tests
- `composer test:all` — both
- `npm run test:browser:headed` — watch the browser drive itself
- `npm run test:browser:report` — the HTML report

## What the suites cover
- [x] P0: envelope shape, envelope code == HTTP status, `lang` header, unknown-locale fallback, JSON 404/401/422, bogus token, enum-to-boolean casting
- [x] P1: console/super-admin unscoped, owner scoped to one row, cross-tenant `find()` and URL both refused, staff lists scoped, **forged `laundry_id` on create overwritten**, app-role locked out, catalogue permissions refused, home tiles gated
- [x] P2: quoted service absent from grid columns, price persists, **blank cell deletes rather than storing zero**, price for a quoted or inactive service rejected, scalar junk ignored, switching to quoted clears prices, unique constraint holds, duration label, public catalogue
- [x] P3: cities with zones, inactive zone hidden, slot filtering by purpose, readable window label, null capacity
- [x] P4: registration without email, unverified on create, code never returned, taken phone refused, terms required, login refused before verification and when inactive, **wrong password and unknown number answer identically**, verify→login→token, logout revokes only the calling token, reset revokes all
- [x] OTP: **hashed storage**, hidden from serialization, single use, expiry, attempts counted and code burned, configured length
- [x] Addresses: pin required, Arabic byte-exact, first becomes default, contact phone fallback, exactly one default, delete promotes another, **cross-customer 404 on read/update/delete/set-default**, inactive zone refused
- [x] Browser: 20 pages + 7 create forms render clean, sidebar per role, tenant isolation as rendered, price grid 30 cells, AJAX search, status toggle, RTL flip, Arabic form round trip

## Bugs the suites found (all fixed)
- [x] `roles.type` could not hold `laundry` outside MySQL — the whole tenancy feature was untestable
- [x] `users.email` stayed NOT NULL outside MySQL — no test could register a customer
- [x] `Language::$fillable` dropped `default` and `app_scope` — marking a language default from the dashboard did nothing
- [x] Timestamps written and compared in different timezones — OTP codes were born expired; the queue worker and P6 scheduler would have hit the same
- [x] Six of my modules read `$request->search` instead of `query` — those search boxes never filtered
- [x] `CategoryController::search()` ignored the search term
- [x] The stock `ExampleTest` had never passed

## Notes
- [ ] Dashboard data columns render the **default language**, not the request locale (`getLocalizedValueDashboard`, per CLAUDE.md). Switching to Arabic flips the layout but leaves record names in English. Pinned by a test so it is not mistaken for a bug — but worth confirming it is what you want
- [ ] `resources/lang/ar*.json` has no translations for the menu labels, so the Arabic dashboard shows English chrome
- [ ] Browser tests run against the development MySQL database and its seeded fixtures, not an isolated one. They read far more than they write and clean up what they create, but they are not safe to point at production
- [ ] `npm audit` reports 12 vulnerabilities from the Playwright install's dependency tree (dev-only)

---

# Laundo — P5: Drivers

Plan reference: خطة داشبورد Laundo, phase P5. Depends on P1 (tenancy/roles), P3 (zones), P4 (auth patterns).
Status: **DONE 2026-08-26** — approved, implemented, both suites green.

Driver identity, profile, documents, working hours, service areas and the
availability switch. The four transport tasks themselves are P8 — this phase
builds the person who will execute them.

## 0 — State checked before planning
- [x] an `employee` role already exists (type=app, is_system) with **0 users** and never used
- [x] `Role::EMPLOYEE` and the `isEmployee()` helper both exist; the helper works now that P0 added the `api` guard
- [x] the design never says "employee" — it says **مندوب / السائق** throughout
- [x] `driver_zones` was deferred here from P1 and P3
- [x] earnings are out of scope: drivers are salaried, decided in the P2 question round

## A — Driver identity and profile
- [x] settle the role slug (see questions) and seed it
- [x] migration `driver_profiles`: user_id, vehicle_type, plate_number, license_number,
      license_expiry, license_image, vehicle_registration_image, vehicle_registration_expiry,
      national_id_image, is_available, notes
- [x] migration `driver_zones`: driver_id, zone_id, unique(driver_id, zone_id)
- [x] working hours per the answer below

## B — Driver management (super admin)
- [x] `app/Modules/Driver/` on the Moderator pattern: a User subclass over the same table,
      scoped by role, with the profile as a related record
- [x] accounts are created administratively — the design has no driver self-registration
      («تواصل مع المشرف» on the login screen)
- [x] document upload with expiry dates, and a visible warning when one has lapsed
- [x] zone picker, working hours, availability, status
- [x] register in `config/dashboard.php`, seed permissions, routes, menu, views

## C — Driver API
- [x] `POST /api/v1/driver/login` — phone + password, refuses an inactive account
- [x] `POST /api/v1/driver/logout`
- [x] `GET  /api/v1/driver/profile` — personal details, vehicle, documents, zones, working hours
- [x] `PUT  /api/v1/driver/profile` — only the fields a driver may edit (not their own zones or documents)
- [x] `PUT  /api/v1/driver/availability` — the «متاح لاستقبال المهام» switch
- [x] `PUT  /api/v1/driver/password`
- [x] forgot password per the answer below
- [x] a driver token must not reach the customer endpoints, and vice versa

## D — Verification (PHPUnit + Playwright)
- [x] a driver cannot sign in to the dashboard (role type `app`)
- [x] a customer token is refused on driver endpoints and a driver token on customer endpoints
- [x] an inactive driver cannot sign in
- [x] availability toggles and persists
- [x] a driver cannot edit their own zones or documents through the API
- [x] a driver cannot read another driver's profile
- [x] expired documents surface in the dashboard
- [x] the zone picker is scoped to active zones
- [x] Arabic round-trips; regression across all four earlier phases
- [x] `composer stan` 0 errors, `pint` clean, both suites green, `Changelog.md` updated

## Decisions taken
- [x] the `employee` role **renamed to `driver`** — zero users, so no risk, and the code now speaks the design's language
- [x] working hours: **one window per driver**, applied to every day
- [x] expired documents: **flagged in the dashboard**, not auto-enforced
- [x] forgot password: **OTP to the phone**, same path as customers

## Verified — 28 new PHPUnit tests, 11 new Playwright tests
- [x] driver signs in; no registration endpoint exists (404)
- [x] inactive driver refused; **a customer cannot sign in through the driver endpoint and vice versa**
- [x] a customer token on a driver endpoint is **403**
- [x] profile reports vehicle, licence, shift and zones
- [x] availability toggles and persists; **a suspended driver cannot make themselves available**
- [x] **a driver cannot change their own zones or documents** through the API — name changes, territory and licence do not
- [x] logout revokes only the calling token; password change signs other devices out; reset by OTP revokes all
- [x] forgot-password answers identically for unknown numbers
- [x] dashboard: driver cannot reach it at all; list shows only drivers; a non-driver id gives 404
- [x] create builds account + profile + zones in one transaction, phone pre-verified
- [x] **the availability switch can be turned off** (an unchecked box is absent from the payload, not false)
- [x] inactive zones refused; duplicate phone refused; backwards shift refused
- [x] expired documents flagged; **a licence expiring today is still valid**
- [x] `isDispatchable()` needs both active and available
- [x] browser: pages and forms render, all five design sections present, zone picker grouped by city, edit form loads the stored profile, availability round-trips through the form, search filters, and a laundry owner is refused (403) with `Drivers` absent from their sidebar

## Bug this phase found
- [x] **`abort(403)` in an API controller returned 500** — the P0 exception renderer had no arm for a plain `HttpException`. Fixed for the whole `api/*` surface, not just here

## Follow-ups
- [ ] Per-weekday shifts, if one window proves too coarse
- [ ] No SMS provider is wired, so a driver's password-reset code reaches the log only
- [ ] Driver task history and today's summary («ملخص اليوم») need tasks — P8
- [ ] The design's driver account screen also lists FAQ / contact / complaint, which arrive with P10

---

# Laundo — P6: Orders

Plan reference: خطة داشبورد Laundo, phase P6. Depends on P2 (catalogue), P3 (zones/slots), P4 (customers/addresses), P5 (drivers).
Status: **DONE** — 158 PHPUnit / 491 assertions, 76 Playwright, `composer stan` clean.

The largest phase. The five-step wizard, the estimated-price engine, the state
machine, automatic laundry assignment, and recurring orders. The piece-review and
final price are P7; the four transport tasks are P8.

## 0 — State checked before planning
- [x] everything P6 reads already exists: 4 services, 10 items, 30 prices, 25 zones, 5 slots, 2 laundries, drivers with coverage
- [x] **zero order plumbing existed** — every table below is new
- [x] **there was no delivery-fee mechanism at all** → resolved by decision, then built (`DeliveryFeeCalculator`)
- [x] `laundry_zones` held only 2 rows → `DevFixturesSeeder` now seeds full coverage, rates and coordinates

## A — Schema
- [x] `orders` — with two price sets. `laundry_id` nullable, `coupon_id` dropped in favour of `coupon_code` (no promotions module exists to point a FK at)
- [x] `order_items` — `service_id` dropped: the order already names the service, and a per-line copy could contradict it
- [x] `order_status_logs`
- [x] `order_media`
- [x] `order_recurrences` + `recurrence_prompts` (unique on `recurrence_id, prompted_for`)
- [x] delivery-fee storage: `zones.price_per_km` + `min_delivery_fee`, `laundries.lat/lng`, `orders.delivery_fee`

## B — The pricing engine
- [x] estimated subtotal from `item_prices`, priced at the moment of ordering
- [x] **unit prices copied onto `order_items`** — proven by moving the matrix after the order and asserting the order did not follow
- [x] delivery fee and estimated total; discount clamped to the subtotal
- [x] a `quote` service takes no per-item lines
- [x] a piece the service cannot price is refused, not given away

## C — The state machine
- [x] all 11 statuses, transitions declared once in `OrderStatus::allowedNext()`
- [x] every move written to `order_status_logs`, plus `note()` for assignments
- [x] **cancellation refused after pickup**
- [x] `returned` terminal and distinct from `cancelled`

## D — Order creation (customer API)
- [x] `POST /orders` — the whole wizard in one payload, plus stain photos
- [x] `POST /orders/quote` — price preview, same pricing pass as `store`
- [x] validation: items priced for the service, addresses fetched through the caller's own relation, slots active and applicable
- [x] `GET /orders` with all/active/completed/cancelled tabs · `GET /orders/{id}` · `GET /orders/{id}/track`
- [x] `PUT /orders/{id}/cancel`
- [x] `GET /orders/{id}/reorder` — returns a basket, not an order (GET, not POST: it creates nothing)

## E — Laundry assignment
- [x] automatic: covers the pickup zone AND offers the service AND active; nearest wins
- [x] manual assignment from the dashboard, restricted to covering laundries and refused once in custody
- [x] nothing matches → **accepted unassigned**, per the decision

## F — Recurring orders
- [x] the saved schedule (weekly / biweekly / monthly) with service, items, address and window
- [x] `orders:prompt-recurring` asks «محتاج تغسل النهاردة؟» — it never creates an order
- [x] confirm → order at today's prices; decline or silence → cycle skipped, schedule lives on
- [x] **timestamps stay UTC**; the cycle advances from the cycle date, so a late run does not shift the weekday

## G — Orders in the dashboard
- [x] super admin sees everything including the unassigned queue; a laundry sees its own only
- [x] detail view: pieces, both price sets, addresses, windows, photos, status history, assigned laundry
- [x] reassign the laundry (driver assignment is P8)

## H — Verification
- [x] price arithmetic (2×17 + 1×23 = 57) and the fee rules, including the ×1.5 and the minimum floor
- [x] a price change after ordering does not alter the placed order
- [x] forbidden transitions refused; `isCancellable()` proven consistent with `allowedNext()` for every case
- [x] cancel before pickup allowed, after pickup refused (400)
- [x] a customer cannot read, track or cancel another customer's order (404 on all three)
- [x] a laundry sees only its own orders; an unassigned order is invisible to every tenant
- [x] assignment picks a covering laundry, skips a non-covering one, and reprices delivery on assignment
- [x] recurrence: prompt created, three runs still one prompt, confirm makes an order, decline skips, paused not asked, resume re-anchors, late run keeps the weekday
- [x] regression across P0–P5 (158 passing, from 111); stan clean; pint run

## Decisions taken
- **Delivery fee** = distance (laundry → customer address) × the pickup zone's per-km rate, floored at the zone minimum, ×1.5 for different pickup/delivery addresses.
- **Order code**: sequential from a five-digit base, as `#10244` in the design.
- **Active orders**: no limit for a customer; the driver cap and single-city rule are P8.
- **No covering laundry**: accept the order unassigned and leave it to operations.
- **Shipped as one phase.**

## Review — what was built, and what is worth knowing

**The load-bearing decisions.** Three rules are each written in exactly one place,
because each of them is the sort of thing that goes wrong quietly:

1. *Prices are copied, never re-read.* `OrderPricing` reads `item_prices` once and
   hands back lines that are stored on the order. A super admin raising a price
   tomorrow cannot rewrite the arithmetic of an order placed today.
2. *Cancellation stops at pickup.* It lives in the transition table, so no endpoint
   can allow it by accident — and `isCancellable()` is derived from that table
   rather than duplicated, with a test asserting the two agree for all 11 states.
3. *An unknown fee is reported, not priced at zero.* Returning `0.00` for a laundry
   with no coordinates would tell a customer delivery is free.

**The recurrence question.** As specified: a schedule asks, it does not order. The
prompt table exists so the scheduler can tell "not asked" from "asked and ignored";
without it the command would either pester the customer daily or skip them
forever. Tested by running it three times and asserting one prompt and zero orders.

**Two things found while building.** `assignLaundry()` originally logged its work
through `transition($order, $order->status)`, which the machine treats as
idempotent — so the assignment left no trace at all. And `promptDue()` captured a
variable it never used, which turned out to be the right behaviour asking to be
made explicit: a prompt belongs to the cycle it was due for, not to the day the
scheduler ran.

**A gap closed that was not in the plan.** The migrations added
`zones.price_per_km` and `laundries.lat/lng`, but nothing in the dashboard could
set them — so every fee in production would have come back «unknown». Wired
through both forms, both requests and both services, with the clearing case
handled (a rate that can be set but never unset is a trap).

## Follow-ups carried forward
- [ ] No coupon engine: `coupon_code` is stored, `discount_total` is always 0 until a promotions module exists.
- [ ] `payment_method` / `payment_status` exist so the state machine can speak about payment, but nothing is wired — P9.
- [ ] Time-slot capacity (`time_slots.capacity`) is not enforced when booking.
- [ ] `order.create` / `order.delete` / `order.toggle` are generated by `PermissionGenerator`'s fixed action set but have no routes.
- [ ] `./vendor/bin/pint` reformatted ~90 pre-existing files; formatting only, but it widens the diff beyond P6.
- [ ] Real road distance instead of haversine, if the fee ever needs to match a routing service — one method to change.

---

# Laundo — P7: Piece Review & Final Pricing

Plan reference: خطة داشبورد Laundo, phase P7. Depends on P6 (orders).
Status: **DONE** — 191 PHPUnit / 621 assertions, 83 Playwright, `composer stan` clean.

## Objective

Close the loop P6 deliberately left open. An order today carries an *estimate*
built from the customer's own count of the pieces. P7 is where the laundry
physically counts them, prices what it actually received, and the customer agrees
to that figure before anything is cleaned.

This is the laundry dashboard's core screen and the single most commercially
sensitive flow in the product: it is where the number the customer pays is
decided.

## Current state (checked, not assumed)

- `orders.final_items_count` / `final_subtotal` / `final_total` / `review_note` /
  `reviewed_at` all exist — and **nothing writes any of them**. Grep confirms the
  only references are the model, the enum and the read-side of the API.
- `order_items.phase` (`estimated` | `final`) exists; every row today is `estimated`.
- `OrderStatus` already declares `Reviewed → AwaitingPayment | Returned` and
  `AwaitingPayment → Cleaning | Returned`, and `OrderStateMachine` will refuse any
  other move. So the transitions P7 needs are in place and guarded.
- `OrderPricing` reads the price matrix; P7 needs the same read for the *final*
  basket, copied onto the order exactly as P6 does.
- Nothing customer-facing exists for approving a price.

## Figma reference (what I actually found)

The Figma REST API is reachable; the MCP connector is not authorised this session,
which changes nothing since the REST route works.

**Authoritative `ui` page:**
- `101:3236` «بانتظار الدفع / السعر النهائي جاهز» — «تمت مراجعة عدد القطع وحالة
  الملابس من قبل خبير العناية. يمكنك الآن إتمام الدفع.» Payment methods (بطاقة
  بنكية / المحفظة الإلكترونية / انستا باي / الدفع نقدًا عند الاستلام) and a single
  action: «ادفع الآن — 280 ج.م». Total tagged **سعر نهائي** (vs **سعر تقديري** before).
- `35:2797` the order wizard's review step carries a required consent:
  «أوافق على مراجعة القطع وتحديد السعر النهائي قبل بدء التنظيف» — **P6 does not
  record this**, see Gaps.
- `413:5365` shows the customer the review note verbatim:
  «تم العثور على قطعة إضافية أثناء المراجعة، وتم تحديث تفاصيل الطلب.»

**`drft` page — a materially different version of the same screen:**
- `399:2593` «مراجعة السعر النهائي»: **مقارنة القطع** side by side (8 قطع العدد
  المضاف عند إنشاء الطلب vs 9 قطع العدد الفعلي بعد المراجعة), per-line detail
  (4 قمصان ×25 = 100, 2 تيشيرت ×20 = 40, 2 بنطلون ×35 = 70, 1 جاكيت ×60 = 60),
  «لن يتم تغيير هذا المبلغ بعد تأكيدك إلا في حالة إضافة خدمة جديدة بموافقتك»,
  and **three** actions: «تأكيد السعر والدفع — 280 ج.م», «لدي استفسار عن السعر»,
  «طلب مراجعة إضافية».
- `399:2762` timeline: تم إنشاء الطلب → بانتظار الاستلام → مراجعة القطع →
  **تأكيد السعر** → بدء التنظيف.
- `416:6541` timeline: تم الطلب → مراجعة → **تأكيد** → تنظيف → توصيل.

**No Figma design exists for the laundry's own review screen** — the file has four
pages (cover, ui, delivery, drft) and none is a dashboard. That screen comes from
the dashboard documentation, which is not in the repository.

## The contradiction I will not resolve on my own

The two versions of the customer's final-price screen disagree on the shape of the
step, and the difference is not cosmetic:

| | `ui` 101:3236 | `drft` 399:2593 |
|---|---|---|
| Piece comparison | absent | «مقارنة القطع» 8 → 9 |
| Per-line breakdown | absent | full |
| Actions | «ادفع الآن» only | confirm / query / request re-review |
| Step in the timeline | payment | **تأكيد السعر**, separate from payment |

And the timelines disagree with what P6 already ships: P6 draws
`picked_up → reviewed → cleaning → ready_for_delivery → delivered`, while both
`drft` timelines insert **تأكيد** as its own point.

Per the standing rule, I have stopped rather than picked one.

## Implementation plan (shape is fixed; the questions decide the details)

### A — Laundry review service
- `OrderReviewService::review(Order, array $lines, ?string $note)` — counts and
  prices the pieces the laundry actually received.
- **Prices are re-read once, here, and copied onto `order_items` as `phase=final`**,
  exactly as P6 does for the estimate. The estimated rows are never touched, so
  the before/after comparison the design draws is always reconstructable.
- Writes `final_items_count`, `final_subtotal`, `final_total`, `review_note`,
  `reviewed_at` in one transaction, then moves the order through the state machine.
- The delivery fee and any discount carry over from the order unchanged — the
  review re-prices *pieces*, not the trip.
- Guard: reviewing is legal only from `picked_up`, and only once unless a
  re-review is explicitly allowed (question 3).

### B — Laundry dashboard screen
- A review form on the order detail page, visible to the assigned laundry when the
  order is `picked_up`: the estimated basket pre-filled, quantities editable,
  pieces addable and removable, a live total, and a note field.
- Shows the estimated figures alongside, so the person counting sees the gap they
  are creating.
- Gated on `order.update` **and** the tenant scope, so only the assigned laundry
  can price its own work.

### C — Customer API
- `GET /orders/{id}/review` — the comparison payload: estimated vs final counts,
  both line sets, the note, and the final money.
- `POST /orders/{id}/approve` — the customer accepts. What this triggers depends on
  question 2.
- Whatever objection path question 3 selects.

### D — Notifications seam
- The customer must be told the price is ready. P11 owns delivery; P7 raises the
  event so the two can be built apart — the same split the recurrence prompts use.

### E — Verification
- Final prices copied, not referenced: move the matrix after the review, assert the
  order does not follow.
- The estimated rows survive the review intact.
- A laundry cannot review another laundry's order; a customer cannot approve
  someone else's.
- Reviewing from any state other than `picked_up` is refused.
- Cleaning cannot begin before whatever gate question 2 selects.
- Arabic notes round-trip; full regression; both suites green; stan clean.

## Gaps in P6 this phase should close
- The wizard's consent «أوافق على مراجعة القطع وتحديد السعر النهائي قبل بدء
  التنظيف» is shown in the design but **not recorded** by `POST /orders`. Same
  class of gap as `accepted_terms`. It is the customer's agreement to the whole
  P7 mechanism, so it belongs on the order.
- P6's tracking timeline may need a **تأكيد** step inserted (question 1).

## Decisions taken

1. **Both screens, in sequence.** The comparison/confirmation screen first
   («مراجعة السعر النهائي» — piece comparison, per-line detail, three actions),
   then the payment screen («بانتظار الدفع») once the price is confirmed. The two
   Figma versions are not competing drafts but consecutive steps.
2. **Confirmation releases cleaning, not payment.** The only reading consistent
   with «الدفع نقدًا عند الاستلام» being on offer, and with the design's own
   «تم تأكيد الطلب — تمت الموافقة على السعر النهائي، وسيبدأ تجهيز طلبك الآن».
3. **Objection = «طلب مراجعة إضافية» or a support query.** No rejection-and-return
   path from the customer, and no auto-approval on silence.
4. **The laundry's review screen is designed from the data model and the customer
   screen**, in the dashboard's existing idiom.

## Consequence: two changes to P6's state machine

Decision 2 makes the current enum lie. `AwaitingPayment` sits between `Reviewed`
and `Cleaning` as though money were the gate — but money is now beside the
pipeline, not in it. Two changes follow, and both are visible in the design:

- **`AwaitingPayment` → `Confirmed`** («تم التأكيد»). This is the design's own
  timeline word: `416:6541` draws تم الطلب → مراجعة → **تأكيد** → تنظيف → توصيل,
  and `399:2762` draws … → مراجعة القطع → **تأكيد السعر** → بدء التنظيف. Payment
  moves entirely to `payment_status`, which already exists and already carries
  unpaid/paid/refunded. A card customer pays on confirmation; a COD customer pays
  at delivery; **neither changes where the order sits in the pipeline.**
- **New `ReviewDisputed`** («بانتظار مراجعة إضافية»), so «طلب مراجعة إضافية» is a
  real state rather than a note nobody acts on: `Reviewed → ReviewDisputed →
  Reviewed`. It gives the laundry a queue of orders a customer has questioned,
  which is the whole point of offering the button.

`Returned` stays in the enum but becomes **unreachable from the customer**, since
decision 3 removed the rejection path. Rather than leave a dead state, it is
reachable only from `Reviewed` / `ReviewDisputed` by an operator — the escape
hatch for an argument that cannot be settled by re-counting.

Resulting pipeline:

    awaiting_pickup → driver_on_way → picked_up → reviewed → confirmed
                                                     ↕
                                              review_disputed
    confirmed → cleaning → ready_for_delivery → delivered → completed

Cost: `OrderStatusTest` and the tracking-steps assertions change with it. P6 is
unreleased and no order in the dev database holds `awaiting_payment`, so the
rename costs a migration of nothing.

## Also closing, from the P6 gap
- Record the wizard's consent «أوافق على مراجعة القطع وتحديد السعر النهائي قبل بدء
  التنظيف» on the order. It is the customer's agreement to this entire mechanism,
  and P7 is where it starts to matter.

## Deliberately NOT built
- Actual payment capture — P9. `payment_status` is set, no gateway is called.
- Support threads — P10. A price query is recorded against the order and surfaced
  in the dashboard; the conversation itself comes later.
- No silence timeout. Decision 3 did not choose auto-approval, so an unanswered
  order waits indefinitely. Flagged as a risk: it needs an operations view of
  orders stuck at `reviewed`, which the dashboard's status filter already gives.

## Review — what was built

**The commercial rule this phase exists to protect.** An order's estimate and its
final price are two separate records, and a review adds to the second without
touching the first. That is what makes «مقارنة القطع» reconstructable months
later — and a comparison you can still draw during an argument is worth more than
one you drew once on a screen.

**The rename was the real work.** `AwaitingPayment` was a name that asserted
something false: that money gates cleaning. It cannot, while «الدفع نقدًا عند
الاستلام» is a payment method. Renaming it `Confirmed` and moving payment out of
the pipeline into `payment_status` is a small diff that fixes a wrong idea, and
the design had the right word all along.

**A question that moves nothing.** «لدي استفسار عن السعر» is easy to build badly:
either as a note in the status log (where nobody filters for it) or as a state
change (which misrepresents a question as a refusal). Its own table, with an
`(answered_at, answered_by)` pair and an open-questions index, is what makes it
answerable rather than merely recordable.

**Verified end to end on the live database**, not only in tests: place → collect →
review → question → answer → dispute → re-review → confirm → cleaning, with the
audit trail correct at every step.

## Follow-ups carried forward
- [ ] No payment capture — P9. `payment_status` is set; no gateway is called.
- [ ] No support threads — P10. A price query is recorded and answerable; the
      conversation is not.
- [ ] **No timeout on silence.** An order whose customer never confirms waits
      indefinitely. Needs an operations view of orders stuck at `reviewed` — the
      dashboard's status filter gives it, but nobody is prompted to look.
- [ ] A quote-priced service («تنظيف جاف») still has no pricing route: the review
      form needs the matrix, and that service has none by design. Its review needs
      free-form pricing, which nothing yet provides.
- [ ] The customer is not yet *told* the price is ready — P11 owns delivery of the
      notification; P7 leaves the seam.

---

# Laundo — P8: Tasks & Dispatch

Plan reference: خطة داشبورد Laundo, phase P8. Depends on P5 (drivers), P6 (orders), P7 (review).
Status: **DONE** — 241 PHPUnit / 813 assertions, 89 Playwright, `composer stan` clean.

## Objective

Move the clothes. An order today is placed, priced, agreed and cleaned entirely on
paper — nobody has been told to collect anything. P8 turns each order into the
**four physical journeys** the delivery app is built around, and gives a driver the
screens to complete them.

## Current state (checked, not assumed)

- `order_tasks` does not exist. The whole phase is new.
- `orders.qr_token` exists and is generated per order — nothing scans it yet.
- `order_media.type` already declares `pickup`, `laundry`, `ready`, `delivery`
  alongside `stain`, so the per-leg photo slots were reserved in P6.
- `driver_profiles` has `is_available`, `shift_start`, `shift_end`, and drivers
  have zones (P5).
- `orders.payment_status` / `payment_method` / `paid_at` exist and nothing writes
  them; a COD collection is the first thing that would.
- **`max_concurrent_orders` and `city_id` are null on every driver.** The columns
  were added in P6 but never given a form field — the same gap as the zone rate,
  and it matters more here because the capacity rule P8 is supposed to enforce is
  built on them.

## Figma reference — the delivery app (`142:3011`), read in full

**The four legs are explicit**, each its own screen with the same five-point bar:

| # | Frame | Leg | What the screen collects |
|---|---|---|---|
| 1 | `193:2292` | استلام من العميل | QR scan · **عدد القطع المستلمة** · photos (optional) · **توقيع العميل** |
| 2 | `207:3599` | تسليم للمغسلة | QR scan · piece count · **اسم الموظف المستلم (اختياري)** · photos |
| 3 | `207:3890` | استلام من المغسلة | QR scan · **مراجعة الكمية — القطع الأصلية: 12** · **ملاحظات المغسلة** · photos |
| 4 | `207:4073` | تسليم للعميل | QR scan · **تفاصيل الدفع + المبلغ المحصل** · photos · **توقيع العميل** |

Every leg ends with a pair of buttons: **تأكيد** or **تعذر الاستلام / توجد مشكلة**.

**Failure reasons** (`207:3755`) are a fixed list plus free text: العميل غير متاح ·
العنوان غير صحيح · العميل طلب التأجيل · عدد القطع غير مطابق · سبب آخر.

**Task list** (`172:1727`): «7 مهام», filtered by state (الكل / جديدة / قيد التنفيذ /
مكتملة / **متأخرة**) and by kind (الكل / استلام / تسليم), searchable by order number
or customer name.

**Home** (`165:1126`): the availability toggle «متاح لاستقبال المهام», a day summary
(استلام 3 · تسليم 2 · مكتملة 4 · **متأخرة 1**) and the current task with «بدء المهمة».

**History** (`221:5767`): completed and failed tasks with البدء / الانتهاء / المدة, and
**سبب الفشل** shown on the failures.

**Order card** (`193:2391`): رقم الطلب · **مرجع العميل C-882** · الخدمة · التاريخ ·
الوجهة, with **طباعة البطاقة / إعادة الطباعة** — a printable label.

**On success** (`231:7325`): «تمت المهمة بنجاح — تم إخطار العميل وتمت إضافة أرباحك إلى
الرصيد المعلق» — driver earnings and a pending balance.

## What I can read straight from the design, and will not ask about

- **Photos are optional, signatures are not.** The design marks every photo slot
  «(اختياري)» and the staff name «(اختياري)», and marks the signature pads with
  neither. Where it wanted to say optional, it said so.
- **A task is late when its window has passed** — «متأخرة» has no other source than
  the order's pickup/delivery slot, which P3 already models.
- **Legs 1 and 4 take a signature; 2 and 3 do not.** A laundry hands over to a
  colleague, not to a customer.

## Implementation plan

### A — Schema
- `order_tasks`: order_id, driver_id (nullable until dispatched), `type`
  (pickup_from_customer | deliver_to_laundry | collect_from_laundry |
  deliver_to_customer), `sequence` 1–4, `status`
  (pending | assigned | started | completed | failed), scheduled window,
  started_at, completed_at, piece_count, receiver_name, failure_reason,
  failure_note, collected_amount, signature_path.
- `order_tasks` is where «المدة» comes from: `completed_at − started_at`.
- Signature stored as an uploaded image like every other file in this codebase.

### B — Task generation
- The four legs are created **together, when the order is confirmed** — not one at
  a time. A driver needs to see tomorrow's delivery, and operations need to see
  the whole chain, before either has happened.
- Legs 2, 3 and 4 unlock in sequence: a task cannot start until its predecessor
  has completed. That ordering is the safety property of the whole phase — nothing
  can be delivered that was never collected.

### C — Dispatch (question 1 decides the shape)
- Eligible driver: active, available, serves the zone, within capacity, in the
  right city.
- **`max_concurrent_orders` and `city_id` become reachable from the driver form**,
  since neither rule can be enforced while both are null.

### D — The driver API
- `GET /driver/tasks` with the design's filters · `GET /driver/tasks/{id}`
- `POST /driver/tasks/{id}/start` — «بدء المهمة»
- `POST /driver/tasks/{id}/verify` — the QR scan, checked against `orders.qr_token`
- `POST /driver/tasks/{id}/complete` — count, photos, signature, staff name,
  collected amount
- `POST /driver/tasks/{id}/fail` — reason from the fixed list plus a note
- `GET /driver/summary` — the home screen's four counters
- `GET /driver/history` — completed and failed, with durations

### E — The order state machine, driven by tasks
- Leg 1 completing → `picked_up`. Leg 2 → the laundry can review (P7). Leg 3 →
  `ready_for_delivery` is already satisfied. Leg 4 → `delivered`.
- Every one of those goes through `OrderStateMachine`, so the audit trail keeps
  its single writer.

### F — Dashboard
- Tasks on the order detail page: the four legs, who holds each, and where it got
  to.
- A dispatch view for unassigned tasks, and manual reassignment.
- The printable order card (`193:2391`).

### G — Verification
- The four legs generated once and only once per order.
- A leg cannot start before its predecessor completes.
- A QR token from a different order is refused.
- A driver cannot see or touch another driver's task.
- Capacity and single-city rules enforced at dispatch.
- A failed leg behaves as question 4 decides.
- Full regression; both suites; stan and pint clean.

## Decisions taken

1. **Automatic dispatch with a manual override.** The system picks an eligible
   driver — active, available, serves the zone, in the right city, under capacity
   — and operations can reassign. Nothing eligible leaves the task in a dispatch
   queue rather than forcing it on somebody, the same shape as P6's laundry
   assignment.
2. **Earnings wait for P9.** P8 records the task, when it finished and who did it,
   and nothing about money. Half a wallet is worse than a deferred one.
3. **A collected amount that differs is recorded and flagged, not refused.** The
   delivery completes. A driver standing at the customer's door should not be
   blocked over a five-pound discrepancy — the discrepancy is the smaller problem,
   and operations can see every one of them.
4. **A failed leg returns to the dispatch pool**, with two exceptions:
   «عدد القطع غير مطابق» halts the order for review instead, because a dispute
   about the count is not a delivery problem and must not travel; and any task
   that has failed **twice** escalates to operations rather than going round
   again — a task failing repeatedly is not a dispatch problem.

**Consequence flagged, not re-asked:** «العميل طلب التأجيل» was not given its own
rescheduling path, so it returns to the pool like any other failure. Another driver
will then be sent at the same time the customer already declined. Recorded as a
follow-up rather than invented around.

## Open, but not blocking — flagged for the mobile team
- The five-point bar on every leg screen reads 1 استلام من العميل → 2 تسليم للمغسلة
  → 3 استلام من المغسلة → 4 تسليم للعميل → 5 **المراجعة**. But the laundry's review
  happens between legs 2 and 3, not after leg 4. Either «المراجعة» is a fifth
  driver-side wrap-up step, or the bar is drawn in the wrong order. It affects the
  app's display only — the API is unaffected either way — so it is recorded here
  rather than held as a blocker.

## Review — what was built, and the two things it got wrong first

**The safety property.** Nothing can be delivered that was never collected. All
four legs exist from the moment the order does — a driver needs to see tomorrow's
delivery — but `predecessorComplete()` holds three of them shut, and it is checked
on every start *and* every completion, because a predecessor can be undone by an
operator after the fact.

**Generation was in the wrong place, and a test found it.** The chain was first
created when the customer confirmed the final price. Confirmation happens after
the review, which happens after the pickup — so the pickup leg was being created
after the pickup it was meant to schedule. Moving generation to placement fixed
it, and forced a second, better change: **starting** leg 1 is what moves the order
to «في الطريق للاستلام», which is what the tracking screen should have said all
along.

**An escalation nobody could act on.** Two failures were supposed to hand a task
to operations, but `assign()` refused any finished task — so the escalated task
was simply dead. It now refuses only a *completed* leg. Found by the end-to-end
walk on the live database, not by any unit test, which is the argument for doing
that walk.

**The P6 gap, again.** `max_concurrent_orders` and `city_id` were added by
migration in P6 and never given a form field, so both were null on every driver
and the rules built on them never bit. Exactly the shape of the zone-rate gap, and
worth naming as a pattern: **a column added without a way to set it is a column
that is always null.**

## Follow-ups carried forward
- [ ] Driver earnings and «الرصيد المعلق» — P9 by decision.
- [ ] **«العميل طلب التأجيل» has no rescheduling path.** It returns to the pool
      like any other failure, so another driver is sent at the time the customer
      already declined. Chosen deliberately; still worth revisiting.
- [ ] The printable order card (`193:2391`, «طباعة البطاقة / إعادة الطباعة») is not
      built, and neither is «مرجع العميل C-882».
- [ ] The two laundry legs have no independent due time — the laundry sets its own
      pace, and inventing a deadline for it would create lateness nobody agreed to.
      Legs 3 and 4 have no `due_at` at all when an order has no delivery date.
- [ ] The driver is not notified of a new task — P11 owns delivery.
- [ ] The five-point bar's «المراجعة» position is unresolved; flagged for the
      mobile team, display-only.

---

# Laundo — P9: Payments, Wallet & Earnings

Plan reference: خطة داشبورد Laundo, phase P9. Depends on P6–P8.
Status: **AWAITING APPROVAL — no code written yet. One question is a hard blocker.**

## Objective

Make the money real. Every phase so far has been careful to record what is owed
and never to pretend it arrived: `payment_status` is written, `paid_at` is set only
on a full cash collection, and no gateway has ever been called. P9 is where that
stops being a placeholder.

## Current state (checked, not assumed)

- `orders` already carries `payment_method`, `payment_status`, `paid_at`,
  `coupon_code` and `discount_total`. **Every order in the dev database is
  `unpaid` with no method**, and `discount_total` has never been non-zero.
- `order_tasks.collected_amount` is the only money the system has ever recorded —
  one row, 83.00, from the P8 walk.
- **Nine tables P9 needs do not exist**: wallets, wallet_transactions, payments,
  payment_methods, coupons, coupon_redemptions, driver_earnings, invoices, refunds.
- `config/services.php` holds only mail and Slack. There is no gateway seam yet.

## Figma reference

**Payment methods** (`ui` `101:3236`, authoritative): بطاقة بنكية · المحفظة
الإلكترونية · **انستا باي** · الدفع نقدًا عند الاستلام, the last marked
«قد يتم تطبيق رسوم إضافية».

**The wallet** (`ui` `318:1296`): «الرصيد الحالي 2500 ج.م», **إضافة أموال** and
**تحويل**, then «تاريخ المعاملات» — `+100 ج.م إضافة رصيد (مكتملة)`,
`-150 ج.م دفع طلب #1024 (مكتملة)`.

**A richer wallet draft** (`drft` `108:5010`) adds: **سحب الرصيد**, **طلب استرداد**,
**استخدام كوبون**, **طرق الدفع**, transaction filters (الكل / المدفوعات / الإضافات /
الاستردادات), and a third state — **«قيد المراجعة»** on a refund, so a refund is
requested and approved rather than granted instantly.

**Receipts**: «TXN-20458 رقم المعاملة» and **«تحميل الفاتورة»** appear on the
confirmation screens (`534:3913`, `534:4178`).

**Driver earnings** (`delivery` `231:7325`): «تم إخطار العميل وتمت إضافة أرباحك إلى
**الرصيد المعلق**» — a pending balance that presumably later becomes payable.

**A discount already exists in the design's own arithmetic**: التنظيف والكي 270 +
رسوم التوصيل 20 − **خصم الترحيب 10** = 280. So `discount_total`, which has never
been non-zero, has a named source waiting for it.

## Read from the design, and not asked about

- **No VAT.** The Egyptian screens total التنظيف + التوصيل − الخصم and stop. The
  15% ضريبة line appears only in Saudi-market drafts (`534:4365`, ر.س, مدى,
  Apple Pay), which are not this product.
- **A refund is reviewed, not automatic** — «قيد المراجعة» is a state the design
  draws.
- **Cash may carry a surcharge** — «قد يتم تطبيق رسوم إضافية» sits under it.

## Implementation plan

### A — The gateway seam (shape settled; provider is question 1)
- A `PaymentGateway` contract with `charge()`, `refund()` and `verifyWebhook()`,
  one driver per provider, and a `fake` driver for tests and local work.
- **Nothing in the domain talks to a provider directly.** The same rule that kept
  pricing out of controllers.

### B — Payments
- `payments`: order_id, provider, provider_reference («رقم المعاملة»), amount,
  status (pending | authorised | captured | failed | refunded), raw payload.
- An order can have several payment attempts; only one captures.
- The webhook is the source of truth for capture — never the redirect back, which
  a customer can close.

### C — Wallet
- `wallets` (one per user) and `wallet_transactions` (credit | debit, reason,
  reference, running balance).
- **Every balance change is a transaction row; the balance is derived, never
  edited.** A wallet you can set directly is a wallet nobody can audit.
- Funds a customer's «الدفع بالمحفظة», receives refunds, and — question 2 — may be
  where a driver's earnings land.

### D — Coupons
- `coupons` + `coupon_redemptions`, fixed or percentage, per-customer and global
  usage caps, validity window, minimum basket.
- Applied at `OrderPricing`, which already accepts and clamps a discount, so the
  arithmetic seam exists.

### E — Driver earnings
- `driver_earnings`: per completed task, a fee, and a state that begins **pending**
  («الرصيد المعلق») and later becomes payable.
- What makes it payable, and where it lands, is question 2.

### F — Refunds
- Requested by the customer, **reviewed** in the dashboard, then either credited to
  the wallet or sent back through the gateway.

### G — Invoices
- «تحميل الفاتورة». A rendered document, generated from the order's stored figures
  — never recomputed, for the same reason prices are copied.

### H — Verification
- A captured payment cannot be double-captured; a webhook replay is idempotent.
- A wallet balance always equals the sum of its transactions.
- A coupon cannot exceed its caps, its window, or the subtotal.
- Cash on delivery still marks paid only on a full collection (P8's rule holds).
- A refund cannot exceed what was actually paid.
- Full regression; both suites; stan and pint clean.

## Decisions taken

1. **Build the seam only.** A `PaymentGateway` contract and a `fake` driver now;
   the real provider is wired when it is chosen. Everything else in P9 is built
   and tested against the contract, so choosing a provider later is a new class
   rather than a new design.
2. **Driver earnings are a share of the delivery fee** — P9b.
3. **The wallet takes top-ups**, «إضافة أموال» and «سحب الرصيد» as the design draws
   them — P9b. Flagged: holding customer balances is a regulated activity in Egypt
   and worth confirming before it ships.
4. **Split, as proposed.** P9a below; P9b is the wallet, coupons, refunds and
   driver earnings.

The cash surcharge was not asked in the end — «قد يتم تطبيق رسوم إضافية» is
permissive («قد»), so P9a does not apply one. It is a line item in P9b if wanted.

---

# Laundo — P9a: Payments & Invoices

Status: **DONE** — 264 PHPUnit / 897 assertions, `composer stan` clean.

## Scope

What an order needs to be payable at all, and nothing more.

- The `PaymentGateway` contract, a `fake` driver, and the config seam.
- `payments`: one row per attempt, with the provider reference the design calls
  «رقم المعاملة».
- Initiating a payment, and **the webhook as the only thing that captures one** —
  never the redirect back, which a customer can close before it arrives.
- Cash on delivery keeps working exactly as P8 built it: paid only on a full
  collection, at the door.
- «تحميل الفاتورة» — rendered **from the order's stored figures**, never
  recomputed, for the same reason prices are copied.
- Payments visible on the dashboard's order page.

## Deliberately out of P9a
- Wallet, coupons, refunds, driver earnings — P9b.
- A real provider. Nothing can actually be charged until one is chosen; the fake
  driver makes every path testable meanwhile.
- **PDF.** No PDF package is installed, and adding a dependency is not a decision
  to slip into a phase. The invoice renders as a printable HTML page, which needs
  nothing and prints correctly.

## Review — what was built

**One rule shapes the whole phase: only the webhook captures.** A redirect the
customer has not followed has settled nothing, and a gateway's reply to `charge()`
means only that the request was accepted. Everything else follows from that — the
unique key on (provider, reference), the status check in `capture()`, the single
place an order becomes paid.

**The fake driver's most important property is what it refuses to do.** It does
not settle immediately. A fake that returned "paid" from `charge()` would let the
application be built around a flow no real provider offers, and the first real
integration would rewrite it.

**Cash was left exactly where P8 put it.** It creates no payment row and never
touches the gateway — routing it through one would invent a transaction that never
existed.

**A silent no-op nearly shipped.** The script registering the service provider
matched nothing, because `bootstrap/providers.php` uses imported class names, and
`str.replace` does not complain. The patch helper now asserts the file actually
changed. Worth remembering: **a replacement that matches nothing looks exactly
like a replacement that worked.**

## Follow-ups carried forward
- [ ] **No provider is wired.** Nothing can be charged. One class plus a config
      entry when the choice is made.
- [ ] No PDF — the invoice is a printable HTML page. Adding a PDF package is a
      dependency decision, not a phase detail.
- [ ] `payments.payload` is kept verbatim and never pruned. Fine now; a retention
      policy is worth having before it holds real card metadata.
- [ ] A part-payment is representable (`capturedTotal` sums captures) but no flow
      creates one. P9b's wallet is the first thing that could.

---

# Laundo — P9b: Wallet, Coupons, Refunds & Earnings

Status: **DONE** — 309 PHPUnit / 1059 assertions, `composer stan` clean.

Decisions taken:

- **Wallet takes top-ups** — «إضافة أموال» and «سحب الرصيد» as drawn. **Flagged:
  holding customer balances is a regulated activity in Egypt; worth confirming
  before this ships.**
- **Driver earnings are a share of the delivery fee**, landing in «الرصيد المعلق».
- Refunds carry the design's «قيد المراجعة» state — requested, reviewed, then
  credited or returned through the gateway.
- Coupons: «خصم الترحيب» exists in the design's own arithmetic, and
  `discount_total` has never once been non-zero.

Two of those were commercial numbers rather than design questions, so they were
built as **settings** instead of being invented or waited on: `Driver_Earning_Rate`
(default 20%) and `Cash_Surcharge` (default none, because «قد» is permissive).
A driver's pending balance becomes payable when the **order completes** — the money
has arrived by then.

## Review — what was built

**The wallet is a ledger.** Balance is a cache; the truth is the sum of the
transactions, and `isReconciled()` proves they agree. Every write happens under a
row lock, because two debits racing would each read the same balance and both pass
a check only one should.

**Pending money is deliberately outside the ledger.** Writing a row for money a
driver cannot touch would make the ledger disagree with the balance it exists to
explain.

**Checking a code never spends it.** Most baskets a code is asked about are never
ordered, and consuming a welcome code on a screen somebody walked away from would
be indefensible.

**A refund moves nothing until a person says so**, and the ceiling is what was
actually captured — refunding against money no gateway ever took would create it.

**Three bugs, one of them only findable by walking it.** The wallet payment
reference was built from a second-resolution timestamp, so a retry after a failed
attempt collided with itself on the unique key — a constraint violation where the
customer should have seen a second chance. No test caught it; the end-to-end run on
the live database did. The other two were a quote request that validated the coupon
code away before the service saw it, and a refund telling the customer to fix the
amount when the real problem was that nothing was refundable.

## Follow-ups carried forward
- [ ] **No payment provider.** The wallet works because it is our own ledger;
      nothing else can be charged.
- [ ] **Legal: holding customer balances is regulated in Egypt.** The top-up
      endpoint refuses rather than pretending. Worth confirming before it ships.
- [ ] Paying a driver out happens outside the app. A withdrawal records intent and
      debits the ledger; nothing here claims the money left.
- [ ] `Cash_Surcharge` is settable but not yet applied to an order's total.
- [x] ~~No dashboard screens for coupons, refunds or wallets~~ — **built**, see
      below.

---

# Laundo — The money screens

Status: **DONE** — 330 PHPUnit / 1136 assertions, 11 Playwright, `composer stan` clean.

Built because P9b left its services unreachable: operations could not create a
coupon or approve a refund except through code.

## What was built
- **Discount codes** — CRUD on the module pattern, AJAX search, status toggle. The
  two caps are labelled by the question each answers, and the value hint changes
  with the type.
- **The refund queue** — «قيد المراجعة» as a worklist, with a second counter for
  what was *approved but never paid out*. Approving asks where the money goes,
  because a wallet credit is instant and a gateway refund is not.
- **The wallet screen** — whose real job is proving the ledger. Every row and every
  detail page states whether the cached balance still equals the sum of its
  transactions.

## The rule the screens had to not break
**No screen sets a balance**, because nothing anywhere sets a balance. An
adjustment writes a transaction like every other change, its reason is required,
and it is recorded against the person who made it. A Playwright test asserts that
no balance field exists on the page at all.

## Follow-ups
- [ ] `Cash_Surcharge` is settable but still not applied to an order's total.
- [ ] Driver earnings have no dashboard view of their own — they are visible in the
      driver API and in the wallet ledger, but operations cannot see a per-driver
      summary.
- [x] ~~The Arabic translations~~ — **done**, see below.

---

# Laundo — Arabic translation

Status: **DONE** — 743 entries, 8 Playwright tests, 330 PHPUnit / 1136 assertions,
`composer stan` clean.

## What was wrong

`resources/lang/ar.json` existed but held **ten stub entries copied from a
template**, one of them reading «web Dashboard». Every one of the 692 translatable
strings in the codebase fell through to its English key. Six phases of work had
been rendering English labels in a product whose design is entirely Arabic.

## The decision that mattered

**Use the design's own words.** «مراجعة القطع», «السعر النهائي», «الرصيد المعلق»,
«تعذر الاستلام», «بانتظار استلام القطع» are the product's vocabulary, taken from
the Figma screens. Inventing a synonym would make the dashboard and the apps
disagree about what a thing is called — which is worse than leaving it in English,
because English is at least obviously untranslated.

## Why it is provably complete rather than complete-looking

Keys were extracted mechanically from every `__()`, `@lang()` and `trans()` call
across `app/`, `resources/views/`, `routes/`, `config/` and `database/seeders/`,
then checked back against that list. Three guards ran before the file was written:
no value still in English, no value empty, and **no `:placeholder` lost** — a
message that drops `:code` renders as a sentence with a hole in it.

42 entries do not appear in the extraction and are correct regardless: enum labels
and menu titles reach `__()` through a variable, which no regex sees. That is
precisely the class of string a naive pass leaves behind, so the browser tests
assert an order status renders in Arabic.

## Two ways the work would have been lost, found by looking

**It would not have survived a deploy.** `resources/lang/.gitignore` ignored `*`,
so the file existed on one machine and nowhere else. That rule was right when the
files held ten generated keys; it stopped being right the moment the product's own
copy went in. Copy is source, not build output.

**It would not have survived somebody adding a language.**
`generateJsonLanguageFiles()` overwrote `{code}.json` wholesale from the ten-key
templates — and `LanguageSeeder` calls it for `ar`. Running the seeder would have
erased the lot without a word. It now merges, existing values winning. Proven by
running the seeder against the finished file and watching the count rise rather
than fall.

Neither was a bug in the translation. Both were reasons the translation would have
quietly disappeared, which is the sort of thing worth checking before calling a
job done.

## Follow-ups
- [ ] `ar_panel.json`, `ar_mobile.json` and `ar_web.json` are still stubs. They are
      the API-facing scoped files that serve the mobile and web clients, generated
      by `LanguageHelper` from the `storage/app` templates — a separate job with a
      different key list, not part of the dashboard.
- [ ] Dashboard **data** columns still render the default language rather than the
      request locale (`getLocalizedValueDashboard`). Deferred by decision in P0 and
      still open; a service named «غسيل وكي» shows its Arabic name because Arabic
      is the default, not because the panel is in Arabic.

---

# Laundo — P11: Notifications

Plan reference: خطة داشبورد Laundo, phase P11. Depends on P4–P9b.
Status: **DONE** — 362 PHPUnit / 1221 assertions, `composer stan` clean.
Scope note from the business owner: **build it apart from the SMS integration
itself.**

## Objective

Tell people things. Every phase so far has been careful to leave a seam where a
notification belongs and to say so — the recurrence prompt «محتاج تغسل النهاردة؟»
records a question nobody delivers, the customer is never told «السعر النهائي
جاهز», and a driver is never told a task is theirs. P11 is the delivery.

## Current state (checked, not assumed)

- **The SMS seam already exists and works.** `App\Services\Sms\SmsSender` with a
  `LogSmsDriver`, `config/sms.php`, bound in `AppServiceProvider`, and `OtpService`
  already calls it. So «apart from the SMS integration itself» is already the
  shape of things: nothing to undo, and a real Egyptian provider stays one class
  plus one config line.
- `notifications` (Laravel's database channel) exists and holds **zero rows**.
- `App\Notifications\AdminNotification` exists — title, message, url, meta — and
  goes to `database` only. `NotificationController` can list unread, mark one
  read, mark all read. Nothing dispatches it.
- `queue.default` is **database**, and `jobs` / `failed_jobs` exist. So a queued
  notification is deliverable without new infrastructure — but note the queue
  worker has to actually be running (`composer dev` starts one).
- Missing entirely: templates, per-user preferences, device tokens, a delivery log.

## Figma reference — and an honest gap

**The design does not specify notification content.** What it does specify:

- «الإشعارات» is a **tab in the driver app's bottom navigation** (`193:1935`,
  `225:6813`) — but no frame draws what is inside it.
- «الإشعارات» is a **toggle in the customer's account screen** (`141:2291`), under
  «إعدادات التطبيق» — so preferences are a real feature, not an invention.
- «**تم إخطار العميل** وتمت إضافة أرباحك إلى الرصيد المعلق» (`231:7325`) — the
  system notifies the customer when a driver completes a task.
- The user app's own Notifications frame (`24:1488`) is **a template placeholder
  from a different product**: «Payment Successful — Your payment of $150.00 for
  Full Synthetic Oil Change» and «Booking Confirmed — Brake Inspection». It is
  car-servicing boilerplate that was never replaced.

So the *moments* come from the business flow, which is fully built and known. The
*copy* has to be written, and it will reuse the vocabulary already agreed in the
Arabic translation — «تمت مراجعة القطع», «السعر النهائي», «في الطريق للاستلام» —
so an app notification and the screen it links to say the same words.

## Implementation plan

### A — Channels
- **database** — the in-app list both apps show. Always on; it is the record.
- **sms** — through the existing `SmsSender`. Costs money per message, so it is
  reserved for the moments in question 1.
- **push** — a `PushSender` seam with a `LogPushDriver`, mirroring SMS exactly,
  plus a `device_tokens` table. **No vendor integration**, same rule as SMS.

### B — One notification class per moment, not one for everything
Each carries its own title, body, deep link and channel list, so «السعر النهائي
جاهز» can be an SMS while «تم تعيين مهمة» is push-and-database only.

### C — The moments
| Moment | Who hears | Why it matters |
|---|---|---|
| OTP | customer / driver | already sent; unchanged |
| Order placed | customer | receipt of intent |
| Driver on the way | customer | «في الطريق للاستلام» — they need to be in |
| **Final price ready** | customer | **the order stops until they answer** |
| Price confirmed | laundry | work may start |
| Ready for delivery | customer | |
| Delivered | customer | |
| **Recurrence prompt** | customer | «محتاج تغسل النهاردة؟» — the whole feature |
| Task assigned | driver | they cannot act on what they do not know about |
| Task queued too long | operations | the dispatch queue is otherwise silent |
| Refund decided | customer | |
| Price question answered | customer | |

### D — Preferences
`notification_preferences` per user and channel, honouring the design's toggle.
**Transactional messages ignore it** — a customer who muted notifications still
has to be told the order is waiting on them, or the order simply stalls.

### E — Delivery log
`notification_logs`: what was sent, to whom, on which channel, and whether it
failed. Without it an SMS bill is unauditable and a "I never got it" is
unanswerable.

### F — Dashboard
A notifications page for operations, and the existing topbar bell wired to real
data rather than an empty table.

### G — Verification
- Each moment fires exactly once, and a retried transition does not double-send.
- A muted user still receives transactional messages.
- A failed channel is logged and does not break the business action that triggered
  it — a failed SMS must never roll back a delivery.
- Full regression; both suites; stan and pint clean.

## Decisions taken

1. **SMS is for authentication only.** No business moment sends one. That is
   already how the system behaves, so it costs nothing to honour and it keeps the
   per-message bill to what security requires. Every other moment reaches people
   in-app and by push.
2. **Build FCM properly**, not a placeholder.
3. **Yes, operations gets told** when a task has been sitting unassigned — as a
   dashboard notification. The queue counter exists but nobody is prompted to look
   at it, and a task idle for two hours is a customer waiting while nobody knows.

### On "build FCM properly", and what it costs

Implemented against Google's **HTTP v1 API directly — with no new Composer
dependency**. The usual route is `kreait/firebase-php`, which drags in a large
tree; the whole of what is needed here is an RS256 JWT signed with `openssl`, a
token exchange, and one POST. Adding a heavy dependency is a decision that belongs
to the owner of the project, and it is avoidable, so it was avoided.

**What cannot be proven here:** a real send needs a Firebase service-account JSON,
which this environment does not have. The driver is complete and its parts are
unit-tested, but until credentials exist `config/push.php` stays on the `log`
driver and no message reaches a handset. Said plainly rather than reported as
working.

## Review — what was built

**The announcement lives in the state machine**, because it is the only thing
that sees every move an order makes. Wiring notifications into the eight call
sites that shift a status would mean forgetting the ninth.

**The line that mattered most to draw** is `isTransactional()`. A muted customer
still hears «السعر النهائي جاهز», because an order nobody confirms is an order
that stops — and they would never learn why. The toggle silences noise, not the
messages the order depends on.

**FCM, honestly.** Complete, dependency-free, and unit-tested against a faked
Google. What is *not* provable here is a real send: that needs a service account
this environment does not have, so the config stays on the log driver and no
message has reached a handset. The distinction between a dead token (400/403/404
→ prune) and a busy Google (5xx/429 → keep) is the part that costs real devices if
it is wrong, so it has a test per status.

**A test bug that looked like a driver bug.** A loop over HTTP statuses was
testing the same response three times, because `Http::fake()` *merges* stubs
rather than replacing them. Worth remembering.

**The Arabic gap nearly reopened the day after it closed.** 32 new strings —
including every notification a customer will ever read — arrived in English. The
extractor caught them, and the conflict check caught `Failed` being translated two
different ways in two files.

## Follow-ups
- [ ] **No Firebase credentials**, so push is unproven end to end and the log
      driver is active. One env var and a JSON file away.
- [ ] The topbar bell still reads the old `NotificationController` endpoints;
      operations' own notifications now exist, so it has real data to show but the
      widget has not been re-pointed.
- [ ] No digest or batching: a customer whose order moves three times in a minute
      gets three notifications.
- [ ] `notification_logs` grows without a retention policy — fine now, worth a
      prune before it holds a year of sends.
- [ ] Device tokens are never pruned for age, only on permanent rejection.

---

# Laundo — P12: Reports

Plan reference: خطة داشبورد Laundo, phase P12. Depends on every phase before it.
Status: **AWAITING APPROVAL — no code written yet.**

## Objective

Answer the questions the business will actually ask. Everything needed to answer
them has been recorded carefully for eleven phases — every price copied, every
status change logged with an actor, every task timed, every notification and its
outcome — and none of it has ever been added up.

## Current state (checked, not assumed)

- **Nothing reporting exists.** No `app/Modules/Report`, no controller, no views.
  The only aggregate anywhere is the home page's permission-gated count tiles.
- Every source is populated and correct: 11 orders across 8 statuses, 28 tasks, 58
  status logs, 5 payments, 8 driver earnings, 7 wallet transactions, 1 refund, 1
  coupon redemption.
- `orders` carries everything a revenue report needs — both totals, the fee, the
  discount, `paid_at`, `confirmed_at`, `laundry_id`, `status`.
- **The tenant scope does the access control for free.** `Order` is scoped, so a
  laundry owner running a report sees only their own work and a super admin sees
  everything, with no report-specific rule to get wrong.

## Figma reference

**None.** The Figma file has four pages — cover, the customer app, the driver app,
and drafts — and no dashboard at all. Every dashboard screen this project has has
been built from the business flow and the project's own conventions, and reports
are no different. Said plainly rather than implied.

## The five reports worth building

### 1. Revenue
Over time, by laundry, by service, by zone. Gross, delivery fees, discounts given,
refunds paid, net. **This is where question 1 below bites.**

### 2. Orders
Volume by day and status, cancellation rate, unassigned rate — and the one nobody
else will think to ask for: **how often the review changes the price, and by how
much.** That number tells the business whether customers under-count by habit,
which is worth knowing before it becomes an argument.

### 3. Laundry performance
Orders handled, turnaround (`picked_up` → `ready_for_delivery`, from the status
log), review rounds per order, how often a customer disputed a price.

### 4. Driver performance
Tasks completed and failed, average duration, lateness rate, failure reasons
broken down, earnings.

### 5. Operations health
The report that earns its place: **orders sitting at `reviewed` with nobody
answering** (the silence problem flagged in P7 and never solved), tasks queued too
long, unassigned orders, failed notifications, and **any wallet whose cached
balance disagrees with its ledger**. Every one of these is a follow-up raised in an
earlier phase that currently has no home.

## Implementation plan

- `app/Modules/Report/` — a query service per report, no new tables. Reports read;
  they do not store. A reporting table is a second copy of the truth that drifts.
- A date range on every report, defaulting to the last 30 days.
- CSV export per report, streamed rather than built in memory.
- Charts on the revenue and orders reports using the vendor template's existing
  chart library — no new dependency.
- The home page's tiles re-pointed at the same services, so the dashboard and the
  reports cannot disagree.

## Verification
- Every figure reconciles against the source rows, checked in a test rather than
  by eye — a report that is merely plausible is worse than none.
- A laundry owner's report contains only their own orders.
- An empty range renders zeroes, not a crash or a blank page.
- Full regression; both suites; stan and pint clean; Arabic complete.

## Decisions taken

1. **Revenue is paid orders**, cash and card alike. It is the honest figure
   because a driver does not mark an order paid until the whole amount is in hand
   — P8 enforces that — so `payment_status = paid` means money actually arrived.
   Summing `payments` instead would silently omit every cash order, and cash is
   the larger share in this market.
2. **A delivered-but-unpaid order is shown as receivables**, on its own line and
   never inside revenue. It is not income; it is a number somebody has to chase,
   and an order that was handed over and never paid for is money lost the moment
   nobody is looking at it.
3. **Refunds are deducted on the day they were paid out.** A closed report that
   changes retroactively is a report nobody can trust, and it matches the cash
   actually leaving.
4. **Super admins and laundry owners both get reports**, the tenant scope doing
   the work — with one exception below.

### The exception worth naming

Operations health and driver performance are **super-admin only**. A laundry's own
revenue and turnaround are its business; how many tasks a driver failed across
every laundry is not, and the tenant scope would not stop that leak on its own
because drivers are not tenant-scoped. That is a rule the reports have to enforce
themselves.

## P12 — Reports: review

Built and verified.

- [x] `DateRange` value object — the window every report shares, with the ways it
      gets asked wrong handled once instead of five times.
- [x] `RevenueReport` — paid orders by `paid_at`, refunds by `settled_at`,
      receivables on their own line and never inside net.
- [x] `OrderReport`, including price movement: what the review actually does to
      the price, in which direction, and by how much.
- [x] `LaundryReport` — turnaround from `picked_up` to `ready_for_delivery`,
      `null` rather than `0` when there is nothing to measure.
- [x] `DriverReport` and `OperationsReport`.
- [x] Five Blade screens, a shared range picker and a div-based bar chart.
- [x] `report.view` / `report.update` generated by the usual `PermissionGenerator`
      machinery via a marker model, not hand-inserted.
- [x] CSV export, streamed, UTF-8 BOM.
- [x] 70 new strings translated to Arabic **in the same phase that added them**.
- [x] 20 PHPUnit tests, 11 browser tests, stan clean, pint applied.
- [x] Cross-checked every headline figure against raw SQL on the dev database.

### What the live walk found

An unbounded range — `?from=1900-01-01` — built a daily series of **36,525
entries**, one chart bar each. Nothing in the test suite would have caught it,
because no test asks for a range no reasonable person would type. But the dates
come off a URL designed to be bookmarked and pasted between people, and a URL
like that arrives eventually.

Fixed by clamping to `DateRange::MAX_DAYS` (366), keeping the end that was asked
for and moving the start forward — a report is read backwards from a known date.

The clamp then exposed a second thing: the range form echoed `request('from')`
rather than the window actually used, so a clamped or swapped range would have
shown dates the figures below it did not cover. The form now renders `$range`,
which makes every correction visible. Both were found by walking the live
database, not by a test — the same technique that found the task-generation
timing, the unrevivable escalation and the wallet reference collision.

### Still open after P12

- [ ] **No payment provider.** Unchanged and still the largest gap: the revenue
      report is correct about money that cannot yet be taken.
- [ ] **Legal: holding customer balances is regulated in Egypt.** Still needs an
      answer from outside the code.
- [ ] Reports have no scheduled delivery — nobody is emailed a Monday summary.
      Operations health in particular is only useful if somebody opens it.
- [ ] Turnaround is measured only where `order_status_logs` has both ends. Orders
      predating the logging show as unmeasured, which is honest but thin.
- [ ] No export for laundry, driver or operations reports — revenue and orders
      only.
- [ ] `Cash_Surcharge`, «طلب التأجيل» rescheduling, and the missing timeout on a
      customer's silence at `reviewed` all carry forward untouched.

### Next

P10 — growth and support — is the only phase never started.

---

## Design pass — 2026-08-31

### Done: badge and text contrast

The Wallets "Active" badge report turned out to be one symptom of a token the
vendored template repurposed. Root cause, fix and reasoning are in the changelog
and in the comment block at the foot of `public/assets/css/theme.css`.

- [x] `.text-dark` restored to an actually dark colour, in `theme.css`, without
      touching `--bs-dark-rgb` (which vendored components use to mean page bg).
- [x] `bg-info` / `bg-warning` / `bg-light` badges now derive dark text from the
      background, so a future badge written without `text-dark` is still correct.
- [x] Footer 2.80:1, roles screen 2.14:1, revenue-report amber 1.63:1 — all fixed.
- [x] 20 browser tests measuring computed contrast. 0 failures across 11 screens,
      in light mode, dark mode and RTL.

**The lesson worth keeping:** the markup was right the whole time. `badge bg-light
text-dark` is exactly what you would write. A test asserting those classes would
have passed for as long as the bug existed. Only measuring the rendered value
could fail — and once it could, it immediately found four more failures than the
one a human had noticed.

Two false readings the sweep produced before it was correct, both now guarded:
the preloader is a fixed white 80% sheet at z-index 9999, and a gradient lives in
`background-image` so `backgroundColor` reads transparent — the latter reported
the navy sidebar at 1.46:1 when it is 11.25:1. An audit that cries wolf is worse
than no audit.

### Done: the dashboard adopted the app's brand

Figma holds **168 frames, every one 375px or 390px wide** — the two mobile apps
and nothing else. There is no dashboard design in the file, so "compare the
dashboard to Figma" had nothing to compare against. What could be compared was
the palette, and it matched on no role at all.

A first read of five frames reported the app as a Material 3 teal ramp. That was
wrong, and the correction matters: across thirty frames the palette **splits by
page**.

| page | `#2563EB` blue | `#00696F` teal |
|---|---|---|
| `ui` (customer app) | 51 | 0 |
| `delivery` (driver app) | 104 | 0 |
| `drft` | 0 | 25 |

Two live palettes in one file — Tailwind blue on the two finished-looking pages,
Material 3 teal on the newer `drft` page whose frames match the phases actually
built (P7–P9). Genuinely contradictory, so it went to the owner rather than being
guessed. **`ui` / `delivery` were named authoritative.**

- [x] `--brand-gold` / `--brand-gold-2` renamed to `--brand-primary` /
      `--brand-primary-soft`, plus `--brand-primary-dark`. Renamed, not just
      recoloured: a token called gold holding a blue is a trap.
- [x] Sidebar navy `#101828` → `#0f2d52`, accent `#c9a227` → `#2563eb`,
      surfaces/borders/text taken from the app palette.
- [x] The two pairings that had to **flip**: the active sidebar pill's text
      (navy → white, because navy on blue is 2.68:1) and the login button's
      gradient (primary → primary-dark, because the light accent would have
      swallowed white text).
- [x] Bootstrap's `--bs-primary` (#2070b0), `--bs-primary-rgb` (#435ebe) and
      `--bs-primary-rgba` (a cyan) were three different colours behind one name,
      so `.btn-primary` and `.bg-primary` never matched. All three unified.
- [x] All fourteen affected pairings measured before writing a line. All pass.
- [x] Verified after the swap, not only before: 140 browser tests, 0 elements
      below WCAG AA, in light mode, dark mode and RTL.

### Done: five sidebar items were never translated

Banners, Intros, Countries, Cities, Roles — English inside an otherwise fully
Arabic menu. They live in `config/menu.php` as plain strings and only meet `__()`
at render time, so the extract-every-`__()`-call check that closed the Arabic gap
twice could not see them.

- [x] Translated, and covered by `TranslationCoverageTest` rather than fixed once.
      The gap reopens the moment somebody adds a module, because the checklist in
      CLAUDE.md says to add a `titles` entry and says nothing about translating it.
- [x] That test also checks the three parallel menu maps agree, since a key in
      `titles` but not `icons`/`routes` renders as a blank sidebar row that looks
      like a permission problem.

### Also worth knowing

- [ ] No dashboard design exists in Figma at all. Every dashboard screen built so
      far was designed in-repo. That is fine, but it means "check Figma" is not
      available as a review step for dashboard work — and it is why the laundry
      screen in P7 was designed here on the owner's instruction.
- [ ] **Figma still holds two contradictory palettes.** `drft` is on the Material 3
      teal while `ui`/`delivery` are on the Tailwind blue the dashboard now uses.
      Worth reconciling in Figma itself, or the next person reads the wrong page.
- [ ] The logo renders as a broken image on the login screen and as a NOT FOUND
      placeholder in the sidebar — the `App_Logo` setting has no file behind it.
      Unrelated to the palette, but it is the first thing anyone sees.
- [ ] The contrast sweep covers 11 screens. Order detail, review, dispatch,
      refunds, notification log and the language screens are not in the list yet.

## Gap audit — 2026-08-31

A full sweep of dashboard vs API vs Figma, asked for rather than assumed. Four
categories of gap; the owner chose to do all four in order.

### Task 1 — DONE: the dead content is connected

Three admin screens were writing content nothing could read. Same shape as a
column added with no form field: complete from either end, dead in the middle.

- [x] `GET /api/v1/banners` — active only, localised, `action` resolved server-side
- [x] `GET /api/v1/intros` — ordered by `order` then **id**, so two slides sharing
      a number cannot arrive in a different sequence on two installs
- [x] `GET /api/v1/app-settings` — explicit allow-list. `Tax` and `Country_Id`
      excluded and tested for; a `Setting::all()` endpoint would leak whatever
      anybody adds next
- [x] `GET /api/v1/pages/{about|privacy|terms}` — one wall of HTML at a time
- [x] Banner target: `BannerTarget` enum + migration + validation (including the
      **pair** rule, which per-column rules cannot express) + service
      normalisation + **a form field**
- [x] `/admin/recurrence` — the repeat schedules, super-admin gated
- [x] The logo, in three variants, wired everywhere including the API
- [x] 37 new tests. `stan` clean, pint applied, Arabic gap zero.

**What the work turned up that nobody had asked about:**

- `where('answer', 'yes')` matched nothing — the enum is `confirmed`/`declined`.
  The "became orders" column would have read 0 forever with no error at all.
- `App_Logo` shipped from the template as `'logo1.png'` with no such file, so the
  broken image on the login screen was the *setting being honoured*, not ignored.
- The brand mark is navy and the sidebar is now navy: 1.08:1. Two files needed.
- Two `confirm('{{ __(...) }}')` handlers that a translated apostrophe would have
  silently disabled, on destructive actions.

### Task 2 — DONE: ratings

Owner's decision: **the laundry is rated**, and the rating covers more than one
aspect. Figma confirmed a 5-star overall («سيء» to «ممتاز»), three 5-star aspects
(جودة الخدمة, التوصيل والاستلام, التوقيت), five chips, a free-text box, and «تخطي».

- [x] `order_ratings` — one row per order (unique index, not a code check),
      `laundry_id` denormalised so BelongsToLaundry scopes it
- [x] `overall` required; the three aspects nullable, because «تخطي» exists — and
      **null, not zero**: a 0 would drag every average down while looking like data
- [x] `RatingTag` enum for «ما الذي أعجبك؟»; labels served by the API
- [x] `POST`/`GET /api/v1/orders/{id}/rating`, with `can_rate` so the app knows
      whether to draw the button at all
- [x] Ratings screen + a rating column on the laundry report
- [x] `order_rating.view` granted to the laundry roles — this one IS tenant-safe,
      unlike the driver and operations reports
- [x] 33 tests. `stan` clean, Arabic gap zero.

**The tension I raised and worked around rather than argued about:** «التوصيل
والاستلام» and «مندوب ودود» describe the driver, not the laundry. The row is
attached to the laundry as decided, but each aspect is its own column — so a
laundry is not marked down for a late driver, and the delivery score can be
attributed to the driver later without a migration.

- [ ] **Open:** whether a customer may change a rating after sending. Built as
      one-shot and final; say so if that is wrong.
- [ ] The notes box says «اكتب ملاحظاتك أو شكواك» — a low score with a comment is
      a support case. Task 3 should read from `order_ratings` rather than build a
      parallel complaint channel.

### Task 3 — DONE: P10 support and complaints

Figma places «المساعدة والدعم» as a **section in the driver's account screen**
(الأسئلة الشائعة / تواصل معنا / تقديم شكوى) and as a **button on the customer's
order-detail screen**. «تواصل معنا» was already finished in task 1 — the contact
details reach the apps through `/app-settings`.

Owner's decisions: operations replies **by phone**, the category is a **closed
set**, and complaints are **platform-admin only**.

- [x] FAQ module + `GET /api/v1/faqs`, with an `audience` column so the two apps
      do not read each other's answers
- [x] `complaints` table, `ComplaintCategory` and `ComplaintStatus` enums
- [x] `POST`/`GET /api/v1/complaints`, `GET /api/v1/complaint-categories`
- [x] Queue screen: oldest open first, a "waiting over a day" counter, a category
      tally, status transitions and appending internal notes
- [x] Low ratings with a comment surface in the same queue
- [x] `complaint.*` super admin only; `Complaint` deliberately does **not** use
      BelongsToLaundry
- [x] 30 tests. `stan` clean, Arabic gap zero, contrast sweep widened to 15 screens.

**The consequence of "reply by phone" I had to handle:** a complaint that lands
and shows nothing back is a black hole. So the API returns a quotable `CMP-`
reference and the complainant can watch the status move. That is not a reply — it
is the minimum that stops the feature feeling broken.

- [ ] Nothing notifies operations that a complaint arrived. The queue has to be
      opened. P11's notification machinery is right there and this does not use it.
- [ ] A complaint is never notified back to the complainant either — by design,
      but a "we have closed this" push would cost little.

### Task 4 — DONE, and my framing of it was wrong

I described this as "two notification systems, the bell stuck on the old one".
It is not. The bell reads Laravel's `notifications` table, which P11's dispatcher
writes to; the log reads `notification_logs`, an audit record of every delivery
attempt. Different tables, different audiences, both connected and both correct.

What was actually wrong, and is now fixed:

- [x] Two route prefixes one letter apart — `admin.notification.*` (log) and
      `admin.notifications.*` (bell). The bell's group is now
      `admin.myNotifications.*`.
- [x] No page listed an operator's own notifications. The bell is a ten-item
      dropdown, so an alert that scrolled past the tenth was gone. `/admin/my-notifications`
      now lists them, unread first.
- [x] `markAsRead` returned JSON to a form navigation. Answers both callers now.
- [x] Complaints notified nobody. A new complaint now alerts operators through the
      existing dispatcher — transactional, and non-throwing so a Firebase outage
      cannot lose an accepted complaint.

### The dashboard home page — replaced

Asked for directly: the page opened with total customers, total laundries, total
categories, total banners. Vanity counts. Rebuilt on one rule — **every number is
either happening now or waiting for a person**.

- [x] `DashboardSummary`, reusing the report services so definitions cannot drift
- [x] The queue first, above every statistic, each row with a count, a reason and
      a link — and **never a zero**, because a column of noughts gets skimmed
- [x] «Right now»: where every live order physically is
- [x] Two different pages: the laundry's working day in order, versus dispatch,
      drivers, platform money and an oldest-first attention list
- [x] Driver figures **withheld** from a laundry, not filtered — tasks carry no
      `laundry_id`, so the scope would not have protected them
- [x] 22 tests on the summary, two stale tile tests replaced

### Still not covered anywhere

- [ ] **Saved cards.** «إضافة بطاقة جديدة» in Figma; `payment-methods` returns the
      enum (cash/card/wallet), not stored cards. Blocked on the payment provider.
- [ ] `Payment` has no list screen of its own — payments are visible only inside an
      order, so there is no reconciliation view.
- [ ] `APP Name` is still `BaseCode` in the settings table. That string reaches
      customers through `/app-settings`.
- [ ] `Country_Id` and `Tax` are set but only `Tax` is applied server-side; worth
      confirming `Country_Id` is read anywhere at all.

### Next

P10 — growth and support — is still the only phase never started.

---

# Laundo — 2026-09-01: the last three of the "half built" list

Three items were left after the ledger screens, each needing the owner's decision
first. All three were answered and are built.

## «مرجع العميل» and the printable card

- [x] **Decision:** a sequential number per customer, fixed for life
- [x] `users.customer_reference`, unique, nullable, backfilled oldest-first
- [x] Sequential over **customers alone** — drivers and staff share the table, so
      the user id would tell the 3rd customer they are the 12th
- [x] Assigned by a `created` hook on the model, not per registration path: four
      paths make a customer, and the one that forgets produces a bag with nothing
      printed on it
- [x] Next number read off the **highest reference, not a row count** — a deleted
      customer must not hand their number on, because labels outlive accounts
- [x] `ticket` block on the driver's task detail: order number, reference,
      service, date, destination, QR
- [x] Searchable on the customers screen; shown read-only on the customer's page
- [x] 13 tests

**Noted while there, not asked about:** the ticket carries the QR token, so a
driver's app could in principle confirm a scan without being present. The scan
exists to catch the wrong bag off a pile, not to prove attendance. If it is ever
meant to be the second, it needs a short-lived token of its own — written in the
code beside the field.

## Quote-priced services

- [x] **Decision:** the laundry types the unit price per line
- [x] Review form renders a price input for a quote-mode service, read-only
      otherwise; the running total reads the typed box
- [x] Every active piece offered — there is no matrix to filter the catalogue by
- [x] A posted price is **ignored** for anything priced per item
- [x] A counted piece with no price is refused; a piece counted to zero needs none
- [x] A re-count keeps the prices already entered
- [x] 10 tests

## Weekly report emails

- [x] **Decision:** weekly, super admins plus each laundry its own
- [x] `laundo:weekly-reports` with `--dry-run` and `--only=`, Sunday 08:00,
      covering the seven days up to and including yesterday
- [x] A laundry's figures come from the **same report services run as its owner**,
      so the emailed number cannot drift from the screen; the guard is restored in
      a `finally`
- [x] Figures in the body, CSV attached with a BOM so Excel opens Arabic properly
- [x] The queue of things waiting for a person sits above the statistics, and a
      zero never appears in it
- [x] One bad address is logged and skipped, never thrown
- [x] 12 tests

## Found on the way

- [x] `OrderRepository::EAGER` selected `service:id,name`, so `isPerItem()` read
      null and **every order claimed to be quote-priced**
- [x] The customers screen's status toggle was disabled for everyone but the super
      admin — a bool passed where the component wanted a slug, and the slug was
      wrong anyway
- [x] `DriverEarning::driver()` is role-scoped, so money owed vanished when a
      driver stopped being a driver; added an unscoped `payee()`
- [x] Two flaky tests of my own: a harness helper that inserted twice, and a
      month-boundary assumption that fails on the 1st

## Still not covered anywhere

- [ ] **Saved cards.** «إضافة بطاقة جديدة» — blocked on the payment provider.
- [ ] `App_Name` is still `BaseCode` in the settings table; that string reaches
      customers through `/app-settings`.
- [ ] No payment provider at all, so nothing can actually be charged.
- [ ] No Firebase credentials, so push is unproven end to end.
- [ ] Holding customer balances is a regulated activity in Egypt — needs a legal
      answer before the wallet is used for real money.
- [ ] Figma still holds two contradictory palettes (`ui`/`delivery` blue against
      `drft` teal). The dashboard follows the blue.

---

# Laundo — 2026-09-01: the Figma sweep

168 designed frames (3 pages) against 96 endpoints. Ten gaps found; six built here,
one blocked on a decision, two are whole features needing business rules, one was
already known and blocked on the payment provider.

## Built

- [x] **«انستا باي» refused at the last step.** `OrderRequest` hand-listed three
      methods while the enum, the quote and the payment endpoint all carried four
- [x] **The cash surcharge had no line on the placed order** — only on the quote
- [x] **«إظهار رمز الاستلام (QR)»** — the token had never reached the customer
- [x] **«مندوب الاستلام · أحمد · ★ 4.9»** — the tracking endpoint returned no
      driver at all; the star is `order_ratings.delivery` alone, null not zero,
      and no phone number
- [x] **«المرفقات» on complaints** — plus showing them on the operations screen
- [x] **«مستندات المركبة»** — columns since P5, never in the payload

## Verified as already working (the sweep's own false leads)

Guest browsing · order photos at creation · task search by order number and
customer name · vehicle, licence, shift and zones read-only by decision · wallet
top-up and withdraw · reorder · rating aspects · coupons · recurrence ·
notification preferences. «نقاط الولاء» exists only in the `drft` page and was
dropped from the final `ui` — not counted as missing.

## Waiting on the owner

- [ ] **«تتبع المندوب مباشرة» — live driver location.** Two screens depend on it
      (`538:4654` and `416:6140`) and nothing exists on either side: no
      coordinates on the driver, no endpoint to push them, none to read them.
      Needs a decision on how often a phone reports and whether the trail is kept.
- [ ] **«الاشتراك الشهري»** — monthly packages on the final home screen. Not the
      same thing as `recurrences`, which is free scheduling.
- [ ] **«ادعُ أصدقاءك / رمز الدعوة»** — a referral code on the final account
      screen, with «خصومات حصرية لك ولهم».
- [ ] **«إضافة بطاقة جديدة»** — still blocked on the payment provider.

