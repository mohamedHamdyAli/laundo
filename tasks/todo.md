# Smart laundry assignment — road distance, slot capacity, load balancing

Decisions taken with the owner before starting (2026-09-20):

| Question | Answer |
| --- | --- |
| Distance provider | **Google Distance Matrix** (key supplied) |
| Delivery fee | **also** switches to road distance |
| Balancing rule | **tolerance in kilometres**, set by super admin |
| "Least loaded" means | **most free places** (capacity − booked) |
| All laundries full | **3 behaviours, chosen from a dashboard setting** |
| Driver shown in the picker | the **`deliver_to_laundry`** leg's driver |
| Capacity editing | **both** a tab on the laundry, and a matrix screen |

---

## Phase 1 — Road distance behind a driver

`app/Services/Routing/` — modelled on `app/Services/Push/`, which is the
project's existing shape for "an external vendor that must never take the
business action down with it".

- [x] `RouteLeg` value object — `km`, `minutes` (nullable), `source`, `estimated`
- [x] `Router` interface — `matrix(Coordinate $origin, array $destinations): array`
- [x] `HaversineRouter` — today's formula, `minutes` null (an invented duration is
      worse than none); this is also the fallback
- [x] `GoogleDistanceMatrixRouter` — one call per origin for up to 25
      destinations, `Http::timeout()`, every failure logged and **swallowed into
      the haversine fallback**
- [x] `RoutingService` — resolves the driver, caches per element, falls back
- [x] `config/routing.php` + `GOOGLE_MAPS_KEY` in `.env` (gitignored — the key
      never reaches the repo)
- [x] **Cache per element, not per matrix**: key is origin+destination rounded to
      4 decimals (~11 m), 24 h TTL. Billing is per element and a customer
      re-quoting three times must not be billed three times.
- [x] `phpunit.xml` gets `ROUTING_DRIVER=haversine` — the suite stays offline and
      the 1,187 existing tests keep their current fee numbers.

**Fee impact.** `DeliveryFeeCalculator` starts measuring the road, so every fee
rises (road is typically 25–40% longer than the straight line). `distanceKm()`
stays as the pure haversine helper because it is the fallback.

**The one risk worth naming:** a quote and its submit must not disagree. Both go
through the same cache, so within an order flow they hit the same value; a
provider outage between them falls back to haversine and quotes *lower*, never
higher. Logged when it happens.

## Phase 2 — Capacity per laundry per window

- [x] Migration `laundry_slot_capacities` — `laundry_id`, `time_slot_id`,
      `capacity` (nullable = uncapped), `unique(laundry_id, time_slot_id)`
- [x] `Laundry/Models/LaundrySlotCapacity` + `DashboardModel`
- [x] `Laundry/Services/LaundryLoad` — `booked()`, `freePlaces()`, `isFull()`

**Counted on the pickup leg only.** The owner's words were «يستقبل ٥ طلبات» —
intake. A delivery window is clothes going back out, which is not work in that
window. This deliberately differs from the platform-wide `time_slots.capacity`,
which counts both legs because *that* number is about vans, not washing.

**Never refuses an order.** This capacity is a routing input. All three overflow
behaviours below accept the order; none throws.

## Phase 3 — The assigner

`LaundryAssigner::assign()` gains the slot and date, and becomes:

1. candidates — **unchanged**: active, claims the zone, offers the service
2. measure all candidates in one matrix call
3. read free places for each in the order's pickup window
4. rank:
   - nearest by road
   - everything within `nearest_km + Balance_Tolerance_Km` is a tie-group
   - inside the group, **most free places wins**; equal → nearest wins
   - `Balance_Tolerance_Km = 0` reproduces today's behaviour exactly
5. all full → the configured behaviour

- [x] `Setting` `Balance_Tolerance_Km` (default **0** — a seeder must not silently
      start re-routing a live install)
- [x] `Setting` `Slot_Overflow_Behavior` — `unassigned` (default, today's
      behaviour) | `nearest` | `hide_slot`

**`hide_slot` needs the mobile app.** `GET /api/v1/time-slots` takes `type` and
`date` and no address, so it cannot know the zone. I will add an optional
`address_id` and make the endpoint answer per-zone when it is sent. Until the
app sends it the option degrades to `unassigned` — stated on the setting itself,
not left for somebody to discover.

## Phase 4 — The picker the super admin reads

Replaces the bare `<select>` in `admin/order/show.blade.php`. Per candidate:

- name, and a badge on the one the system would pick
- **customer → laundry**: road km + minutes
- **laundry → driver** of the `deliver_to_laundry` leg, or «لسه متعيّنش». A
  location older than the freshness window is shown with its timestamp rather
  than presented as live.
- load in the order's window: «محجوز ٣ من ٥»
- the delivery fee that would result
- why it was *not* picked: «أبعد ٢.٣ كم» / «ممتلئة» / «مفيش إحداثيات»

