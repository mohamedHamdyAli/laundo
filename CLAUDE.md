# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer dev          # server + queue:listen + pail logs + vite, concurrently
composer test         # config:clear then artisan test (PHPUnit 11 — NOT Pest)
composer test:browser # npx playwright test (needs a server already running)
composer test:all     # composer test, then the browser suite
composer stan         # phpstan analyse app --level=5 (via larastan)
./vendor/bin/pint     # formatter (no composer script)
vendor/bin/php-cs-fixer fix          # second formatter, config .php-cs-fixer.dist.php
vendor/bin/rector process --dry-run  # config rector.php
php artisan ide-helper:models -W     # refresh model @property docblocks
npm run build / npm run dev
```

Single test: `php artisan test --filter=TestName` · one file: `php artisan test tests/Feature/Api/OrderTest.php` · one suite: `php artisan test --testsuite=Unit`. One browser spec: `npx playwright test tests/browser/<name>.spec.js`.

PHPUnit runs against in-memory SQLite (`phpunit.xml`); the app itself runs on MySQL. Anything relying on MySQL-only SQL will pass in tests and fail in the app.

## Stack

Laravel 13 · PHP ^8.3 · MySQL · Sanctum (mobile API) · `laravel/ui` (Bootstrap auth scaffolding) · Vite 6 · Playwright (browser tests).

## Architecture

**Two surfaces over one codebase**: a Blade admin panel under `/admin`, and a stateless JSON API under `/api/v1` serving a customer app and a driver app. They share the modules, the models and the repositories; they do not share controllers, requests or response conventions.

### Module structure

`app/Modules/{Name}/` — Controllers, Models, Repositories, Services, Requests, and often `Enums`. 31 modules: Address, Banner, City, Complaint, Country, Coupon, Driver, Faq, Intro, Item, ItemCategory, JourneyStep, Laundry, LaundryService, LaundryStaff, LaundryZone, Moderator, Notification, Offer, Order, Payment, Pricing, Rating, Recurrence, Report, Service, Setting, TimeSlot, User, Wallet, Zone.

Not every feature is a module. **Role**, **Language** and **Notification** admin screens live in `app/Http/Controllers/Admin/` + `app/Models/` instead — when touching those, don't look for a module directory.

Read **`app/Modules/Offer/`** end to end for the module contract at its cleanest; **`app/Modules/Coupon/`** for a service with real business rules in it.

### The layer contract

Controllers do HTTP only and delegate to the service; **repositories are the only place raw Eloquent queries live**; services own business logic and wrap every write in `DB::transaction`.

Every CRUD service exposes **`shredData($id = null)`** — the universal view-data assembler. It returns the list under a plural key and, when `$id` is given, the single record under **`row`**. Controllers pass its result straight to the view; Blade partials expect `$row`. New modules must follow this or the shared partials/components won't fit.

### List pages: server render + AJAX search

Every list module has both `index` and `search` routes:

- `index` returns the full view, or `response($view)` when `$request->ajax()`.
- `search` (AJAX only) returns JSON `{table, pagination}` by rendering `admin/{module}/partials/_{module}_table_body.blade.php`.

The client half — **`setupAjaxSearch({inputSelector, tableBodySelector, paginationWrapperSelector, url, colspan})`** and the `.toggle-status` click handler — is defined **inline in `resources/views/layouts/footer_script.blade.php`**, not in `public/assets/js/custom/`. Index views wire it up in a `@push('scripts')` block (`layouts/main.blade.php` renders `@stack('scripts')`).

It binds **`keyup`**. Driving it from a test with Playwright's `page.fill()` sets the value without firing that event and the search looks broken — use `page.type()`.

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

### Permissions

Slugs are `{model}.{action}` with actions fixed at **view, create, update, delete, toggle** (`PermissionGenerator::$actions`).

Permissions are **generated, not hand-listed**: `PermissionSeeder` runs `PermissionGenerator`, which walks `config/dashboard.php`'s `models` array, keeps only classes using the **`App\Trait\DashboardModel`** trait, and derives the slug from `Str::snake(class_basename())`. A new model gets permissions only after it is added to `config/dashboard.php` **and** uses that trait. The generator only ever creates — it never prunes, so a removed model leaves its permissions behind.

Three enforcement points, all bypassing checks for `role.slug === 'super_admin'`:

| Where | How |
| --- | --- |
| Routes | `middleware('permission:category.view')` (`CheckPermission`) |
| Blade | `canDo('category.create')` helper |
| Sidebar | `MenuBuilder` derives visible items from the user's `*.view` permissions |

`EnsureDashboardRole` (`dashboard.only`) additionally gates all `/admin` routes on `role.type === 'dashboard'`. System roles/permissions are flagged `is_system = true` and should not be deleted.

### Sidebar

`config/menu.php` drives everything — `groups` (dropdowns), `singles`, plus parallel `icons` / `titles` / `routes` maps keyed by model name. `MenuBuilder` intersects those keys with the user's `*.view` permissions. A new module needs an entry in the relevant `groups`/`singles` list **and** in all three UI maps, or it renders with nulls.

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

### The API layer

`routes/api.php`, 102 endpoints under `/api/v1`, controllers in `app/Http/Controllers/Api/V1/`, requests in `app/Http/Requests/Api/V1/`.

- **Responses** go through `app/Helpers/ApiResponse.php` — `successReturnData()`, `successReturnCreated()`, `successReturnPaginated()`. The envelope is `key`, `status`, `msg`, `code` plus `data`/`errors`/`meta`. **`status` is `success`/`error` derived from the code by `apiResponseStatus()` — never pass it in**, or a call site will eventually disagree with its own HTTP status; `key` is the one that says *which* outcome. The panel's `ResponseService` is a different thing (it `throw`s / returns `never`); don't mix them.
- **Auth** is Sanctum on the `api` guard, one `users` table for both apps. Customer tokens are named `mobile`, driver tokens `driver-app`.
- **Driver endpoints are not gated by middleware.** `$request->user()` returns a plain `User`, so each driver controller resolves the driver record and does `abort_unless($driver !== null, 403, …)` itself. Adding a driver endpoint means repeating that, not adding a middleware.
- **Named rate limiters** beyond `api`: `otp`, `otp-verify`, `login`, `location`. Auth routes carry them individually.
- Controllers keep a private `present*()` method per payload shape (e.g. `OrderController::presentSummary()` vs `presentDetail()`). A field added to a summary must be eager-loaded in the corresponding `index()` or it is an N+1 — there are query-count tests guarding this.
- Domain vocabulary lives in **PHP enums** under `app/Modules/{Name}/Enums/` (`OrderStatus`, `TaskType`, `PaymentMethod`, `PaymentStatus`, `TransactionReason`, …). Prefer these over string literals.
- Password reset is **two steps**, for the panel and both apps: `verify-reset-code` spends the code and issues a single-use ticket, `reset-password` takes the ticket. Shared in `app/Services/Auth/PasswordResetTicket.php`. Never accept code + new password in one call.
- Cross-field rules shared between requests go in `app/Http/Requests/Api/V1/Concerns/` (see `OneDiscountPerOrder`).

### Money, phones and dates

- `appCurrency()` reads the `Currency` setting, validates `/^[A-Z]{3}$/`, falls back to `EGP`. `moneyFormat($amount, ?string $currency = null)` formats through `NumberFormatter` with a `-u-nu-latn` locale extension, so Arabic renders **Western digits** — Arabic-Indic numerals in prices is a bug, not a locale preference.
- `phoneRegex()` is **E.164** (`/^\+[1-9]\d{7,14}$/`). Stored numbers are normalised to it; don't reintroduce a local-format regex.
- One discount per order: `coupon_code` and `offer_id` are mutually exclusive, the offer wins, and a code sent beside it is **refused with a message** rather than dropped. Enforced at the quote as well as at submit.

### Helpers (`app/Helpers/`, auto-loaded via composer `files`)

`Helpers.php` — `uploadOrUpdateImage($file, $dir, $existing = null)` (validates extension + 5MB cap, deletes the old file, returns the stored path, or returns `$existing` when `$file` is null), `DeleteImage()`, `getImageDashboardUrl()` (returns **raw HTML**, use `{!! !!}`), `canDo()`, `getLocalizedValue*()`, `getDefaultLanguage()`, `humanDate()`, `moneyFormat()`, `appCurrency()`, `phoneRegex()`, `getSettingValue()`.

`LanguageHelper.php` — generates `resources/lang/{code}{,_panel,_mobile,_web}.json` from the `storage/app/{panel,mobile,web}File.php` templates.

`ApiResponse.php` — the API envelope (above).

### Caching (fragmented — check both systems)

Two overlapping caches exist:

- `CachingService` — keys from `config('constants.CACHE')` (`languages`, `settings`), 1-hour TTL. `getLanguages()` feeds the topbar language switcher via `ViewServiceProvider`'s `layouts.topbar` composer.
- `Helpers.php` — `rememberForever` on `all_languages`, `available_locales`, `default_language`, `languages_without_default`, `language_{code}`, `lang_file_{code}_{type}`, and the settings reads.

`clearLanguageCache($code)` clears the **Helpers** set only — it does **not** touch `config('constants.CACHE.LANGUAGE')`, so the topbar switcher can stay stale for up to an hour after a language change. Clear both when editing languages. Tests call `Cache::flush()` in `setUp` for this reason.

## Testing

767 PHPUnit tests, 2327 assertions, currently green (~84s). Real coverage exists — treat a failure as a regression, not as a flaky stub.

- `tests/Feature/Api/` (28 files) · `tests/Feature/Dashboard/` (17) · `tests/Feature/Console/` · `tests/Unit/` (9) · `tests/browser/` (10 Playwright specs).
- **There are no factories.** `database/factories/` holds only an unused `UserFactory`. Build rows with `Model::create()`, or better with the builders on `tests/TestCase.php`: `seedCore()`, `seedGeo()`, `seedCatalog()`, `cover()`, `addressFor()`, `grant()`, `superAdmin()`, `customer()`, `driverUser()`, `laundryWithOwner()`, `apiHeaders()`.
- **`seedCore()` is mandatory** in `setUp` — the locale helpers throw without a default language row.
- Idiom: `Tests\TestCase`, `RefreshDatabase`, `#[Test]` attributes (not `test_` prefixes), `Cache::flush()` in `setUp`, and a private `tr()` helper that json-encodes translations with `JSON_UNESCAPED_UNICODE`.
- Columns guarded against mass assignment (e.g. `redemptions_count`) need `forceFill()` after create — otherwise the test silently exercises a default row instead of the state it meant to set up.

