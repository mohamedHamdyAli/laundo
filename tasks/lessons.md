# Lessons

Rules written for whoever works on Laundo next — including me. Each one is here
because it already cost something, not because it sounded like good practice.

---

## A column added without a way to set it is a column that is always null

This has now happened three times: `zones.price_per_km` and `min_delivery_fee`,
then `laundries.lat`/`lng`, then `driver_profiles.max_concurrent_orders` and
`city_id`. Each was added by migration, read by a service, and never once
written, because no form ever offered the field. The code looked right and the
feature was silently dead.

**Rule:** a migration that adds a column is not finished until something can
write it — a form field, a seeder, or an explicit default. Before shipping, query
the column on the dev database and confirm it is not uniformly null.

---

## Walk the live database at the end of every phase

Three real bugs came from this and from nothing else:

- Task chains were generated at confirmation, i.e. *after* the pickup they were
  meant to schedule.
- A task escalated to operations could never be revived, because `assign()`
  refused any finished task rather than only completed ones.
- The wallet payment reference used `now()->timestamp`, so a retry within the
  same second collided with its own unique key.

None of these had a failing test, because a test only asks what its author
thought to ask. Walking real rows asks what the data thought to do.

**Rule:** after each phase, write a throwaway script that recomputes the headline
figures a second way and cross-checks them. Reconcile every breakdown back to its
total. Disagreement is a finding.

---

## Translate in the phase that adds the strings, not the phase after

P11 added the notification copy — the only Laundo text many customers ever read —
and it was English until the gap was caught by extraction, a day after the Arabic
was declared complete. P12 added 70 more report labels the same way.

**Rule:** the last step of any phase that touches Blade is: extract every `__()`
key, diff against `resources/lang/ar.json`, translate the difference. Not next
phase. The check takes a minute and the gap is invisible otherwise.

---

## The report window comes off a URL, and a URL is untrusted input

`?from=1900-01-01` built a 36,525-entry daily series and a chart bar for each. The
range picker is a plain GET form *specifically* so reports can be bookmarked and
pasted between people, which is exactly why an absurd range eventually arrives.

**Rule:** anything derived from query parameters that sizes a loop, an array or a
render needs a bound. And when input is corrected — clamped, swapped, defaulted —
the form must render the corrected value, never the raw request. A page quietly
measuring a different window from the one on screen is worse than an error.

---

## `Http::fake()` merges stubs; a loop over statuses may test one status

A loop calling `Http::fake()` per iteration to test 403, 404 and 500 tested 403
three times, because the stubs merge rather than replace. It read as a driver bug
for a while. It was a test bug.

**Rule:** use `#[DataProvider]` for per-case HTTP stubs, one fake per test.

---

## The test harness grants permissions separately from `RoleSeeder`

`tests/TestCase.php::seedCore()` hands roles a **fixed list**. Adding a permission
to `RoleSeeder` does not reach the tests, and the symptom is a 403 in a test that
should pass.

**Rule:** a new permission goes in both places, and the harness comment says so.

---

## Test the rendered value, not the class name

`badge bg-light text-dark` is exactly what you would write for a neutral badge.
It was also invisible for months at 1.02:1, because the vendored template
redefines `--bs-dark-rgb` to the page background colour. The markup was correct
the entire time; the value behind it was wrong.

A test asserting the classes were present would have passed throughout. A test
that read `getComputedStyle` and did the WCAG sum failed immediately — and then
found four more failures nobody had reported: `bg-info` order badges at 1.96:1,
the footer at 2.80:1, the roles screen at 2.14:1, and an amber figure I had added
to the revenue report the day before.

**Rule:** for anything visual, assert the computed result. When a class name and a
rendered value can disagree, only the value is worth asserting.

---

## An audit that cries wolf is worse than no audit

The first version of that sweep confidently reported **119 broken sidebar labels**.
All 119 were false. Two causes, both worth remembering:

- `#loader-container` is a fixed white 80% sheet at `z-index: 9999`. Measured mid
  fade-out, every colour on the page reads through it.
- A gradient lives in `background-image`, so `getComputedStyle(el).backgroundColor`
  returns `transparent` for that element. An ancestor walk looking only at
  `backgroundColor` skips straight past it — which is how the navy sidebar was
  reported at 1.46:1 when it is actually 11.25:1.

