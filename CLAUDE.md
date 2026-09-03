# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer dev          # server + queue:listen + pail logs + vite, concurrently
composer test         # config:clear then artisan test (PHPUnit 11 — NOT Pest)
composer stan         # phpstan analyse app --level=5 (via larastan)
./vendor/bin/pint     # formatter (no composer script)
vendor/bin/php-cs-fixer fix          # second formatter, config .php-cs-fixer.dist.php
vendor/bin/rector process --dry-run  # config rector.php
php artisan ide-helper:models -W     # refresh model @property docblocks
npm run build / npm run dev
```

Single test: `php artisan test --filter=TestName` · one file: `php artisan test tests/Feature/Foo.php` · one suite: `php artisan test --testsuite=Unit`.

`tests/` currently holds only the stock `ExampleTest` stubs — there is no real test coverage, so `composer test` passing proves very little. Verify changes by driving the running app rather than by tests alone. PHPUnit runs against in-memory SQLite (`phpunit.xml`); the app itself runs on MySQL.

## Stack

Laravel 13 · PHP ^8.3 · MySQL · `laravel/ui` (Bootstrap auth scaffolding) · Vite 6.

## Architecture

Laravel admin panel (dashboard-only, no API routes yet) built on a **Modular Architecture** with **Repository + Service Layer**.

### Module structure

`app/Modules/{Name}/` — Controllers, Models, Repositories, Services, Requests. Modules: **Banner, Category, City, Country, Intro, Moderator, Setting, User**.

Not every feature is a module. **Role**, **Language** and **Notification** live in `app/Http/Controllers/Admin/` + `app/Models/` instead — when touching those, don't look for a module directory.

### The layer contract

Controllers do HTTP only and delegate to the service; **repositories are the only place raw Eloquent queries live**; services own business logic and wrap every write in `DB::transaction`. Read `app/Modules/Category/` end to end — it is the most complete example of the pattern.

Every CRUD service exposes **`shredData($id = null)`** — the universal view-data assembler. It returns the list under a plural key and, when `$id` is given, the single record under **`row`**. Controllers pass its result straight to the view; Blade partials expect `$row`. New modules must follow this or the shared partials/components won't fit.

### List pages: server render + AJAX search

Every list module has both `index` and `search` routes:

- `index` returns the full view, or `response($view)` when `$request->ajax()`.
- `search` (AJAX only) returns JSON `{table, pagination}` by rendering `admin/{module}/partials/_{module}_table_body.blade.php`.

The client half — **`setupAjaxSearch({inputSelector, tableBodySelector, paginationWrapperSelector, url, colspan})`** and the `.toggle-status` click handler — is defined **inline in `resources/views/layouts/footer_script.blade.php`**, not in `public/assets/js/custom/`. Index views wire it up in a `@push('scripts')` block (`layouts/main.blade.php` renders `@stack('scripts')`).

### Multi-language data (the biggest gotcha)

Translatable columns hold JSON `{"en":"…","ar":"…"}`, handled **manually — there is no `$casts` entry**:

- **Write**: the service does `json_encode($request['name'], JSON_UNESCAPED_UNICODE)` before hitting the repository.
- **Read**: the model defines `getNameAttribute($v) => json_decode((string) $v)` — returning a **`stdClass`, not an array**. So it's `$row->name->en`, never `$row->name['en']`.
- Models also override `asJson()` to keep `JSON_UNESCAPED_UNICODE` (otherwise Arabic is stored as `\uXXXX`).
- Form inputs are **arrays keyed by language code**; requests validate `'name' => 'required|array'` plus `'name.*'`.
- In Blade use `getLocalizedValueDashboard($model, 'name')` (default language) or `getLocalizedValue()` (request locale from the `lang` header, falls back to `->ar`).

Adding a translatable field means touching four places: migration, `$fillable`, the `getXAttribute` accessor, and the `json_encode` in the service.

`languages.default` and `languages.is_rtl` are **enum string `'true'`/`'false'`**, not booleans — `where('default', 'true')`.

### Permissions

Slugs are `{model}.{action}` with actions fixed at **view, create, update, delete, toggle** (`PermissionGenerator::$actions`).

Permissions are **generated, not hand-listed**: `PermissionSeeder` runs `PermissionGenerator`, which walks `config/dashboard.php`'s `models` array, keeps only classes using the **`App\Trait\DashboardModel`** trait, and derives the slug from `Str::snake(class_basename())`. A new model gets permissions only after it is added to `config/dashboard.php` **and** uses that trait.

Three enforcement points, all bypassing checks for `role.slug === 'super_admin'`:

| Where | How |
|---|---|
| Routes | `middleware('permission:category.view')` (`CheckPermission`) |
| Blade | `canDo('category.create')` helper |
| Sidebar | `MenuBuilder` derives visible items from the user's `*.view` permissions |

`EnsureDashboardRole` (`dashboard.only`) additionally gates all `/admin` routes on `role.type === 'dashboard'`. System roles/permissions are flagged `is_system = true` and should not be deleted.

### Sidebar

`config/menu.php` drives everything — `groups` (dropdowns), `singles`, plus parallel `icons` / `titles` / `routes` maps keyed by model name. `MenuBuilder` intersects those keys with the user's `*.view` permissions. A new module needs an entry in the relevant `groups`/`singles` list **and** in all three UI maps, or it renders with nulls.

### Routing

All admin routes in `routes/web.php`, prefixed `/admin`, mostly `admin.{module}.{action}`. Real exceptions worth knowing before calling `route()`:

- `home`, `change-password.index`, `change-password.update`, `language.set-current` — **no `admin.` prefix**
- roles are **plural**: `admin.roles.index`, `admin.roles.permissions.update`
- settings: `admin.generalSetting.viewGeneralSetting` / `updateGeneralSetting` / `viewPrivacyAndTerms` / `updatePrivacyAndTerms`
- status toggle: `POST /{module}/status/{id}` named `admin.{module}.toggleStatus`

### Status fields

`status` is the **string `'active'`/`'inactive'`**, not a boolean. `toggleStatus` returns JSON `{success, status}` and is rendered via:

```blade
<x-status-toggle-button :id="$row->id" :status="$row->status"
    endpoint="{{ route('admin.category.toggleStatus', $row->id) }}" permission="category.toggle" />
