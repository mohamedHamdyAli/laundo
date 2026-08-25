# Changelog

## 2026-08-03

### Fix

- Fixed duplicate role names crashing with a raw `UniqueConstraintViolationException` (SQLSTATE 23000) instead of a friendly validation message. Added `App\Http\Requests\RoleRequest` with a `unique:roles,name` rule plus a slug-collision check (catches names that differ only in case/punctuation but slugify identically), updated `RoleController::store()` to use it, and added a try/catch around `Role::create()` as defense-in-depth against race conditions.

### Migration

- Added a unique constraint on `roles.name` (previously only `slug` was protected at the DB level).

### Feature

- Added a Moderator management module (`app/Modules/Moderator/`) — CRUD for dashboard staff (name/email/phone/role/profile image/status/password), reusing the existing `users` table via a `Moderator` model scoped to `role.type = dashboard` and excluding `super_admin`. A Moderator's CRUD permissions are entirely derived from whichever Role they're assigned (reusing the existing Role↔Permission system — no separate per-moderator overrides). Added sidebar entry, routes under `permission:moderator.*` middleware, and generated `moderator.{view,create,update,delete,toggle}` permissions via the existing `PermissionGenerator`.
- Added an in-app notification system: Laravel database notifications (`app/Notifications/AdminNotification.php`, sent synchronously, not queued), a topbar bell with unread badge + dropdown, and a `public/assets/js/custom/notifications.js` polling script (15s interval, no page refresh) backed by new `admin/notifications/*` endpoints. Creating a Moderator now notifies all Super Admin and Admin users.
- Added a Country/timezone setting: General Settings now has a Country selector (`Country_Id`) whose timezone drives the app's per-request timezone via a new `SetTimezone` middleware (mirrors the existing `SetLocale` middleware). Countries auto-fill a sensible IANA timezone from a static code map (`app/Support/CountryTimezones.php`) when created/edited without one specified, and admins can override per-country via the Country form.

## 2026-08-12

### Fix

- Fixed sidebar link/icon colors being invisible against the new navy sidebar background: the vendor template's `.sidebar-wrapper .menu .sidebar-link`/`i` rules (`public/assets/css/main/app.css`) had higher CSS specificity than the theme override in `public/assets/css/theme.css`, so the original light-sidebar colors (`#000`/`#4D5454`) always won regardless of load order. Matched the selector specificity in `theme.css` so the navy-friendly colors take effect.
- Fixed the home page's floating "3D hero" icon tiles rendering as visually scattered, disconnected boxes (randomly-placed `position: absolute` percentages). Replaced with a contained 2×2 grid inside the same panel, with a synchronized gentle hover/float instead of independent random offsets.
- Fixed data tables across list pages looking squared-off against the rounded card around them. `.table-responsive` now gets its own border + rounded corners (clipping the header background and striped rows to match), instead of relying on the outer `.card` radius alone.
- Fixed a Blade syntax bug (`:permission="x.toggle"` instead of `permission="x.toggle"`) in the status-toggle button usage on the Category, City, Banner, Country, and Intro list pages. The leading `:` makes Blade evaluate the attribute as a raw PHP expression, so `category.toggle` etc. was interpreted as an undefined PHP constant — this crashed the entire list page with a 500 error as soon as the table had at least one row (previously masked because those tables were empty in the dev DB). Verified via a live create → list → edit → delete round trip against the Category module after the fix.
- Found and fixed the real reason the table rounding fix above didn't show up on most list pages: Country, City, Banner, Intro, Category, and Language render `<table>` directly inside `.card-body` with no `.table-responsive` wrapper (only Moderator/User use that wrapper), so the earlier fix never reached them. Added a matching rule for `.card-body > table.table` (using `border-collapse: separate` so `border-radius` + `overflow: hidden` reliably clip the header background across browsers).
- Fixed the topbar dark-mode toggle and notification bell icons rendering nearly invisible: that markup isn't wrapped in Bootstrap's `.nav-link`, so it never received `--bs-navbar-color` and fell back to a very light inherited color. Gave both explicit, theme-aware colors (muted by default, full contrast on hover) consistent with the sidebar icon fix above.
- Fixed the notification bell sitting lower than the other topbar icons and looking washed out even after the previous pass: unlike the dark-mode toggle, the bell's markup (`.avatar`) never got a sized circle backdrop, so it stayed at its natural inline baseline instead of being vertically centered like its siblings. Gave it the same 38px flex-centered circle as the dark-mode toggle, and switched both icons from a muted gray to a bold navy (gold on hover/dark mode) so they read as clearly "on" rather than faded.
- Fixed the dark-mode/bell glyphs still not sitting dead-center inside their circles after the flex-centering fix above: bootstrap-icons' vendor CSS puts `vertical-align: -0.125em` on every glyph (a nudge meant for inline text flow), which offsets it from true center inside a flex-centered circle. Zeroed that out specifically for these two icons so `align-items: center` centers the glyph itself instead of its text-baseline position.

## 2026-08-25

### Improvement

- Unlinked the local repository from GitHub (Infrastructure): removed the `origin` remote, which still pointed at the upstream template repo `https://github.com/mohamedHamdyAli/laravel_templete_blade.git`. `main` no longer tracks a remote branch, so `git push`/`git pull` require an explicit remote until a new one is added.