## Docs

`docs/` is maintained by hand and drifts if you don't:

- `docs/postman/Laundo API v1.postman_collection.json` — 102 requests, one per endpoint, with substantive per-request descriptions. An endpoint diff will not catch a **stale request body**; check the bodies when you add a field.
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

## Known rough edges

Don't "fix" these blind, but know they're there:

- `Banner` and `Intro` model **classes are lowercase** (`class banner`, `class intro`) — match existing usage rather than renaming casually.
- `CachingService::getSystemSettings()` plucks by a `name` column; the `settings` table has `key`. It is currently unreferenced — dead code.
- Settings are key/value rows with **PascalCase keys** (`App_Name`, `App_Logo`, `About`, `Privacy_Policy`, `Terms`, `Country_Id`, `Currency`, `Cash_Surcharge`); `About`/`Privacy_Policy`/`Terms` hold translatable JSON.
- The **`App_Name` setting row still says `BaseCode`** while `.env` says `Laundo` — and the setting is the one the apps, the invoice and the login alt text read, via `getSettingValue('App_Name')`. `config('app.name')` is only the browser tab title. Left alone deliberately: an invoice may need a registered legal name, so it is the owner's call.
- Terms and privacy hold **draft copy awaiting legal review**.
- Seven images are still placeholders pending export from Figma (3 onboarding illustrations, 3 journey-step icons, 1 offer image).
- `public/storage` must be the **symlink**, not a real directory. If it is a directory, every uploaded file 404s and signed routes 403; fix with `rmdir` then `php artisan storage:link`.