**Rule:** before reporting a measured finding, verify one instance by eye. If a
sweep says a hundred things are broken and the screenshot looks fine, the sweep
is wrong. Screenshot it and look.

---

## Never nest a CSS comment

`/* ... /* inner */ ... */` does not nest. The inner `*/` closes the block and
everything after it is parsed as CSS. Writing a doc comment that quoted a hex
value as `/* #f2f7ff */` silently broke every rule that followed it.

**Rule:** quote values in prose, not in comment markers — and after appending to a
stylesheet, scan it for unterminated and nested comments.

---

## Figma covers the mobile apps only — and holds two palettes

All 168 frames are 375px or 390px wide. **There is no dashboard design in the
Figma file**, so "check Figma" is not an available review step for dashboard work.

Worse, the palette is not consistent within Figma either. A first read of five
frames reported the app as a Material 3 teal ramp. Across thirty frames it split
by page: `ui` and `delivery` are Tailwind blue `#2563eb` (155 uses), `drft` is
Material 3 teal `#00696F` (25 uses).

**Rule:** sample widely before calling anything "the brand" — five frames is a
sample of five frames, not of a design system. And when two pages contradict,
that is the stop-and-ask case, not a judgement call.

---

## A palette swap is not a find-and-replace

Gold needed *dark* text on it; the brand blue needs *light* text. Recolouring
`--brand-gold` to a blue without touching the pairing would have put navy on blue
at 2.68:1 — trading one invisible thing for another. Two of the fourteen pairings
had to flip direction, and one gradient had to change its far end because white
text would have vanished into the light accent.

Also: rename the token. `--brand-gold: #2563eb` is a lie, and the next person
will believe it.

**Rule:** enumerate every pairing the token participates in, compute each one, and
only then write. `grep` for the token — 23 uses across 5 different roles is normal.

---

## The `__()` scan cannot see config strings

`config/menu.php` holds sidebar titles as plain strings that only meet `__()` at
render time. Extracting every `__()` call from code and diffing against `ar.json`
— the check that closed the Arabic gap twice — never saw them, so Banners, Intros,
Countries, Cities and Roles sat in English inside a fully Arabic menu.

**Rule:** translation coverage must include strings held in config, not only those
written inside `__()`. `tests/Unit/TranslationCoverageTest.php` now enforces it;
extend that test rather than re-running a script by hand.

---

## A wrong enum value in a where() reports zero, not an error

`->where('answer', 'yes')` on `recurrence_prompts`, whose enum is
`confirmed`/`declined`. It matches nothing, so the "became orders" column would
have read **0 for every schedule, forever**, with no exception, no log line and
no failing page. A figure that is silently wrong is worse than a crash.

It surfaced only because a test tried to *insert* `'yes'` and hit the CHECK
constraint. Reading the controller would not have caught it.

**Rule:** when filtering on an enum column, read the migration for the allowed
values — do not infer them from the field name. And prefer a test that writes the
value as well as one that reads it, because the write path is the one the database
will argue with.

---

## Two files, not one, when the brand mark meets a dark surface

The Laundo mark is navy `#072555`. The sidebar is navy. The mark measured
**1.08:1** on it — not "hard to see", gone. One `App_Logo` setting cannot serve a
white login panel and a navy sidebar.

Also worth keeping: a designer's export is not a web asset. This one was
1000×1000 with the wordmark 558×75 sitting low and off-centre, so dropped into a
150px box it rendered about 94px wide and floated near the bottom. Trim to the
artwork, add an even margin, then size it.

**Rule:** put the variant decision in one helper (`brandLogo($variant)`) so no
template can pick the wrong one, and have it fall back when the uploaded file
**does not exist** — not merely when the setting is empty. This install shipped
`App_Logo = 'logo1.png'` with no such file, and honouring the setting rendered a
broken image everywhere.

---

## `onsubmit="return confirm('{{ __(...) }}')"` is a broken confirmation

A translated string containing an apostrophe closes the JS string early and the
confirmation silently stops working — on precisely the destructive actions that
needed it. Use `@js(...)`, which escapes for a JS context.

**Rule:** never interpolate a translated string straight into a JS literal in
Blade. `@js()` exists for this.

---

## Bind the loop key; a leaked `$key` reads as data