Manual reassignment keeps working exactly as it does today, including the
in-custody refusal and the delivery-fee recalculation.

## Phase 5 — Screens, permissions, menu

- [x] Matrix screen `admin/laundry-slot` — mirrors `admin/laundry_zone`, which is
      already a laundry-picker + bulk-save screen. Bulk-edit grid ⇒
      **`setupClientFilter`, not `setupAjaxSearch`** (a server re-render blanks
      the cells it does not draw and the save wipes them).
- [x] Capacity tab on the laundry edit screen, writing the same table
- [x] `config/dashboard.php` + `PermissionSeeder` ⇒ `laundry_slot.*`
- [x] `config/menu.php` — `laundries` group **plus** `icons`/`titles`/`routes`
- [x] Tolerance and overflow go on the general settings screen behind
      **`setting.update`** — a laundry holds `laundry.update` by design, and a
      routing dial the payee can turn is the commission bug again
- [x] `laundry_slot.update` is **not** granted to laundry roles for the same
      reason: capacity decides how much work a laundry is handed

## Phase 6 — Proving it

- [x] `Unit/RoutingTest` — haversine maths, cache hits, `Http::fake()` for the
      Google payload, fallback on 4xx/5xx/timeout
- [x] `Feature/Dashboard/LaundryAssignmentTest` — zone and service filters still
      hold; tolerance 0 = nearest; tolerance diverts to the freer laundry;
      free-places beats absolute count when capacities differ; all three overflow
      behaviours
- [x] `Feature/Dashboard/LaundrySlotCapacityTest` — both editors, permission gate
- [x] Re-run the **whole** suite (~5 min) — the fee change may move stored
      expectations, and each one must be looked at rather than updated blind
- [x] Walk the dev database afterwards, per `tasks/lessons.md`: confirm
      `laundry_slot_capacities` is not uniformly null and that a real order
      routes where the panel says it will
- [x] Arabic for every new `__()` key, in this phase, not the next
- [x] `Changelog.md`, `docs/mobile-api-changes.md` (`address_id`), and the
      assigner section in `CLAUDE.md`

---

## Review

Done and verified. 1,381 PHPUnit tests / 4,442 assertions green, PHPStan clean,
Pint clean, and every screen driven in a browser against the dev database.

**What changed against the plan**

- **The provider is the Routes API, not the legacy Distance Matrix.** The key
  the owner supplied is valid, but a Google project created recently cannot
  enable the legacy endpoint at all — it answers `REQUEST_DENIED` with «You're
  calling a legacy API». Found by curling one real request before building on
  it; `tasks/lessons.md` has the rule.
- **The road is 49-83% longer than the straight line, not the 25-40% estimated.**
  Measured live from Nasr City: downtown 9.25 -> 13.77 km, Heliopolis 2.35 ->
  4.29 km, Giza 12.80 -> 19.18 km. Every delivery fee rises by that, and the
  owner has been told.
- **The key lives in `settings`, not `.env`.** The owner asked, and the cost
  objection does not hold: it is a `rememberForever` read the settings screen
  invalidates, about a millisecond against a 200 ms network call. A config value
  still wins where an install would rather keep it in a file.
- **`address_id` on `GET /api/v1/time-slots` was built, not deferred.** It
  carries a new `laundries_full` field, `is_full` folds it in only under
  `hide_slot`, and a client sending neither new parameter gets byte-identical
  behaviour. `SlotAvailabilityTest` guards that.

**Two things fixed that the plan did not anticipate**

- A candidate that was *nearer* than the winner reported «Same distance, less
  room», because the difference was negative. Seen on the real screen — 3.7 km
  beside 18.2 km — where it reads as a broken panel rather than a wide
  tolerance. It says «Nearer, but less room» now.
- `laundries_full` was measuring intake against **delivery-only** windows, which
  would have reported a clear evening as full because the morning was.

**Proven, not assumed**

- The picker predicted EGP 79.48 for Laundry B and the assignment wrote EGP
  79.48 — the card and the write go through one assembler.
- The laundry edit tab renders **zero nested forms**, which was the HTML risk in
  putting a second form on that screen.
- The capacity grid saved three cells and touched nothing else.
- The dev database was walked before and after and is byte-for-byte back:
  11 orders, 0 capacity rows, order #8 and #10 restored to their recorded
  values, the tolerance back to 0. Teardown deleted exactly what setup created,
  by primary key, and reported the counts.
- The API key is in `settings` and in `.gitignore`d `.env` only —
  `git grep AIzaSy` returns nothing.

**Left deliberately**

- `hide_slot` does nothing visible until a mobile client sends `address_id`.
  Stated on the settings field itself and in `docs/mobile-api-changes.md`.
- Shipped defaults reproduce today's behaviour exactly: tolerance 0, overflow
  `unassigned`, every laundry uncapped. Nothing reroutes until somebody sets a
  number.