## Frontend

Views are Blade under `resources/views/admin/{module}/` (with `partials/`, `forms/`, `shared/` subfolders), extending **`layouts.main`**.

**Styling is a static vendor admin template, not a build pipeline.** CSS/JS come from `public/assets/**` via `asset()` calls in `layouts/include.blade.php` and `layouts/footer_script.blade.php` — Bootstrap 5, jQuery, Font Awesome, bootstrap-icons, select2, sweetalert2, toastify, filepond, bootstrap-table, leaflet. RTL swaps to `assets/css/main/rtl.css` based on the session language. Project overrides go in `public/assets/css/theme.css` and `custom.css`; the vendor `main/app.css` often out-specifies them, so **match its selector specificity instead of relying on load order** — and before changing a property, grep for *every* rule that sets it, in both override files and the vendor CSS. More than one "fix" here has been a no-op because a second `!important` rule was still winning.

Vite/Tailwind are near-unused but **not dead**: `@vite` appears only in `layouts/app.blade.php` — and seven views do extend it (`auth/passwords/*`, `auth/register`, `auth/verify`, `home`, `welcome`). `Auth::routes(['register' => false])` in `routes/web.php` makes `/password/reset` reachable, so **`npm run build` is required before deploying** or that page 500s with a missing Vite manifest. It appears to work locally only because `npm run dev` leaves a gitignored `public/hot` behind. Don't route new styles through Vite unless you're deliberately migrating.

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

1. `app/Modules/{Name}/` — Controller, Model (+ `DashboardModel` and `Searchable` traits), Repository, `{name}CrudService.php` (with `shredData()`), Request (branch rules on `$this->getMethod() === 'PUT'`: required on create, nullable on update), plus `Enums/` if it has a closed vocabulary.
2. Migration (`status` enum `active|inactive`, translatable columns as `text`), then `php artisan migrate`.
3. Register the model class in `config/dashboard.php` so `PermissionSeeder` generates its five permissions, then `php artisan db:seed --class=PermissionSeeder`.
4. Route group in `routes/web.php` with `permission:` middleware on each action, including `search` and `status`.
5. `config/menu.php`: `groups`/`singles` entry **plus** `icons`, `titles`, `routes`.
6. Views under `resources/views/admin/{name}/` — `index` (with `setupAjaxSearch` in `@push('scripts')`), `create`, `edit`, `show`, `partials/_{name}_table_body`, `forms/formInput`, `shared/controlBut`.
7. If it is exposed to the apps: endpoint in `routes/api.php`, a `present*()` method, an entry in the Postman collection **and** in `generate-reference.py`.

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