`foreach ($grouped as $row)` followed by `$satisfaction[$key]` compiled, ran, and
returned the value `$key` still held from the loop *above* — the last order's
laundry id. Every laundry but one reported another's rating, or none.

PHPStan at level 5 did not object, because `$key` was defined. A single-laundry
test passed, because the leaked value happened to be right.

**Rule:** bind every key you use (`as $key => $row`), and when a per-group figure
is added to a report, test it with **two** groups. One group cannot distinguish a
correct lookup from a lucky one.

---

## Fix the class, not the call sites

`text-warning` is #ffc107 — 1.63:1 as text — and it is the obvious class to reach
for when something needs attention. I fixed it once by replacing it on one view,
then reached for it again on five new views the next day.

Worse, four *older* uses had passed the rendered-page contrast sweep, because a
`$count > 0 ? 'text-warning' : ''` with a zero count renders nothing to measure.

**Rule:** when a utility class is wrong, correct the class. A class that only ever
styles text can be fixed centrally, and that reaches the conditional uses an audit
of the rendered page cannot see. And write down that limitation in the audit
itself — a sweep is a net, not a proof.

---

## Stop fighting the schema column by column

Writing an `Order` with a literal `create([...])` in a test cost four rounds of
"NOT NULL constraint failed" — `pickup_address_id`, then `delivery_address_id`,
then `qr_token`. Each fix invited the next.

**Rule:** build fixtures through the real service (`OrderService::place()`), then
force only the fields under test. A hand-written insert drifts from the schema the
first time a column is added, and the test that lists columns is the one that
breaks.

---

## A noted problem is not a handled problem

`DB_DATABASE=templete` was written down in CLAUDE.md's own "known rough edges"
list before any of this work started. I read it, recorded it, and ran twenty-odd
migrations into the template's database anyway — on a machine hosting thirty other
project databases.

It turned out clean: all 53 tables were ours. That was luck, not planning. Had
another project been sharing `templete`, migrating into it would have been very
hard to undo.

**Rule:** a known rough edge that affects **where data is written** is a blocker,
not a note. Check the target before the first migration, not after the twentieth.
Anything on that list touching the database, the filesystem, or an outbound
integration gets resolved or explicitly waived out loud before work begins — the
rest can stay a note.

The move itself, for reference: `mysqldump` the old database, create the new one,
import, switch one line in `.env`. The old database is left untouched, so the
whole thing reverses by editing that line back.

---

## A test that passes by coincidence is worse than no test

`assertStringNotContainsString((string) $complaint->id, $reference)` looked like it
checked "the reference is not derived from the id". What it actually checked was
whether an eight-character random string happened to contain the digit "1" — which
it does about one time in eight. The suite showed one failure, a re-run showed
none, and only running that single test ten times made the pattern visible.

**Rule:** when a test involves a random value, ask what it would take for the
assertion to fail *by chance*. If the answer is "not much", it is measuring luck.
Assert the property directly — here, that the reference is not a function of the
id, over several rows — and run a suspected flake ten times rather than twice
before calling it either a flake or a fix.

---

## Two names one letter apart are not two systems

I looked at `admin.notification.*` and `admin.notifications.*`, concluded the
project had two notification systems with the topbar bell stuck on an abandoned
one, and wrote that down. It was wrong. The bell reads Laravel's `notifications`
table, which P11's own dispatcher writes to; the log reads `notification_logs`,
an audit record of delivery attempts. Different tables, different audiences, both
connected.

The confusing *name* was real and worth fixing. The broken system was not.

**Rule:** before reporting that something is abandoned or duplicated, find what
writes to it. One `grep` for the writer would have settled this in a minute, and
I spent a paragraph of a report on it instead.

---

## A vanity metric is a metric nobody reads

The dashboard opened with total customers, total laundries, total categories and
total banners. None changed during a working day; none led anywhere. "Total
Banners: 0" is a row count.

The test that fixed it: **is this something happening right now, or something
waiting for a person?** If neither, it belongs on a reports screen.

Two consequences worth keeping:

- **Never render a zero in a work queue.** A column of noughts teaches people to
  skim past it, and then the one that is not a nought goes unnoticed. Filter the
  list and say "nothing is waiting" in words.
- **Put the reason next to the number.** "3 orders confirmed and not started" is
  a count; "the customer agreed to the price, the clock is running" is why anyone
  should care. The second one is what makes it get acted on.