```

`permission` takes a **literal string — no leading `:`**. Writing `:permission="category.toggle"` makes Blade evaluate it as PHP and 500s the whole page as soon as the table has one row (this has already been fixed once across five modules).

### Helpers (`app/Helpers/`, auto-loaded via composer `files`)

`Helpers.php` — `uploadOrUpdateImage($file, $dir, $existing = null)` (validates extension + 5MB cap, deletes the old file, returns the stored path, or returns `$existing` when `$file` is null), `DeleteImage()`, `getImageDashboardUrl()` (returns **raw HTML**, use `{!! !!}`), `canDo()`, `getLocalizedValue*()`, `getDefaultLanguage()`, `humanDate()`, `moneyFormat()`.

`LanguageHelper.php` — generates `resources/lang/{code}{,_panel,_mobile,_web}.json` from the `storage/app/{panel,mobile,web}File.php` templates.

`ApiResponse.php` — JSON response formatting.

### Caching (fragmented — check both systems)

Two overlapping caches exist:

- `CachingService` — keys from `config('constants.CACHE')` (`languages`, `settings`), 1-hour TTL. `getLanguages()` feeds the topbar language switcher via `ViewServiceProvider`'s `layouts.topbar` composer.
- `Helpers.php` — `rememberForever` on `all_languages`, `available_locales`, `default_language`, `languages_without_default`, `language_{code}`, `lang_file_{code}_{type}`.

`clearLanguageCache($code)` clears the **Helpers** set only — it does **not** touch `config('constants.CACHE.LANGUAGE')`, so the topbar switcher can stay stale for up to an hour after a language change. Clear both when editing languages.

## Known rough edges

Don't "fix" these blind, but know they're there:

- `ResponseService` reads `config('constants.RESPONSE_CODE.*')`, which **doesn't exist** in `config/constants.php` (only `CACHE` is defined) — those codes resolve to `null`.
- `routes/web.php` imports `App\Modules\setting\Controllers\SettingController` with a **lowercase namespace segment**. Works on Windows, breaks on a case-sensitive filesystem (Linux deploy).
- `Banner` and `Intro` model **classes are lowercase** (`class banner`, `class intro`) — match existing usage rather than renaming casually.
- `userAuth()` / `isAdmin()` / `isEmployee()` use the **`auth('api')` guard, which is not defined** in `config/auth.php`. Calling them throws.
- `CachingService::getSystemSettings()` plucks by a `name` column; the `settings` table has `key`. It is currently unreferenced — dead code.
- Settings are key/value rows with **PascalCase keys** (`App_Name`, `App_Logo`, `About`, `Privacy_Policy`, `Terms`, `Country_Id`); `About`/`Privacy_Policy`/`Terms` hold translatable JSON.
- `resources/lang/` ships **`en*` only** — no `ar` JSON files despite RTL support.
- `.env` is no longer a leftover (`APP_NAME=Laundo`, `DB_DATABASE=laundo`), but the **`App_Name` setting row still says `BaseCode`** — and that is the one the apps, the invoice and the login alt text read, via `getSettingValue('App_Name')`. `config('app.name')` is only the browser tab title.
- `PROJECT_DOCUMENTATION.md` is a **stale doc from another project** ("Clean-X", claims Laravel 12). Prefer this file.

## Frontend

Views are Blade under `resources/views/admin/{module}/` (with `partials/`, `forms/`, `shared/` subfolders), extending **`layouts.main`**.

**Styling is a static vendor admin template, not a build pipeline.** CSS/JS come from `public/assets/**` via `asset()` calls in `layouts/include.blade.php` and `layouts/footer_script.blade.php` — Bootstrap 5, jQuery, Font Awesome, bootstrap-icons, select2, sweetalert2, toastify, filepond, bootstrap-table, leaflet. RTL swaps to `assets/css/main/rtl.css` based on the session language. Project overrides go in `public/assets/css/theme.css` and `custom.css`; the vendor `main/app.css` often out-specifies them, so **match its selector specificity instead of relying on load order**.

Vite/Tailwind are configured but effectively unused: `@vite` appears only in `layouts/app.blade.php`, which **no view extends**. Don't route new styles through Vite unless you're deliberately migrating.

## Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Service class + file | camelCase | `categoryCrudService.php` |
| Route names | `admin.{module}.{action}` (exceptions above) | `admin.city.store` |
| Permission slugs | `{model}.{action}` | `category.delete` |
| Table body partial | `partials/_{module}_table_body.blade.php` | `_category_table_body.blade.php` |
| JSON translation columns | `{"en":…,"ar":…}`, `stdClass` on read | `name`, `description` |
| Cache keys | `config/constants.php` + literals in `Helpers.php` | `CACHE.LANGUAGE` |

## Adding a New Module

1. `app/Modules/{Name}/` — Controller, Model (+ `DashboardModel` and `Searchable` traits), Repository, `{name}CrudService.php` (with `shredData()`), Request (branch rules on `$this->getMethod() === 'PUT'`: required on create, nullable on update).
2. Migration (`status` enum `active|inactive`, translatable columns as `json`/`text`), then `php artisan migrate`.
3. Register the model class in `config/dashboard.php` so `PermissionSeeder` generates its five permissions, then `php artisan db:seed --class=PermissionSeeder`.
4. Route group in `routes/web.php` with `permission:` middleware on each action, including `search` and `status`.
5. `config/menu.php`: `groups`/`singles` entry **plus** `icons`, `titles`, `routes`.
6. Views under `resources/views/admin/{name}/` — `index` (with `setupAjaxSearch` in `@push('scripts')`), `create`, `edit`, `show`, `partials/_{name}_table_body`.

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
- Run tests, check logs, demonstrate correctness. Given the empty test suite, "demonstrate correctness" here usually means exercising the real page/route.

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
