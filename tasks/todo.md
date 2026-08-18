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
- [ ] Visual QA (colors/layout/animation/Select2+SweetAlert2 dark-mode clash) — NOT verified, no browser automation tool available in this environment. User should check http://127.0.0.1:8000/login and /admin/home directly.

## Final steps (all workstreams)
- [ ] Code review pass
- [ ] Security review pass
- [ ] Update `Changelog.md`
