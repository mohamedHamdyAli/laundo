# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Development (runs server + queue + logs + vite concurrently)
composer dev

# Run tests (clears config cache first)
composer test

# Static analysis (PHPStan level 5)
composer stan

# Code formatting
./vendor/bin/pint

# Frontend build
npm run build
npm run dev
```

## Architecture

This is a **Laravel 12 admin panel CMS** with multi-language support, using a **Modular Architecture** with **Repository + Service Layer** patterns.

### Module Structure

Every feature lives under `app/Modules/{ModuleName}/` with this shape:

```
ModuleName/
├── Controllers/ModuleController.php   # HTTP layer only
├── Models/Module.php                  # Eloquent model
├── Repositories/ModuleRepository.php  # All DB queries
├── Services/moduleCrudService.php     # Business logic (camelCase filename)
└── Requests/ModuleRequest.php         # Validation rules
```

Existing modules: `Banner`, `Category`, `City`, `Country`, `Intro`, `Setting`, `User`

### Routing

All admin routes are in `routes/web.php`, prefixed `/admin`, named `admin.{module}.{action}` (e.g., `admin.category.index`). Permission middleware uses slug format: `middleware('permission:category.view')`.

### Key Services

- `app/Services/ResponseService.php` — status toggling and standardized responses
- `app/Services/MenuBuilder.php` — dynamic sidebar composition (driven by `config/menu.php`)
- `app/Services/LanguageService.php` — language file management
- `app/Providers/ViewServiceProvider.php` — view composers that share languages/settings globally

### Helpers (`app/Helpers/`)

Auto-loaded (no import needed):
- `Helpers.php` — `uploadOrUpdateImage()`, `DeleteImage()`, general utilities
- `ApiResponse.php` — JSON response formatting
- `LanguageHelper.php` — `clearLanguageCache()`, localization helpers

### Multi-Language Data

Translatable fields are stored as JSON: `{"en": "Value", "ar": "قيمة"}`. Languages are cached permanently via `cache()->rememberForever()`. The `Language` model and `LanguageHelper` handle resolution.

### Permissions

Permission slugs follow `{model}.{action}` (e.g., `user.create`). Super admin role bypasses all checks. Roles and permissions are seeded; system records are flagged `is_system = true` and should not be deleted.

### Frontend

Bootstrap 5.2.3 + Tailwind CSS 4.0 via Vite. Views are Blade templates under `resources/views/admin/{module}/`. Shared layout components: `layouts/main.blade.php`, `layouts/sidebar.blade.php`. RTL is supported (controlled by `languages.is_rtl`).

## Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Service files | camelCase | `categoryCrudService.php` |
| Route names | `admin.{module}.{action}` | `admin.city.store` |
| Permission slugs | `{model}.{action}` | `category.delete` |
| JSON translation columns | `{"en":…,"ar":…}` | `name`, `description` |
| Cache keys | Defined in `config/constants.php` | `CACHE_LANGUAGE` |

## Adding a New Module

1. Create `app/Modules/{Name}/{Name}Controller.php`, `{Name}.php`, `{Name}Repository.php`, `{name}CrudService.php`, `{Name}Request.php`
2. Add migration and run `php artisan migrate`
3. Add route group to `routes/web.php` following the `admin.{name}.*` pattern with `permission:` middleware
4. Add menu entry to `config/menu.php`
5. Create Blade views under `resources/views/admin/{name}/`
## Changelog Policy (MANDATORY)

A `Changelog.md` file must exist at the root of the repository.

If `Changelog.md` does not exist: - Create it automatically before
completing the task. - Use the format defined below.

After completing ANY task (feature, fix, refactor, migration,
configuration change, performance improvement, etc.), you MUST update
`Changelog.md`.

Failure to update the Changelog is considered an incomplete task.

---

### Update Rules

After every task:

1.  Append changes under the current date (`YYYY-MM-DD`).
2.  Do NOT delete or modify previous entries.
3.  Do NOT rewrite history.
4.  If an entry for the current date already exists, append under it.
5.  Keep entries concise but clearly descriptive.
6.  Specify the affected layer when relevant (API, Service, Angular,
    Database, Infrastructure, etc.).

---

### Entry Categories

Use the following sections when applicable:

- `### Feature`
- `### Fix`
- `### Refactor`
- `### Improvement`
- `### Migration`

Only include sections that apply to the task.

---

### Required Format

    # Changelog

    ## YYYY-MM-DD

    ### Feature
    - Added new endpoint for managing committees (API / Service).

    ### Fix
    - Fixed null reference in UsersService when filtering by status.

    ### Refactor
    - Removed correlated subquery in TransactionsService EF projection.

    ### Improvement
    - Optimized Angular autocomplete to remove artificial result limit.

    ### Migration
    - Added EF migration `AddCommitteeStatusEnum` for AppDbContext.

---

### Additional Rules

- Never leave the changelog empty after a task.
- Never batch multiple unrelated days into one date.
- Never skip updating the file.
- Always ensure the file remains clean, readable, and chronologically
  ordered.

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
- Run tests, check logs, demonstrate correctness.

### 5. Demand Elegance (Balanced)

- For non-trivial changes: pause and ask "is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution."
- Skip this for simple, obvious fixes—don't over-engineer.
- Challenge your own work before presenting it.

### 6. Autonomous Bug Fixing

- When given a bug report: just fix it. Don't ask for hand-holding.
- Point at logs, errors, failing tests—then resolve them.
- Zero context switching required from the user.
- Go fix failing CI tests without being told how.

---

## Task Management

1. **Plan First**: Write plan to `tasks/todo.md` with checkable items.
2. **Verify Plan**: Check in before starting implementation.
3. **Track Progress**: Mark items complete as you go.
4. **Explain Changes**: High-level summary at each step.
5. **Document Results**: Add review section to `tasks/todo.md`.
6. **Capture Lessons**: Update `tasks/lessons.md` after corrections.

---

## Core Principles

- **Simplicity First**: Make every change as simple as possible. Impact minimal code.
- **No Laziness**: Find root causes. No temporary fixes. Senior developer standards.
- **Minimal Impact**: Changes should only touch what's necessary. Avoid introducing bugs.