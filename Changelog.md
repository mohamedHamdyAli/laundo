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
