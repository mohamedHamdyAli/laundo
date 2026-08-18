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