---

## "Hygiene" is where the real bugs hide

A list of seven hygiene items turned out to contain: two things that were not
problems at all, two that were decisions in disguise, and **one silent
data-destroyer nobody had noticed**.

`replaceLanguageFile()` deleted its target and moved the upload into place. For
`panel_file` the target is `{code}.json` — the panel's complete translation. An
admin uploading twenty strings from the languages screen wiped a thousand,
silently, with no undo. It was not on any list; it surfaced only because checking
whether the "stub translation files" mattered meant reading the code that writes
them.

The two non-problems: `Country_Id` is read by `SetTimezone` (not a leftover), and
`ar_panel`/`ar_mobile`/`ar_web` were not incomplete — **nothing read them**, so
filling them in would have achieved nothing. The fix was to wire the reader.

**Rule:** verify every item on a maintenance list against the code before working
it. Half of them will be wrong, and the reading is where the unlisted bug is
found. Never carry a claim forward from a previous note without re-checking it —
including your own.

---

## A missing enum case in a branch list is invisible

`TaskFailureReason::CustomerPostponed` was simply absent from `haltsTheOrder()`,
so it fell through to the release-and-dispatch branch. A driver recording "the
customer asked to postpone" put the same journey in front of the next driver
within seconds — and it failed again, and again, until the task exhausted its
attempts and escalated. **The escalation blamed the drivers.**

Nothing errored. Every test passed. The code read fine, because the branch it fell
into is the correct branch for most reasons.

**Rule:** when a `match`/`in_array` decides behaviour per enum case, enumerate
every case out loud against what should happen to it. The dangerous case is not
the one handled wrongly — it is the one nobody listed, which silently takes the
default.

---

## A schedule entry for an unregistered command fails silently

`withCommands()` in this project is explicit, because commands live with their
modules rather than in `app/Console/Commands`. Two commands were scheduled and
never registered; `Schedule::command()` accepted the string happily and Laravel
suggested a different command when I ran it by hand.

**Rule:** after adding a scheduled command, run it once (`php artisan <name>`) and
check `php artisan schedule:list`. A schedule you have not seen execute is a
schedule you have not written.

## A model subclass with a role scope is not a display relation

`DriverEarning::driver()` points at `Driver`, whose global scope requires the
`driver` role. That scope is a feature for the driver API — another driver's id is
simply not found — and a bug on any screen that reports on history: move somebody
off driving and their unpaid earnings disappear from the ledger, while a summary
built from `sum('amount')` over the unscoped table keeps counting them. The two
numbers then disagree on the same page, and neither is flagged.

The tell was PHPStan complaining that `$row->driver?->name ?? '—'` was an
unnecessary nullsafe. It was right about the declared type and wrong about
reality; the `?? '—'` was there because I half-knew the row could be missing.
**A nullsafe I cannot explain is a design question, not a lint warning.**

**Rule:** a relation used for *reporting on the past* must not go through a model
scoped by present-tense role or status. Add an unscoped relation (`payee()`) and
say in the docblock why both exist.

---

## Validation is where features go to die quietly

Third time in this project: `coupon_code` in P7, `payment_method` in the cash
surcharge. The service was correct, the pricing was correct, the setting was
stored — and the FormRequest never let the field through, so the whole feature
was inert with no error anywhere.

**Rule:** when a feature reads a new request field, the FormRequest rule and an
endpoint-level test are part of the change, not follow-up. Testing the service
directly proves nothing about this.

---

## Test harness helpers with a fixed identity must be idempotent

`superAdmin()` inserted a row per call, so asserting two screens in one test hit
`users.phone`'s unique index — and the failure reads as a schema problem rather
than as "you asked for the same person twice".

**Rule:** a helper that returns *the* something (the super admin, the default
language) uses `firstOrCreate` on its natural key. A helper that returns *a*
something takes the distinguishing value as a parameter.

---

## A column allow-list on an eager load is a silent lie

`OrderRepository::EAGER` had `service:id,name`. Nothing failed. `isPerItem()` read
a `pricing_mode` that was never selected, saw null, and answered **false** — so
every catalogued order claimed to be quote-priced, and the review screen offered
to re-price items the platform sets the price of. Three of these in one day: the
same shape hid `customer_reference` from the driver's ticket, and `service:id,name`
hid the pricing mode.

