# Driver record changes go through a person

The driver app now edits «بيانات المركبة», «رخصة القيادة» and «مستندات المركبة».
As shipped, those writes land straight on `driver_profiles` — so a licence expiry
is whatever the driver last typed, and the record stops being a record.

The owner's decision: **nothing a driver submits about their vehicle or papers
takes effect until somebody has looked at it.** It is staged, an operator is
notified, and it applies on approval.

## The line

| Submitted from the app | Applies |
| --- | --- |
| name · email · profile photo | **immediately** — their own identity, nothing verified about it |
| vehicle · licence · documents | **after approval** |
| zones · availability | not submittable at all (unchanged) |

The dashboard keeps writing directly. An operator editing a driver **is** the
approval; routing them through their own queue would be theatre.

## Plan

- [x] `driver_record_submissions` — `driver_id`, `payload` (json), `status`
      (`pending|approved|rejected`), `reviewed_by`, `reviewed_at`, `note`.
      One pending row per driver: a second submission supersedes the first
      rather than queueing two versions of the same car.
- [x] Files are uploaded on submit and their paths live in the payload. Rejecting
      leaves the file orphaned rather than deleting it — a rejected photograph is
      evidence of what was sent, and an operator may want to look again.
- [x] `POST /driver/profile` stages instead of writing. Response says so, and
      `GET /driver/profile` carries `pending_review` so the screen can show
      «قيد المراجعة» beside what was sent.
- [x] Notification on submit → super admins and anyone holding the new
      permission. `NotificationEvent::DriverRecordSubmitted`.
- [x] Its own screen under `app/Modules/Driver/` — current value against proposed
      value, side by side, with approve and reject. Per-field diff, because
      approving a payload you cannot read is not reviewing it.
- [x] `MenuBadges::for('driver_record_submission')` — pending count. Work waiting
      on a person, which is the only thing that earns a badge.
- [x] Wiring: `config/dashboard.php`, `PermissionSeeder`, `config/menu.php`
      (items + icons + titles + routes), routes with `permission:`.
- [x] Tests, docs, and a note for the mobile team: this **changes** what we
      shipped yesterday, so their save button now means «send for review».

## Decisions worth keeping

- **Approval applies the payload as it was submitted**, not as it is now. If an
  operator edits the driver in between, approving must not silently undo them —
  so the diff is computed at review time and a field the operator has since
  changed is shown as a conflict rather than overwritten blindly.
- **Rejecting is not silent.** The driver's own screen has to show that a
  submission came back, or they will send the same photograph again.


## Review

Shipped: migration, model, service, notifier, API staging, dashboard screen,
permission, menu entry, badge. Eleven new tests on the queue itself, and six of
this morning's rewritten — they asserted the direct write this replaces, which is
the contract the owner reversed rather than a regression.

The decisions worth keeping:

- **The line is identity against record.** A name and a photograph are the
  driver's own and nothing about them is verified; a licence expiry is a claim
  about a document. Splitting there means the driver still gets an instant save
  for the things that are theirs to assert.
- **The diff is computed when somebody looks, not when it was sent.** An operator
  who corrected the same driver in between would otherwise be silently undone by
  an approval.
- **The dashboard is deliberately not routed through the queue.** An operator
  editing a driver *is* the approval; sending them round their own queue would be
  theatre with an extra click in it.
- **A rejection without a reason is not a rejection.** It is the same photograph
  arriving again next week.

Worth flagging: this changes a contract deployed this morning. The note for the
app team says plainly that the save response now returns the **old** values and
that this is not a bug — the field they need is `pending_review`.