The pattern is that a missing *column* degrades to null, and null then flows into
a boolean method that returns a confident wrong answer. A missing *relation*
throws; a missing column does not.

**Rule:** when adding a method that reads a column to decide behaviour
(`isX()`, `hasY()`), grep every `with('relation:col,col')` for that model and add
the column. When adding a column to an allow-list, ask what reads the model, not
what the current screen prints.

---

## Reach for the real data before believing a figure looks wrong

The laundry digest showed 11 orders and $178, identical to the platform's. It
looked like the tenant scope had failed. It had not: `OrderReport` counts orders
**created** in the range and `RevenueReport::byLaundry` counts orders **paid** in
it, and the one laundry with revenue is naturally equal to the platform total.

I nearly "fixed" working code. What settled it was writing the scoping test with
two laundries and explicit amounts — which is also the test that had to exist
anyway.

**Rule:** when a figure looks wrong, first find the two definitions that could
both be right. Only then look for the bug.

---

## Hand-listing enum values in a FormRequest

`OrderRequest` had `Rule::in(['cash','card','wallet'])` while `PaymentMethod` has
four cases and both the quote and the payment endpoints read the enum. A customer
could pick «انستا باي», see it priced, and be refused at the last step. Nothing
was inconsistent at the moment somebody added InstaPay to the enum — the rule was
already written.

**Rule:** a validation rule over a set that exists as an enum reads the enum.
`Rule::in(PaymentMethod::values())`, never a literal list. When adding an enum
case, grep for the case's siblings as string literals.

---

## Comparing the design to the API is a different sweep from comparing it to the screens

Nine phases each checked their own screens against Figma and all nine passed. What
none of them did was walk **every** frame against the whole endpoint list at once.
Doing that in one pass found ten gaps, and the common shape was a control drawn
since the first version whose data never arrived: a QR button with no token, a
driver card with no driver, a photo attacher the endpoint ignored.

They survived because a missing field renders as an empty state, and an empty
state looks like "no data yet" rather than like a bug.

**Rule:** once a feature set is broadly complete, do a whole-file Figma pass at
least once. Pull every TEXT node per frame and read it against `route:list`, not
against memory of what was built.

---

## Project-specific traps that have already bitten

- `:permission="category.toggle"` in a status-toggle button makes Blade evaluate
  it as PHP and 500s the whole page as soon as the table has one row. The
  attribute takes a **literal string, no leading colon**. Fixed once across five
  modules; do not reintroduce it.
- Translatable accessors return **`stdClass`**, so `$row->name->en`, never
  `$row->name['en']`.
- `languages.default` and `is_rtl` are enum strings `'true'`/`'false'`.
- `status` is the string `'active'`/`'inactive'`, not a boolean.
- `clearLanguageCache()` clears the `Helpers.php` cache set only, not
  `config('constants.CACHE.LANGUAGE')`. Clear both, or the topbar switcher stays
  stale for an hour.
- PHPStan's result cache goes stale and reports `view-string` errors for views
  that exist. `./vendor/bin/phpstan clear-result-cache` before believing it.
- `php artisan tinker <file>` needs a real `<?php` opening tag, or it echoes the
  file instead of running it — and the silence looks exactly like a hang.
- Aggregate aliases on a scoped model: read them with `$row->getAttribute('total')`.
  `toBase()` is PHPStan-clean too but drops the tenant scope.
- `DriverEarning::driver()` is role-scoped (`Driver` model). For ledgers and any
  historical report use `payee()`.
- Python heredocs and PHP namespaces: `\M`, `\U`, `\N`, `\S`, `\H` are invalid
  escapes. Use raw strings or `chr(92)` — this has now broken five scripts,
  including the one writing this line.
- `:permission="canDo('x')"` on `<x-status-toggle-button>` passes a **bool** into
  a component that calls `canDo()` itself. The attribute takes a literal slug:
  `permission="user.toggle"`. And the action is `toggle`, never `toggleStatus`.
- `artisan tinker <file>` hangs on this machine often enough to be unusable for
  quick data checks. Write a feature test instead — it is the same effort and it
  stays.
- Anything asserting "this month" or "this week" must `travelTo()` a fixed point.
  A month-boundary assumption passes 28 days out of 30 and fails on the day
  somebody would doubt the screen.

