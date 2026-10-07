# Laundo — how it works, in detail

The long form of what `CLAUDE.md` summarises. Each section here is the full
account behind a short summary there, under the same name: the reasons, the
incidents that set the rules, and the class and setting names. Read the
section before changing that area. Moved here unchanged from `CLAUDE.md` on
2026-10-04; keep the two in step when a rule changes.

## The order lifecycle

The domain core. Full reference in **`docs/order-lifecycle.md`** (Arabic, written
from the code) and **`docs/order-cycle-explained.md`**; the rules that bind code:

- **`OrderStateMachine::transition()` is the only way a status changes.** Writing
  `$order->status` directly skips validation *and* leaves `order_status_logs`
  short — and the customer app's tracking screen is built from that log, not from
  the current status, so a hand-moved order renders with missing steps.
- `OrderStatus::allowedNext()` is the single transition table; `isCancellable()`
  is derived from it, so no endpoint can permit a cancel the table forbids.
  Cancelling stops at `picked_up` — once we hold the pieces the order runs out.
- **`returned` is not `cancelled`**: the pieces come back but the delivery fee is
  still owed, and collapsing the two loses that.
- A driver's work is **four legs** owned by `TaskType` (`pickup_from_customer`,
  `deliver_to_laundry`, `collect_from_laundry`, `deliver_to_customer`), each with
  `start` / `verify` / `complete` / `fail`. `verify` (the QR scan) is separate
  from `complete` on purpose — merging them meant a failed photo upload threw
  away a good scan. `TaskType::startsInto()` / `completesInto()` decide which leg
  moves the *order*; the others move only the task.
- A leg's own status is `TaskStatus`, and it has **six** cases:
  `pending` · `assigned` · `started` · `completed` · `failed` · `cancelled`.
  **`cancelled` is not `failed`.** A failure is a driver who went and could not
  do it: it counts an attempt, feeds the monthly bonus gates, and returns the leg
  to the queue with `driver_id` nulled. A cancellation is a leg nobody is going
  to drive because the order stopped — so it keeps its `driver_id`, which is what
  leaves it in that driver's history where «ملغاة» can explain where the job went.
- **An order that stops closes its open legs**, in `OrderStateMachine::standDownTasks()`
  and in the same transaction as the status change. Completed legs are left alone:
  on a `returned` order the pieces really were collected and really did reach the
  laundry, and that is work the driver is owed for. Before this, cancelling moved
  the money and left every leg exactly where it was — the holder kept a task they
  could not finish, and an unassigned one sat on the dispatch board as work
  waiting for somebody who was never coming.
- **Nothing leaves the laundry before its price is agreed** (2026-10-01).
  `OrderTask::orderAllows()` holds `collect_from_laundry` until the order is
  `cleaning` or `ready_for_delivery`, in `TaskService::start()` / `complete()`
  (`order_not_ready`) and in the API's `can_start` / `blocked_reason`. A leg
  used to wait only on the leg before it, and `advanceOrder()` lets a leg the
  order cannot follow complete with a note. So on live, orders still
  `picked_up` were collected and delivered, unpriced, with nothing collected
  and no way to move again. **Confirming the price with the pieces already at
  the laundry moves the order to `cleaning`** (`OrderReviewService::confirm()`).
  The handover that should do it has almost always happened before the
  review, from `picked_up`, and was refused then, which left every normal
  order stuck at `confirmed`. Both sides lock the order row
  (`TaskService::advanceOrder()` re-reads it under `lockForUpdate`), so a
  confirmation and a handover at the same moment cannot miss each other, and
  `2026_10_01_100200_…` released the orders the old way had stranded.
- **Every count of an order's pieces is checked** (`Order/Services/PieceCheck`,
  2026-09-28/29). Legs 1–3 require `piece_count`, and the laundry counts at its
  review; each is measured against the last number somebody stood behind — the
  customer's order at the pickup, the handover before at the laundry, what was
  handed to the laundry at its review, the review at the collection
  (`PieceCountSource`). What a leg was held to is **stamped on it at
  completion** (`order_tasks.expected_piece_count` / `_source`), because the
  review can change the order's count later. A disagreement is **one
  `PieceDiscrepancy` row** (`piece_discrepancies`, `step` = the leg or
  `laundry_review`, indexed `open`), created in the handover's or the review's
  own transaction and announced after it commits. **Nothing is ever refused.**
  `PieceCountNotifier` rings the bell of the platform (`order.update`, not in a
  laundry) and of the order's laundry (`order.view`, picked by the order's
  `laundry_id` — the driver's request has no tenant), both through
  `Notification/Services/PanelAudience`, **in the panel's default language**
  (the bell keeps the words as written, and the request is the driver's), with
  everything inside the try. The order screen shows a red banner, the leg's
  count in red, and a «reviewed» list; **`Order::withOpenPieceCheck()`** is the
  one count behind the `order` sidebar badge, both home-page queues and the
  `piece_mismatch` pseudo-filter (`OrderRepository::PIECE_MISMATCH`, mirrored in
  `OrderSheet`) — in orders, not rows, so a 1 opens a list of one. Only the
  platform closes one — «تمت المراجعة» (`admin.order.piece_check.resolve`,
  `order.update`, a note **and the real count**, under a row lock);
  `PieceCheck::resolve()` refuses a reviewer with a `laundry_id` as well as a
  signed-in tenant, since a laundry holds `order.update` by design. The real
  count (`confirmed_count`, via `OrderTask::countedPieces()`) is what the next
  count is held to (`PieceCountSource::Settled` at the collection and at a
  later review), so a difference the platform already looked into is not raised
  again. A review is compared only once the handover to the laundry is
  confirmed; entered before that, `afterHandover()` checks it when it is. The
  one exception to «the platform closes it»: a second review whose count now
  agrees with the handover closes the review's own row (the laundry found the
  piece). `TaskService::complete()` re-checks the leg under a row lock, and
  `piece_discrepancies.order_task_id` is unique — a leg disagrees at most once.
  `PieceDiscrepancy` carries `order_id` and no `laundry_id`, so — like
  `OrderTask` — it is reached only through the scoped `Order`
  (`PieceCheck::resolveById()` → `OrderRepository::findDiscrepancy()`). **The
  driver app is not shown `expected_pieces` on a counted leg until it is
  completed, nor on the delivery leg until the collection is** (the owner's
  call: shown first, a driver copies it — and one driver usually holds all four
  legs, so the delivery's count would give the others away). The driver's
  order screen (`GET /driver/orders/{id}`) names the pieces without their
  quantities until the same moment — one predicate for both,
  `DriverTaskController::countsRevealed()`, so an items list cannot hand out
  the number `expected_pieces` holds back. «توجد مشكلة ← عدد القطع غير مطابق»
  (`PieceCountMismatch`, halts the order) is the separate path for a driver who
  will not complete the handover at all.
- `cleaning`, `ready_for_delivery`, `completed` and `returned` currently have
  **no endpoint driving them** — a known gap, not something to paper over.

## What a driver may change about themselves

`POST /api/v1/driver/profile` takes the vehicle, the licence and six documents —
and **writes none of them**. They are staged in `driver_record_submissions` and
apply when somebody approves them, through `Driver/Services/DriverRecordReview`.

- **The line is identity against record.** Name, email and profile photograph
  apply at once: they are the driver's own and nothing about them is verified,
  so holding them for review would be asking somebody to approve a nickname. A
  licence expiry is a claim about a document, and a record nobody checks is not
  a record.
- **Zones are refused outright**, from the app and from the queue alike.
  Territory decides who is handed work, so a driver choosing their own would keep
  the short trips and drop the rest. Availability has its own endpoint.
- **The dashboard still writes directly.** An operator editing a driver *is* the
  approval; routing them through their own queue would be theatre.
- **One pending row per driver** — a second submission supersedes the first
  rather than queueing two versions of the same car. Superseded rows are marked,
  not deleted.
- **The diff is computed at review time**, never stored at submit time: an
  operator may have corrected the same driver in between, and approving a diff
  worked out hours ago would silently undo them. Approval writes only the fields
  actually submitted.
- A rejection **requires** a note, and it reaches the driver. Told only
  «rejected», they send the same photograph again. The uploaded file is kept —
  a refused photograph is evidence of what was sent.
- `GET /driver/profile` carries `pending_review` with the exact `fields` waiting,
  so the app can mark those rows rather than greying the page. Without it the
  screen saves, redraws the old values and looks broken.

The screen is `admin/driver-record-submission`, gated on
`driver_record_submission.*` rather than `driver.update` — checking a licence
photograph is a different job from keeping a shift current — and `MenuBadges`
counts what is pending.

## Which laundry gets the order

`Order/Services/LaundryAssigner` decides, at placement and again whenever an
operator reassigns. Three filters then two rules, and the filters are not
negotiable:

1. **active**, 2. **has claimed the pickup address's zone** (`laundry_zones`),
3. **offers the requested service** (`laundry_services`, active). Nothing that
fails these is ever chosen, by any path, including the panel's picker.

**A zone is drawn on the map** (2026-09-29). `zones.boundary` is the ring of
`[lat, lng]` corners the owner drew on the zone form (`<x-zone-drawer>`, our
own small tool on the Leaflet already on every panel page — no drawing plugin),
with `min/max_lat/lng` beside it for an indexed SQL pre-filter. **Shape tools**
(2026-09-30) — rectangle, circle, triangle, freehand — turn a drag into
ordinary corners, snapped like a click; the server knows nothing of shapes and
checks the ring as any other. A circle is 32 corners walked round on the sphere
with `L.CRS.Earth.R` (the radius `map.distance()` measured the drag with);
freehand is simplified to ≤100 corners. A shape replaces the drawing (Undo
restores it), the tool drops back to «Corners» afterwards, and while a tool is
on the corners take no pointer — so a drag that starts on an old corner draws
the shape. The click the browser fires after a drag's release is ignored
(`shapedAt`), or it would add a stray corner. **The place search is one
component, `<x-map-search>`**, shared with `<x-map-picker>`: Nominatim on Enter
or the button only (its usage policy forbids autocomplete), results written as
text, and each map decides what a chosen place means — the picker drops its pin
there, the zone drawer only looks there. Its failure message is a prop, because
«set the pin by hand» means nothing on a map with no pin. **Nominatim reads ه
and ة as different letters** — «مدينه نصر», as it is typed, finds nothing or the
wrong place — so a word ending in ه is asked with ة first and then as typed,
one second apart (the usage policy), results merged (`spellings()`). ي/ى and
the alef forms it already folds. It is OpenStreetMap by the owner's choice
(2026-09-30): Google's Places/Geocoding would find Nasr City's districts, which
OSM does not map, but neither API is enabled on the key's project and it is
billed — do not switch it without asking. **Testing Arabic from the Windows
shell lies**: the command line mangles Arabic arguments before `curl` sees them
and every query comes back empty — send them from a UTF-8 file.
`Zone/Services/ZoneLocator` decides an address's zone from its pin, in
`AddressController` on create and edit: **inside a drawn zone → that zone and
its city, whatever `zone_id` the app sent, and whether the zone or its city is
switched on or off** (since 2026-10-07 — before, only an active zone in an
active city claimed a pin, so a pause erased the zone from any address edited
meanwhile, and a pin in a paused zone could be filed under another the app
picked, whose order went through); a zone **not drawn yet** is still taken at
the app's word, switched off or not (`AddressRequest` accepts any existing
zone), so an install moves over one zone at a time;
a pin **outside every drawn zone gets no zone**. Its order was accepted
unassigned until **2026-10-07**; the owner reversed that at the app team's
request: **an order whose pickup, or a different delivery, address has no zone
is refused** — at `POST /orders/quote` as well as `POST /orders`, inside
`OrderService::resolveContext()`, before anything is written, and a repeat
schedule on one at `RecurrenceService::create()` — as **422 with
`key: out_of_coverage`** (`OutOfCoverage`, `failReturnOutOfCoverage()`; the app
keys on that string, so it is a contract) and `errors` naming the field.
`Address::isCovered()` (a zone, and that zone **serving** — `Zone::isServing()`:
switched on, in a city switched on; `scopeCovered()` / `scopeServing()` in SQL
beside them) is the one definition behind the refusal, the app's `is_covered`, and the
screen's badge and order. A rung row goes back on the list at the next refusal. **A zone switched off takes no orders** — its addresses keep it, are refused,
and are taken again the moment it is switched back on: the switch is how the
owner chooses where the platform works (2026-10-07). A repeat schedule there is
not prompted (`RecurrenceService::promptDue()` lets the cycle pass). An active
zone not drawn yet is still taken at the app's word, so narrowing the platform
to some zones means switching off every other one, drawn or not. **A zone no laundry covers yet is still
accepted unassigned** — that gap is in our setup, not where the customer lives,
and `LaundryAssigner` keeps answering null for it. Each refusal is recorded by
the controller, after the service has let go (outside any transaction, so the
repeat-schedule path's rollback cannot take it) and never fatally, as one
`coverage_requests` row per customer and address (`Zone/Models/CoverageRequest`,
pin and street copied in, `attempts`): the panel's «خارج التغطية»
(`coverage_request.*`, super admin only, like complaints), whose badge counts
the rows whose address **has since gained a zone** and nobody has rung — the
moment the app's «we will contact you» can be kept.
Drawings **may not overlap** (`ZoneLocator::assertNoOverlap()`, from
`ZoneRequest::after()` and again inside the save's transaction with the
neighbours locked; against every drawn zone, inactive ones too). A border
shared along the same street is not an overlap: `Support/Geo/Polygon` is plane
arithmetic (no spatial extension) with a **tolerance of about 10 cm**
(`Polygon::TOLERANCE`), and the drawer **snaps** a corner onto a neighbour's
corner or edge — computed on plain lat/lng, the plane the server checks on, not
on the screen's projection. Overlap is found by edges crossing *or* a point
just inside an edge lying well inside the other ring, which is what catches the
same ring drawn twice. A ring with no area, a spike, or over 500 corners is
refused, each with its own message. Redrawing a zone, or switching a drawn one
on, re-locates the addresses it takes in or lets go, through the models
(`ZoneLocator::relocateAround()`): only an address a drawn zone (on or off) now
claims, or this zone's own outside its new drawing — never another zone's
address let go — and **an address with an order under way is not left in no zone**
(held, and the flash says how many). Switching a zone off moves nothing, and a
zone in a switched-off city still holds its ground: the switch decides service,
not where a pin is. A save that does not send `boundary` (a
spreadsheet row) keeps the drawing; the zones sheet has a read-only `drawn`. **Laundries and drivers are still matched by `zone_id`** —
unchanged, and right by construction once an address's zone is its pin's: a
driver given a zone is only offered trips inside it. A laundry may sit outside
the zones it serves (the owner's choice). `GET /cities` carries each zone's
`boundary` (null = not drawn).

Then, among what is left: **the nearest by road wins, unless a nearly-as-close
one has more room.**

- **Distance is the road, through `app/Services/Routing/`.** It was a straight
  line for the life of the project, which is the wrong answer on any map with a
  river in it. `RoutingService` resolves the driver, caches per
  origin/destination pair and falls back; `Router` is the contract,
  `HaversineRouter` the local arithmetic and the fallback,
  `GoogleDistanceMatrixRouter` the real measurement. **`phpunit.xml` pins
  `ROUTING_DRIVER=haversine`** so the suite runs offline and a fee a test
  asserts is arithmetic.
- **It is the Routes API**, `routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix`
  — the legacy `maps.googleapis.com/maps/api/distancematrix` endpoint **cannot
  be enabled on a recently created Google project at all**. Its elements come
  back **unordered**, carrying their own `destinationIndex`, so results are
  matched on that and never on array position.
- **The key is a settings row, `Google_Maps_Key`**, so it can be rotated from
  the panel. `config('routing.google.key')` wins when set. It is deliberately
  not in the seeder.
- **A fallback is never cached.** Caching a straight line for the 24-hour TTL
  turns a brief outage into a day of wrong distances and — since the fee
  measures the road too — a day of undercharged deliveries that nothing flags.
- **`Balance_Tolerance_Km`** (default **0**, which is the old behaviour exactly):
  everything within that distance **of the nearest** forms one group, and inside
  it the laundry with the **most free places** in the customer's pickup window
  takes the order. Measured from the nearest and never pairwise — a chain each
  within the tolerance of the last would let an order drift arbitrarily far.
- **Free places, not fewest orders.** Four of twenty beats none of two.

**Capacity is per laundry per window** — `laundry_slot_capacities`, edited from
`admin/laundry-slot-capacity` and from a tab on the laundry's edit screen, both
through one `sync()`. Blank is uncapped, `0` is «closed for this window», and
they are different answers. It counts the **pickup** leg only: it is about
washing machines, where `time_slots.capacity` is about vans and counts both
legs across the whole platform. The two do not constrain each other.

**The delivery leaves the service its time** (`Order/Services/Turnaround`,
2026-09-28). A service's `duration_min`–`duration_max` was display-only, so a
2–4-day wash could be collected and returned the same afternoon. The owner's
rules: **the middle of the range** (2–4 days = 3, 24–48 hours = 36); a service
in **hours is exact** — from the end of the pickup window to the start of the
delivery window; in **days, whole days** (picked up Monday, back from Thursday
in any window); no turnaround set, no rule beyond «not before pickup». Enforced
at `POST /orders` (`Concerns/DeliveryAfterTurnaround`, 422 on `delivery_date`
naming the earliest), surfaced as `too_early` on `GET /time-slots` (given
`service_id` + `pickup_date` + `pickup_slot_id`) and on the reschedule options,
and `delivery_after` (`{value, unit}` in the service's own unit — a day service
is «from the Nth day», never N×24 hours) on `GET /services`. **A postponed pickup pushes the
delivery** to the first window with room that fits (`RescheduleService::
keepDeliveryAfterPickup()`, legs' `due_at` moved with it, `delivery_moved` in the
response); a delivery rebooked too soon is refused. The quote takes no dates, so
it has nothing to check. **The range has a far end too**: `Delivery_Window_Days` (settings,
operations tab, 14 by default) after the earliest day; past it is `too_late`,
refused the same way — **for a new booking only**. A rebooked delivery is held
to the turnaround alone (`problem(..., withLatest: false)`): the window is
measured from the original pickup, and an order postponed weeks later would
have no day left. `OrderService::place()` holds the rule as a backstop. `GET /delivery-window` (`GeoController::deliveryWindow`)
gives the app the whole picture — `earliest`/`latest`, `chosen` with the exact
refusal message, a `suggestion`, and the day's `windows` with `available` — so
its bottom sheet fixes a conflict in place. `Turnaround::problem()` /
`message()` are the one source of both the check and its words.

**Nothing here ever refuses an order at checkout.** A zone whose laundries are
all full is the same shape of problem as a zone nothing covers, and
`SlotOverflowBehavior` (`Slot_Overflow_Behavior`) is where an operator says what
to do: accept it unassigned (default), give it to the nearest anyway, or hide
the window from the customer. The last needs `GET /api/v1/time-slots` to be sent
an `address_id` — until an app sends one it behaves like the first, which the
settings field says on its face.

**Capacity gates on `laundry_slot_capacity.update`, not `laundry.update`** — a
laundry owner holds the latter by design, and capacity decides how much work
they are handed. Same boundary as the commission rate.

`LaundryAssigner::evaluate()` is the whole decision — each candidate's leg, load
and the reason it lost — and `assign()` is that method keeping only the answer.
The order screen renders the rest, so the picker and the router cannot disagree.

**Automatic assignment can be switched off** (`Order/Services/AutoAssign`,
settings `Auto_Assign_Laundry` / `Auto_Assign_Driver`, operations tab; blank =
on = the old behaviour). Laundry off: `place()` stores no laundry — the fee is
still measured from the one the assigner *would* pick, and the quote's
`laundry` is null — and choosing one by hand recomputes the fee as always.
Driver off: `DriverDispatcher::automatically()` (leg creation, after a failed
attempt, after a reschedule) and the `tasks:dispatch` sweep do nothing; the
operators' own «وزّع» buttons still call `dispatch()` — a person deciding is not
what the switch stops. Either way `AssignmentNotifier` rings the bell of
platform staff holding `order.update` / `order_task.update` (one notice per
order's legs, not per leg). An automatic path added later goes through
`automatically()`, never `dispatch()`. **An order with no laundry is never dispatched
automatically** — `automatically()` and the sweep skip it (a driver would collect
a bag with nowhere to take it), and `OrderService::assignLaundry()` offers its
waiting legs once a laundry is set. Until 2026-09-28 an uncovered area's pickup
was dispatched regardless.

## Money: who owns which share

Added after the rest of the panel; `docs/order-cycle-explained.md` covers it in
prose. The total is composed in exactly one place, `OrderPricing::compose()`:
`subtotal − discount + delivery_fee + cash_surcharge = pre_tax_total`, then
`+ tax` (the rate is **copied onto the order at placement and never re-read**).

It splits four ways: **tax** → the state, never divided and never commissioned;
**delivery fee + cash surcharge** → the platform, which pays the driver out of
them; and **cleaning revenue** (`Order::cleaningRevenue()` = the laundry's own
piece prices, less their share of the discount) is the only part the platform
and the laundry divide.

**The percentage on a laundry is what the *laundry* receives** — 10 means the
laundry gets 10 of the hundred and the platform keeps 90. It was the other way
round (the platform's cut) until 2026-09-27; the client reversed it, and
`2026_09_27_100100_turn_commission_rules_into_laundry_shares` rewrote every stored
rate so no laundry's payout moved. The columns kept their names:
`commission_amount` is still the platform's part, `laundry_amount` the laundry's,
and `order_settlements.laundry_share_rate` is the rate the laundry was paid at —
null on rows settled the old way. «Per piece» (the client's wording) is the same
money as per order: the basis *is* the sum of the laundry's piece prices after
the discount, and computing it per piece would only move piastres of rounding.

**Who pays for a coupon is its own decision** (2026-09-27). The laundry's share
is measured on its prices *before* the discount; `coupons.discount_laundry_share`
(0 = the platform, 100 = the laundry, between = a split, null = the
`Coupon_Laundry_Share` setting, which empty means 0) says how much of the
discount comes off the laundry. It is **copied onto the order at placement**
(`orders.discount_laundry_share`, `discount_covers_delivery`) and never re-read.
The part of a coupon that came off the delivery fee is always the platform's.
The laundry is floored at zero; the platform is not — `commission_amount` can be
negative, and `settleFor()` then debits the super admin wallet under
`TransactionReason::DiscountFunded` with `allowOverdraft: true`, the only caller
allowed to overdraw. Setting the bearer on a coupon gates on `setting.update`
(`CouponRequest::prepareForValidation()` drops it from every input bag — query
string and JSON too — otherwise).

**A coupon can be limited to part of an order** (2026-09-28): `coupons.scope_type`
(null = the whole order / `service` / `category` / `item`) and `scope_ids`, one
kind and several of it. An offer's discount *is* its coupon, so an offer is
limited the same way — the owner's answer to «offers on a category, a service
or an item». `CouponService::validate()` takes the priced basket
(`OrderPricing::lines()`, extracted from `quote()` for this) and the discount
comes off `Coupon::eligibleSubtotal()` only; the minimum order is still the
whole order's. A code on some pieces **never discounts the delivery fee**
(`coversDeliveryFee()`), whatever its box says. `POST /coupons/check` takes
`service_id` + `items` to price the basket as checkout does; without them a
limited code answers 422 «worked out at checkout», never «invalid».
`applies_to` (`Coupon::scopeSummaries()`, one query per kind) is in the check
response and in `GET /offers`. The limit is **copied onto the order** (`orders.discount_scope`: type, ids,
the agreed discount, the rate) and `OrderReviewService` works the discount out
again on the pieces actually counted — a percentage re-worked, a fixed amount
capped, never above what was agreed. `discount_covers_delivery` is stamped
from `coversDeliveryFee()`, never the raw box: the settlement splits on it.

**A catalogue-wide price rise** lives in `Pricing/Services/PriceIncrease`, set from
the card above the price grid (`admin.pricing.increase`, `item_price.update`).
*For a period*, `item_prices` is untouched and the rate (`Price_Increase_Rate`,
optional `Price_Increase_Ends_At` — past it the rate reads as zero, no job
involved) is applied wherever a piece price is read: the quote, the review, the
review form, the grid preview, the app catalogue and the landing page (whose
cache key carries both rates). *Permanently*, it is written into `item_prices`
in one transaction and the period rate is cleared. The rise is the laundry's
price — it goes under the platform fee, into `base_unit_price` — and is stamped on
the order as `orders.price_increase_rate`, because `OrderReviewService` reads the
matrix again at review and must price at the rate the customer agreed to. Typed
prices on a quoted service never rise.

**Every rise is in a history** (`price_changes`, `price_change_items`), shown
under the card. A permanent rise records each price before and after, so it can
be **undone** (`admin.pricing.increase.undo`): only the latest permanent rise
still standing, and only prices still holding the rise's value — a price
corrected by hand since is left alone and counted.

| Class | Role |
| --- | --- |
| `Payment/Services/SettlementService` | resolves rules, computes the split, moves money |
| `Payment/Models/OrderSettlement` (+ `Line`) | one order's division — `pending\|settled\|cancelled` |
| `Payment/Models/CommissionRule` | a laundry's share — percent only, one active per laundry |
| `Payment/Services/EarningService` + `Models/DriverEarning` | per-leg driver bonus — `pending\|released\|cancelled` |
| `Driver/Services/BonusResolver` | which rule a driver is on, what one leg pays |
| `Driver/Services/MonthlyBonusService` | measures a month, applies gates, picks a tier |
| `Driver/Models/DriverBonusAward` | one driver-month — `due\|approved\|rejected` |
| `Support/PlatformAccount` | the platform's wallet = the **oldest super admin** |

**Cash at the door is a payment** (2026-10-01).

- **The delivery leg on an unpaid order requires `collected_amount`**
  (`TaskService::paymentAtTheDoor()`). `0` is allowed; more than
  `payableTotal()` is refused; on a paid order it is dropped. Before, a
  delivery closed without it left the order unpaid for ever: never
  `Completed`, never settled, the driver's bonus never released.
- **Anything above 0 is a captured `cash` payment** carrying `collected_by`
  and `order_task_id` (unique, so one per leg). It stays «with the driver»
  until `Payment/Services/CashCustody::receive()` stamps `handed_over_at` and
  `received_by`. That is `admin.payment.cash.receive`, on `payment.update`,
  refused inside a laundry whatever it was granted (`NotForALaundry`, its own
  class, so a lock timeout is not a 403). It receives **up to `up_to`**, the
  newest collection the screen showed: cash taken while the page is open is
  not in the operator's hand.
- **The payments screen lists what each driver holds.** Cash collected
  before the 2026-10-04 release was never recorded, so it is not there.
- **A cash payment refunds to the wallet only.** A refund to «the original
  method» needs a `provider_reference`, and a cash payment has none; a refund
  and an invoice look for the payment *with* one, so a card payment beside
  cash is still the one a card refund goes back to.
- **Revenue is still read off the orders** (`RevenueReport`), so the cash
  payments count nothing twice.
- **No `payment_method` is filed as cash** (`OrderService::paymentMethod()`),
  so the driver is shown cash. **The cash fee still follows what the customer
  chose**: a quote with no method shows none (`CashSurchargeTest`), and the
  total agreed is the total stored, so it is charged only when cash was sent.
  The app sent none on 23 of 31 live orders, and `2026_10_01_100100_…` filed
  those as cash.

At `Confirmed` a `pending` settlement is written (visible, nothing moved). At
`Completed`, `OrderStateMachine::settleMoney()` releases the driver's bonus and,
in one transaction, credits the laundry owner its share and the platform the
remainder (plus the customer's platform fee). `Cancelled`/`Returned` cancels both.

Rules that are easy to break by accident:

- **The commission basis is `cleaningRevenue()`, not `preTaxTotal()`.** Widening
  it re-creates the bug where the platform booked a cut of the delivery fee while
  also paying the driver out of it. See `SettlementService::basisFor()`.
- **A missing rule means different things on each side.** No share rule on a
  laundry → the general **`Laundry_Share_Rate`** setting; with neither, **nothing
  is divided** — the settlement stays `pending` and `settleFor()` refuses to move
  money, rather than paying the platform the whole of the laundry's work.
  `admin.settlement.settle` («سوِّ الآن», `setting.update`) pays it once a share
  exists. `Commission_Rate` is the *customer's* platform fee and is never read as
  a laundry's share. No driver bonus rule → **no bonus at all**. Inactive counts
  as absent on both.
- **One active share per laundry.** The rule form, the toggle and the laundry
  dialog all refuse a second; the fixed-amount basis is retired and cannot be
  switched back on. `CommissionBasis::Fixed` stays only so old rows still read.
- **Pending recomputes, settled/approved is frozen.** A `pending` settlement and a
  `due` award are rewritten on every recompute; once money moved the row is
  immutable and stores its measurements rather than deriving them.
- **Nothing pays on a schedule.** `drivers:close-bonus-month` (1st, 07:00, last
  month) computes and raises one notification — it *never* approves. Approval is
  a human act behind `driver_bonus_award.update`.
- **Money permissions are `setting.update`, not `laundry.update`/`driver.update`.**
  A laundry owner holds `laundry.update` by design, so gating its commission on
  it would hand the payer the dial. Same boundary for a driver's bonus rule.
- `per_order` bonuses pay only on `DeliverToCustomer` (four legs would pay 4×);
  tiers pay the **highest reached**, never summed; the laundry's share is the
  figure rounded and the platform's part is taken by subtraction, so the halves
  always reconcile.

## The home page counts; «ملخص الماليات» has the money

**No money on the home page** (the owner, 2026-09-30). The home page has no
permission of its own, so every panel account opens it: moderators, a driver
supervisor, a laundry's staff. A figure in pounds there was a figure all of them
read. `DashboardSummary` counts. `Report/Services/FinanceSummary` holds the money, on
`admin.finance.index` («ملخص الماليات», first in the Money group) behind
**`finance.view`**. That permission comes from `Report/Models/Finance`, a
permission carrier like `Report` and `LaundryRevenue`. Nobody holds it until
somebody grants it; the super admin bypasses as always. It is deliberately not
`report.view`, which every laundry owner holds.

- **Every revenue figure is `RevenueReport`'s** (`summary()`, `daily()`,
  `byMethod()`, `byService()`, `byLaundry()`, `receivables()`), dated by
  `paid_at`, so the finance page, the revenue report and the old home tiles all
  agree.
- **The comparison is like for like.** `compared()` measures the month so far
  against the same days of last month (`subMonthNoOverflow()`), never a whole
  month against part of one. With nothing to compare against, the change is
  null, not a percentage.
- **«Who keeps what» is this month's *settled* settlements.** It shows
  `laundry_amount` against `commission_amount` as the two halves of the washing.
  The delivery fees, the customer platform fee and the tax are listed beside it,
  never inside it (see `basisFor()`). The platform's part can be negative, and it
  is shown that way.
- **Tenant rules.** A laundry granted the page reads its own money through the
  scoped models. It is not shown the drivers' pending bonuses (`owed()['drivers']`
  is null: earnings carry no `laundry_id`) or the laundry ranking.

**The charts are `admin/partials/_viz`**: ApexCharts, which is already on every
panel page, plus a few HTML bars.

- **Colours are validated roles, set as CSS variables per theme.** `--viz-s1..8`
  is categorical in a fixed order; `--viz-o1..5` is ordinal (one blue, walked
  the other way in dark mode); `--viz-other` is the grey for everything else.
  They were checked with the data-viz validator against the card surfaces
  `#ffffff` and `#172033`. Text uses `--text-strong` / `--text-muted`, never a
  series colour.
- **Colour follows the thing, not its rank.** A service keeps the slot of its
  place among all services by id. Cash is the first payment method.
- **Charts redraw only when `body.theme-dark` actually changes**, so a modal
  opening doesn't trigger a redraw.
- **Tooltips are built by the partial and every string goes through `esc()`.**
  ApexCharts' own tooltip writes names with innerHTML, and a series name here is
  an operator-editable translation or a service name.
- **Data reaches the page as `<script type="application/json">@json(…)</script>`.**
  `@json` escapes `<`, so a name cannot close the tag; `HomeTest` guards this.
- **Every chart has its numbers in text.** A donut's legend is its table. A line
  or column chart has a `<details>` table under it. A donut is drawn only from
  three slices, and an all-zero series becomes a sentence.
- **Days use `date()` in SQL**, the convention of `RevenueReport::daily()`.
  Delivered, cancelled and picked-up counts come from `order_status_logs`
  (reached through the scoped Order, since the log has no `laundry_id`), not
  from `updated_at`.

## Notifications and the queue

A service calls a notifier (`OrderNotifier`, `DriverApplicationNotifier`,
`LaundryApplicationNotifier`, …) → **`NotificationDispatcher::send()`**. Every
event delivers on **both** channels — `NotificationEvent::channels()` returns
`['database','push']`; SMS is reserved for auth.

- **Business actions are synchronous, and that is deliberate.** An HTTP request
  pays the FCM round-trip inline, because a worker that dies is invisible and a
  delivery that silently did not happen is worse than a slow one. Push failures
  are swallowed and logged so a vendor outage never rolls back the business
  action.
- **One exception, and only one**: `app/Jobs/SendManualNotification.php`, the
  hand-written broadcast from `admin.notification.compose`. An announcement to
  three thousand customers cannot be done inside a request at all — each
  recipient is a round-trip — and the alternative was a cap on how many people
  could be told. **One job per recipient, never one per chunk**: a chunk failing
  at the sixtieth of a hundred would, on retry, reach the first fifty-nine a
  second time, and a duplicate notification cannot be taken back. Below
  `push.manual_inline_limit` (5) it still sends inline and reports «sent»; above
  it the flash says «sending», because telling somebody a message has gone while
  it sits on a queue is how a stopped worker becomes invisible.
- **The worker is a cron entry, not a supervisor daemon.** The box runs
  `queue:work --stop-when-empty --max-time=55` every minute under `flock -n`
  (lock at `/home/nahrnet/.laundo-queue.lock`), which needs no root
  and cannot leave a dead process behind — a stopped daemon looks exactly like an
  empty queue. `QUEUE_CONNECTION` is `database`; `composer dev` runs
  `queue:listen` locally. Worst-case latency on a broadcast is a minute, which is
  a minute nobody is waiting on.
- **Do not reach for a job for anything else.** If a new feature seems to need
  one, the question to answer first is what happens when the worker is behind,
  and for every other path in this codebase the answer is «the business action
  silently did not happen».
- **Only `push` is mutable** (`MUTABLE_CHANNELS`). `database` is a record, not a
  delivery: muting it emptied the in-app list *and* froze the rate-limit counter
  at zero, which handed the muted user unlimited push. Absent preference = on.
- **One account, one list** (`User::notifications()`, 2026-09-30). `Driver`,
  `Moderator` and `LaundryStaff` are the same `users` row through a narrower
  class, and Laravel files a notification under the class it was sent through —
  while the driver app's Sanctum token belongs to a `Driver`. So a broadcast
  sent through `User` never reached the driver's list. The override files and
  reads under `User` whatever subclass it is called on; do not send around it
  with `DatabaseNotification::create()`.
- **A driver who registered no handset is pushed nothing**, and until
  2026-09-30 none had: every `task_assigned` push was a «no registered device»
  skip. `notification_logs` is where to read that off — the `failure_reason`
  says which of «no device», «rejected permanently» or «send failed» it was.
- Rate limit `config('push.rate_limit_per_hour', 3)` per subject, counted off the
  `database` rows; `isTransactional()` events bypass both the cap and the mute.
- **A notification's `url` is a path, never `route()`.** It is stored now and
  clicked later, somewhere else, so an absolute URL bakes in whichever host built
  it: anything raised from the console or from tinker gets `APP_URL` or
  `localhost`, and one written behind Cloudflare's Flexible SSL gets whatever
  scheme survived the edge. A live notification shipped reading
  `http://localhost/admin/driver-record-submission`. `NotificationUrlTest` reads
  the notifier source and refuses `route(` or `url(` in that position — and
  checks the hand-written paths still match real routes, because writing one by
  hand means nothing validates it any more.
- **The FCM gotcha**: `data` is a *map* in FCM v1 and PHP's empty array encodes as
  `[]`, so Google 400'd every data-less message — and the driver read 400 as
  "device gone", so the dispatcher deleted every handset it notified. `data` is
  now set only when non-empty with string values, and **400 is no longer
  treated as permanent** (only 403/404). Don't reintroduce it.

## Excel export and import

`app/Support/Spreadsheet/` — one engine, one `Sheet` class per screen in
`Sheets/` (auto-discovered by `SheetRegistry`), one controller
(`Admin\SpreadsheetController`, `admin.spreadsheet.{export,template,import}`),
one Blade component (`<x-spreadsheet-actions sheet="city" search="#…" :filters="[…]" />`).

- **Export** is the screen's own scoped query narrowed by the same search term
  and filters the list sends, gated on `{model}.view`. Written row by row to a
  temp file (openspout), values never formulas. `query()` must carry no
  `orderBy` or join — the exporter walks it with `lazyById`.
- **Import** validates each row by building **the screen's own FormRequest**
  (`RowValidator`: POST for a new row, PUT with the route id for an edit, full
  `validateResolved()` cycle) and stores it through the module's own crud
  service. Row by row — good rows saved, bad rows reported with their sheet row
  number (the owner's choice). A row with `id` edits that record (looked up
  through the scoped query), without one it is added; nothing is ever deleted;
  a blank cell leaves the field alone (translatable columns merge per language).
  Capped at `Importer::MAX_ROWS` because it runs in the request. Gated on
  `{model}.create`/`.update`, and refused outright for anybody inside a laundry.
- Headers are field keys (`name_ar`, `country_id`), not translated labels, so a
  sheet round-trips. `Column::readOnly()` helpers (a country's name beside its
  id) are ignored on import.
- **Money and operations sheets are export-only** — a payment or a settlement
  typed into a spreadsheet is a ledger entry nobody earned.
- **Every string is written as a text cell** (`Exporter::row()`, `StringCell`).
  OpenSpout's own `Row::fromValues()` turns any string starting with `=` into a
  live formula, and names, notes and a *public* driver application all reach
  these sheets as typed — a `=WEBSERVICE(…)` name would send the other rows'
  phone numbers out the moment an operator opened the file. Do not go back to
  `Row::fromValues()` for data rows.

## The activity log — who changed what

`App\Services\ActivityLogger`, fed by wildcard Eloquent events
(`eloquent.{created,updated,deleted}: *`) registered in
`ActivityLogServiceProvider`, plus panel sign-in/sign-out. One `activity_logs`
row per model written: actor (name and role **copied**, so a deleted account
still reads), source (`dashboard` / `api` / `site` / `system`), route name, IP,
and `diff` — each field `{old, new}`. The column is `diff`, not `changes`:
that name is Eloquent's own `$changes` property.

- **Every screen and endpoint is covered without its own code** — a module
  added next month is recorded on the day it ships. What it cannot see is a
  bulk write that loads no model (`Model::where()->update()`, `DB::table()`):
  those fire no event. Route a change a person makes through a model.
- **A logging failure never fails the change** — caught, logged as
  `[activity] not recorded`. The row is written in the change's own
  transaction, so a rolled-back change leaves no row.
- **Secrets are recorded as changed, never by value** (`••••`):
  `config/activity.php` `redacted` substrings (`password`, `token`, `otp`,
  `secret`, `payload`, `api_key`) and `redacted_setting_keys`
  (`Google_Maps_Key`). A new credential column or setting goes on that list.
- **Excluded** models (logs of their own, delivery artefacts, rows derived from
  a recorded parent) and **ignored** attributes (timestamps, the driver's
  position, OTP, remember token) are in the same file; an update touching only
  ignored attributes writes nothing.
- **It is read by the owner, so it is worded for the owner.**
  `App\Services\ActivityPresenter` turns a row into a sentence — «تعديل مدينة
  «القاهرة»» — with who by role name (`activity.roles`), where from by the
  sidebar screen the route belongs to, and only the fields that mean something
  (`hidden_fields` / `hidden_suffixes`), each in words: enums by their label
  (read off the model's casts), ids by the name of what they point at
  (`activity.references`, scopes left **on**), a password as «changed», a date
  in the display timezone. What a record *is* comes from `activity.nouns`. The
  screen, its Excel export and the order history all word a change through it —
  add a noun there when you add a model, or it reads by its class name.
- **A record's name is kept in every language** (`subject_label` holds the JSON
  for a translatable name, `ActivityLog::subjectName()` picks the reader's), so
  a change made on the English panel does not read in English on the Arabic one.
- **`order_id` is stamped** on the order's row and on anything carrying
  `order_id`, which is what `Order/Services/OrderHistory` reads: the order
  screen's «السجل» merges `order_status_logs` (the statuses, never pruned — the
  app's tracking screen is built from them) with these rows, and drops the
  order's `status` from its own diffs so a transition is not shown twice.
- **The order's history is an allow-list, not a way round other permissions.**
  Everybody who may see the order — a laundry owner included — sees only its
  working parts (`OrderHistory::OPEN`: the order, its pieces, legs, photos, price
  queries, coupon use). A complaint, a driver's pay, a payment, a refund, the
  settlement, a rating show only with their own screen's `.view` (`GATED`), and
  any kind of record nobody named only to `activity_log.view`. A laundry never
  reads a complaint — its body and the platform's `internal_note` were one
  missing entry away from every laundry owner's screen.
- The global screen is `admin/activity-log` (`activity_log.view`, system group),
  read-only, export-only sheet; it leaves out any model on the `excluded` list,
  including rows written before it joined. Permissions are excluded — the
  seeder writes them on every deploy. `laundo:prune` keeps `retention_days`
  (183 — six months, the owner's call).

## List pages: server render + AJAX search

Every list module has both `index` and `search` routes:

- `index` returns the full view, or `response($view)` when `$request->ajax()`.
- `search` (AJAX only) returns JSON `{table, pagination}` by rendering `admin/{module}/partials/_{module}_table_body.blade.php`.

The client half — **`setupAjaxSearch({inputSelector, tableBodySelector, paginationWrapperSelector, url, colspan, errorHtml, extraParams})`** and the `.toggle-status` click handler — is defined **inline in `resources/views/layouts/footer_script.blade.php`**, not in `public/assets/js/custom/`. Index views wire it up in a `@push('scripts')` block (`layouts/main.blade.php` renders `@stack('scripts')`).

It binds **`keyup`**. Driving it from a test with Playwright's `page.fill()` sets the value without firing that event and the search looks broken — use `page.type()`.

**`extraParams`** (object or function) is how the twelve filtered screens keep
their dropdown selected across a search and a page change — orders, payments,
wallet, settlement, refund, complaint, rating, recurrence, notification,
dispatch, earning, driver_bonus. Pass a *function* so the value is read at
request time rather than at wire-up time.

The term is sent as **`query`**, and a `search()` action must read it as
`$request->get('query')` — **never `$request->query`**, which is Symfony's
public ParameterBag property and hands you the bag, not the term.

**`Searchable` (`app/Trait/Scopes/Searchable.php`) reaches across relations.**
Columns may be dotted paths (`profile.vehicle_type`, `laundry.city.name`),
resolved with `whereHas`, plus an `$expressions` list for a cell no column holds.
The rule the owner set is that **anything a list screen displays is searchable**
— so when you add a column to a table body, add it to the model's search list
too. Aggregates and `humanDate()` columns are deliberately excluded.

## Two search patterns — picking the wrong one blanks data

`setupAjaxSearch` re-renders rows **from the server**. On a screen that is one
big bulk-edit form — the price grid, the roles permission matrix — the cells the
partial does not render come back empty and **are blanked on save**.

Those screens use **`setupClientFilter({inputSelector, itemSelector, groupSelector, siblingHeadingSelector, emptySelector, countSelector})`** instead (same file): it
hides non-matching rows with `style.display`, fetches nothing, and leaves every
input in the DOM so a save still posts the whole grid. `ClientFilterWiringTest`
guards which screens are wired to which.

## Stack lists, not tables

Newer list screens are card rows, not `<table>`: declare `$stackCols` once in the view and inject it as `--stack-cols` on **both** the `.stack-head` strip and the `.data-stack` container, one `<span>` per row `<div>`. These pass `errorHtml` to `setupAjaxSearch` and **no `colspan`**. Copy `resources/views/admin/offer/` rather than an older table-based module.

## Multi-language data (the biggest gotcha)

Translatable columns hold JSON `{"en":"…","ar":"…"}` in a **`text`** column, handled **manually — there is no `$casts` entry**:

- **Write**: the service does `json_encode($request['name'], JSON_UNESCAPED_UNICODE)` before hitting the repository.
- **Read**: the model defines `getNameAttribute($v) => json_decode((string) $v)` — returning a **`stdClass`, not an array**. So it's `$row->name->en`, never `$row->name['en']`.
- Models also override `asJson()` to keep `JSON_UNESCAPED_UNICODE` (otherwise Arabic is stored as `\uXXXX`).
- Form inputs are **arrays keyed by language code**; requests validate `'name' => 'required|array'` plus `'name.*'`.
- In Blade use `getLocalizedValueDashboard($model, 'name')` (default language) or `getLocalizedValue()` (request locale from the `lang` header).

**At least one language, not all of them.** Requests validate that *some* language was filled rather than requiring every one — a client writing Arabic-only copy is normal. Reading falls back preferred → default → any non-empty via `pickTranslation()`, using `filled()` so a whitespace-only value doesn't win. When relaxing this on a form, remove the client-side `required` too, or the browser still blocks submit.

Adding a translatable field means touching four places: migration, `$fillable`, the `getXAttribute` accessor, and the `json_encode` in the service.

`languages.default` and `languages.is_rtl` are **enum string `'true'`/`'false'`**, not booleans — `where('default', 'true')`.

**The Web File — where the public site's copy lives.** Four JSON files per
language: `{code}.json` (the panel's own, ~2,200 hand-authored entries),
`{code}_panel.json`, `{code}_mobile.json` and `{code}_web.json`.

```
storage/app/webFile.php         the key list + English defaults (~190 keys)
   -> php artisan laundo:sync-web-lang    merges new keys into every language
resources/lang/{code}_web.json  edited from the languages row's dropdown
   -> webText('landing.hero.title')       locale -> default -> template -> key
```

`webText()` is the reader. Its last-but-one rung is the template, which is what
makes adding a key safe: it renders its English default everywhere immediately
and each language overrides it when somebody translates it. The dashboard's
`updateWeb()` runs `array_filter()`, so blanking a value there *deletes* the key
and the template default takes over — which is the behaviour you want from a
"reset this string" box.

**Never run `LanguageHelper::generateJsonLanguageFiles()` to add web keys to an
existing language.** It merges panel + mobile + web into `{code}.json` too, and
`TranslationCoverageTest::no_arabic_value_is_left_in_english` fails the build on
any `ar.json` value holding no Arabic — so it would tip a hundred English
marketing strings into the panel's translation and redden the suite. That is why
`laundo:sync-web-lang` exists and writes `{code}_web.json` **only**; a test
asserts the six other files are byte-identical after a sync.

`GET /api/v1/translations/web` serves the same file to the apps. The landing
page is its second consumer, not a replacement.

**Two editors, one file.** «تعديل محتوى الصفحة التعريفية»
(`admin.language.landing`) groups the `landing.*` keys by section in page order
with readable headings and the shipped default as each placeholder — that is the
one to use for marketing copy. «Edit Web Json» is the flat key/value list, still
right for the app-override keys it was built for. Both write
`{code}_web.json`; the landing screen can only write the `landing.` namespace,
so it cannot touch the ten keys the apps read.

**Validation messages have their own editor** — «تعديل رسائل التحقق»
(`admin.language.validation`, `language.update`). `lang/{code}/validation.php`
is PHP, which no screen can safely write, so it stays the shipped default and
what the screen saves goes to **`storage/app/lang/{code}_validation.json`**
(`config('app.validation_overrides_path')`; flat keys — `required`,
`min.string`, `attributes.phone`). In `storage/`, **not** `resources/lang/`:
it is runtime data, and a tracked directory the server writes into is one
`git pull` refuses (see **Deploying**). `ValidationOverrideLoader` decorates
`translation.loader` — registered with `extend()` in
`AppServiceProvider::register()`, because the translation provider is deferred
and would replace a plain binding — and lays the file over the `validation`
group with `lay()`, **not `Arr::undot()`**: everything after `attributes.` is
one key, because a field name can hold dots (`items.*.quantity`).
- The rows are **`en`'s keys with the language's own laid over them** — Arabic
  ships 37 fewer messages than English (`password.*`, `decimal`, …), which
  answer Arabic users in English, and those are the ones most needing a box.
- Two guards in `Services/languages/ValidationMessages::save()`, all or
  nothing: **only keys the shipped files have**, and **every `:placeholder`
  kept** in a spelling Laravel fills (`:min`, `:Min`, `:MIN` — not `:mIN`),
  refused keyed by the input's own name (`messages[min.string]`) so the
  background submit paints it beside the box. `lay()` re-checks the
  placeholders at load, so an override a later release outgrew is not served.
- A language with **no `validation.php` of its own** is laid over `en`'s
  lines, not over nothing: Laravel falls back per message but reads
  `validation.attributes` as one array, so naming one field would un-name the
  rest. Blank is a reset; an emptied file is deleted; a rename moves the file,
  a deletion deletes it. Each save is an activity-log row against the language
  (`ActivityLogger::recordChanges()`, the wording before and after).
- The wording now reaches every refusal, so **no validation text may be printed
  unescaped**. `footer_script`'s toasts were `showErrorToast("{!! $error !!}")`
  — one quote closed the string — and are `@json()` now; keep them that way.

All the editors hang off `admin/language/shared/controlBut`. They used to hang
off `x-action-button-lang`, **which nothing renders** — so `admin.language.panel`,
`.mobile` and `.web` were reachable only by typing the URL. Do not "tidy" that
partial back to the component: its action trio is ungated and the language
list's actions go through `canDo()`.

## Permissions

Slugs are `{model}.{action}` with actions fixed at **view, create, update, delete, toggle** (`PermissionGenerator::$actions`).

Permissions are **generated, not hand-listed**: `PermissionSeeder` runs `PermissionGenerator`, which walks `config/dashboard.php`'s `models` array, keeps only classes using the **`App\Trait\DashboardModel`** trait, and derives the slug from `Str::snake(class_basename())`. A new model gets permissions only after it is added to `config/dashboard.php` **and** uses that trait. The generator only ever creates — it never prunes, so a removed model leaves its permissions behind.

Three enforcement points, all bypassing checks for `role.slug === 'super_admin'`:

| Where | How |
| --- | --- |
| Routes | `middleware('permission:category.view')` (`CheckPermission`) |
| Blade | `canDo('category.create')` helper |
| Sidebar | `MenuBuilder` derives visible items from the user's `*.view` permissions |

`EnsureDashboardRole` (`dashboard.only`) additionally gates all `/admin` routes on `role.type`, which must be **`dashboard` or `laundry`** — not `dashboard` alone. A laundry owner and their staff sign in to the same panel and are confined by their permission set and by the tenant scope, not by the gate; `app` (customers, drivers) stays locked out. System roles/permissions are flagged `is_system = true` and should not be deleted.

`RoleSeeder` also ships **`driver_supervisor`**, which is deliberately *not*
`is_system`: it is a starting point an install adjusts, not a structure. It
carries the driver, application, record-review and dispatch permissions and
**no money permission at all** — every driver money term gates on
`setting.update` precisely so the person managing a driver is not the person
setting what that driver is paid. It exists because the record-review
notification is addressed to whoever holds `driver_record_submission.update`,
and without a role that has it, that is nobody.

Laundries therefore have a **second front door**: `GET /laundry/login`, whose form posts to the same `login` route — one authentication path, so throttling, the session and the `/admin/home` redirect cannot drift. `/login` still works for them; the separate page exists because it was headed «Admin Control Panel» and nothing told an owner the account they were handed belonged there. `auth/passwords/{email,reset}` and the admin login extend **`layouts.auth`** — the shell lifted out of the old `login.blade.php`, deliberately *not* `layouts.app`, which is the panel's only Vite chain.

The **laundry** pages (`/laundry/login`, `/laundry/register`, `/laundry/applied`) extend **`layouts.auth-card`** instead: a card on a navy ground, loading `landing.css` + `auth-card.css` and nothing else. They read as a continuation of the marketing site the applicant arrived from, and they get IBM Plex Sans Arabic — the panel's own shell still has no Arabic webfont. `class="landing"` on `<html>` is load-bearing there: landing.css scopes its dark tokens and its 100% root font size to it.

Laundries can also **apply for themselves**: `GET /laundry/register` files the laundry `inactive` with `approved_at` null and the owner `inactive`, and `admin/laundry/pending` is where an operator approves or rejects. Approval flips both halves *and every staff account on the laundry* — turning on one of two is a half-open door. **Pending is a null `approved_at`, never a third `status` value**: `status` is the binary the toggle button drives and a dozen queries filter on, and a pending laundry is simply `inactive`, which they already exclude. A laundry the panel creates is stamped approved on the spot, because an operator creating one *is* the approval.

**A laundry's services change only when the platform approves.** The application
names at least one (`services[]`, filed as `laundry_services` rows on the
inactive laundry — approving the application approves them). Afterwards the
laundry's own services screen does **not** write: saving it files one
`LaundryServiceRequest` per difference (`open`/`close`, one pending per laundry
and service, a new ask supersedes the old), and `laundry_services` — what
`LaundryAssigner` reads — stays as it was until somebody approves on
`admin/laundry-service-request` (`laundry_service_request.*`, badge in the
sidebar). So a service asked to open brings no orders yet and one asked to close
keeps bringing them. A refusal requires a note, which the laundry sees beside the
service; both sides hear in the panel bell (`LaundryServiceRequestNotifier`). An
operator saving the same screen writes at once and supersedes the laundry's
pending asks — the edit *is* the approval, as with driver records.
`LaundryServiceRequestReview::mayReview()` refuses any actor inside a laundry,
even one somebody granted the permission to.

**Sign-in is gated on `status = active`** (`LoginController::credentials()`). It was not, for the whole life of the panel — `AuthenticatesUsers` matches email and password and nothing else, so any inactive account signed straight in while the API refused it. A pending laundry gets a «still being reviewed» message rather than the generic failure; everyone else gets the generic one, so the form cannot be used to discover which addresses hold accounts.

A locked-out owner is given a new password from the **laundry edit screen** (`owner_password`, blank means unchanged) — `Laundry::owner()` is the relation that finds them. Note it is a plain constrained `hasOne` ordered by id: `latestOfMany()` builds its aggregate subquery *without* the constraints declared before it, so on a laundry that also has staff it picks the newest staff row and the role filter then discards it, returning null.

**Drivers apply too, but differently.** `POST /drivers/apply` (public, no GET —
the form is on the landing page) files a `DriverApplication` row, which is a
*lead*, not an account: the operator reads it on `admin/driver-application`,
marks it handled (`toggleHandled`), and creates the driver by hand. A laundry
application creates real inactive rows; a driver application does not. Don't
unify the two paths without knowing that.

## Sidebar

**Queue counts.** `App\Services\MenuBadges::for($model)` returns a number or
null, and `MenuBuilder` hangs it on every item; a closed dropdown carries the
sum of its children. Two rules, both tested in `MenuBadgeTest`: **only work
waiting on a person** (never a row count — a badge beside Zones reading 25
teaches an operator to stop reading the ones that matter), and **zero draws
nothing**. It is a class and not a config entry because a closure in
`config/menu.php` does not survive `config:cache`, and it is uncached because a
stale badge on a queue reads as "nothing waiting" to somebody who then does not
look.

`config/menu.php` drives everything. `MenuBuilder` intersects its keys with the
user's `*.view` permissions. Four top-level keys:

- **`groups`** — the dropdowns, each `{order, title, icon, items: [model keys]}`.
  Nine of them: `locations`(1), `catalog`(2), `laundries`(3), `delivery`(4),
  `marketing`(6), `order`(7), `operations`(8), `money`(9), `system`(99).
- **`singles`** — a `model => order` **map** (`user`:5, `report`:10),
  interleaved with the groups by that number. `order` used to be a single; it is
  now the first item of an **`order`** group (7) beside `order_today`, the way
  Marketing holds its screens.
- **`permissions`** — a key that borrows another model's `.view` permission
  rather than having its own. `order_today` («طلبات اليوم», `admin.order_today.*`)
  is the orders list read as a day's work, so it answers to `order.view`; a new
  permission would need a model to generate it and a grant to every role that
  can already see orders. `MenuBuilder::visible()` reads it. Its routes stay
  `admin.order_today.*` on purpose: the sidebar lights an item by route prefix,
  and under `admin.order.*` «All orders» would light up on this page too.
- **`icons` / `titles` / `routes`** — three parallel maps keyed by model name,
  one entry each per screen and exactly as many as `groups` + `singles`. A key
  present in two of the three renders with a null in the third, so the three
  counts agreeing is the cheap check that a new module is fully wired. **Deliberately
  not written down as a number here**: it moved twice in one day, and a count
  nothing verifies is a line that quietly becomes false.

A new module needs an entry in a group's `items` (or in `singles`) **and** in all
three UI maps, or it renders with nulls. **Menu keys are not always the module
name**: `order_task`, `driver_earning`, `order_settlement`, `order_rating`,
`order_recurrence`, `item_price` and `notification_log` are menu/permission keys
whose code lives in a differently-named module.

## The public site

A third surface, added after the panel and the API: a marketing page at `/`,
`/ar` and `/en`, plus `/{locale}/terms` and `/{locale}/privacy`. `/` used to
return `view('auth.login')`; the login form is at `/login`, which
`Auth::routes()` has always registered. **A signed-in user is shown the page.**
Bare `/` used to redirect them to `/admin/home`, which meant an operator had to
sign out to look at the marketing site — and `/ar` let them through anyway, so
the rule was inconsistent as well as unhelpful. The way in is offered instead, by
`landing/partials/_account_bar`, and only to an account that has a panel:
`User::canReachPanel()` is the one definition of that and `EnsureDashboardRole`
calls it, because two copies of the test is how the button comes to invite a
customer into the 403 the middleware is about to throw.

**It does not extend `layouts.main`.** That chain loads ~22 stylesheets and ~30
scripts (`app.css` 399 KB, `theme.css` 93 KB, `bootstrap-icons.woff2` 110 KB,
ApexCharts, TinyMCE, select2, FilePond, jsTree, jQuery UI). `layouts/landing`
loads `landing.css` and a deferred `landing.js` and nothing else; icons are
inline SVG. `LandingPageTest` and `landing.spec.js` both assert none of the
admin assets is requested — **do not add one to this layout.**

- `landing.css` **re-declares the design tokens** rather than importing
  `theme.css`. `theme.css` stays canonical, and `tests/Unit/LandingTokenParityTest.php`
  fails the build if a shared token's value drifts. Change a brand colour there
  and this file has to follow.
- `html.landing { font-size: 100% }` undoes the panel's `87.5%` density zoom, so
  landing CSS is authored against a 16px root. Only tokens are shared, never
  layout classes.
- Logical properties throughout (`margin-inline`, `inset-inline-start`), so one
  stylesheet serves both directions. `rtl.css` is **not** loaded.
- **Arabic type**: IBM Plex Sans Arabic, self-hosted, two weights, Arabic subset
  only. Latin stays Nunito and `unicode-range` routes each glyph. Before this
  the project shipped **no Arabic webfont at all** — `--bs-body-font-family:
  Nunito` with no fallback stack.
- `landing.css` / `landing.js` are fingerprinted with `filemtime()` via
  `landingAssetVersion()`. They have no build step, so nothing else busts a
  visitor's cache after a deploy.

**All marketing copy is Web File-driven** — see the localization section below.
Nothing user-facing is hardcoded in a landing Blade file.

`LandingContentService::pageData()` is cached for an hour, and its **cache key
carries `filemtime()` of the service**. That is not decoration: adding a key to
the payload without it leaves the previous array in place and the view dies on
an undefined variable — a 500 on the front page for up to an hour after the
deploy, looking like a bad release rather than a stale cache. Keep the stamp if
you change the assembler.

`journey_steps` and live `offers` render from their own dashboard screens.

`LandingContentService` supplies the facts (services, prices, coverage, windows,
the timeline) and enforces two rules that are easy to undo by accident:

- **No development data on a public page.** Every settings read goes through
  `realSetting()` / `isPlaceholderSetting()`, which refuse the values
  `SettingsSeeder` leaves behind — `App_Name = BaseCode`, `nahrPhpTeam@…`, the
  seven social URLs pointing at their networks' front pages, the lorem-ipsum
  `About`. Laundries are never listed. Live offers **are** rendered (`offers()`),
  but an offer's discount badge is withheld while its coupon
  `looksLikeTestCode()` — `Offer::badge()` publishes the linked coupon's
  discount, and this install's only offer links to `SMOKE10`. (The class
  docblock still says offers are not rendered at all; the method is right.)
- **An empty table is a missing section, not a broken one.** `faqs`, `intros`,
  `banners` and `order_ratings` hold zero rows; the FAQ falls back to Web File
  copy and takes over from it when rows appear.

Also deliberate, and worth knowing before "fixing" it: the page markets **cash
on delivery only**. Card, wallet and InstaPay are `PaymentMethod` cases the app
draws and the only gateway in the codebase is `FakeGateway`.

`LandingController` reads route parameters off the request rather than as
arguments — Laravel binds them **positionally**, and `->defaults()` plus a
`{locale}` segment swaps them. See `tasks/lessons.md`.

Guest language switching is `GET /locale/{code}` (`LocaleController`). This is a
second door on purpose: `admin.language.set-current` sits behind
`['auth','dashboard.only']`, and its URI is hand-written in **14 places across
five Playwright specs** — leave it alone.

## The API layer

`routes/api.php`, a hundred-odd endpoints under `/api/v1`, controllers in `app/Http/Controllers/Api/V1/`, requests in `app/Http/Requests/Api/V1/`. `php artisan route:list --path=api/v1` is the count; `docs/postman/generate-reference.py` prints it on every run.

- **Responses** go through `app/Helpers/ApiResponse.php` — `successReturnData()`, `successReturnCreated()`, `successReturnPaginated()`. The envelope is `key`, `status`, `msg`, `code` plus `data`/`errors`/`meta`. **`status` is `success`/`error` derived from the code by `apiResponseStatus()` — never pass it in**, or a call site will eventually disagree with its own HTTP status; `key` is the one that says *which* outcome. The panel's `ResponseService` is a different thing (it `throw`s / returns `never`); don't mix them.
- **`successReturnPaginated($items, $paginator = null, $msg = '')`** — items
  first, paginator second. Getting them the wrong way round is a **silent**
  failure: a paginator has a `__toString()` that renders the Blade pagination
  *view*, so the response came back 200 OK with a page of Bootstrap `<nav>`
  markup in `msg` and an empty `meta`, and nothing in the log. Every call site in
  the API was once written that way. `ApiContractTest` guards it now.
- **Auth** is Sanctum on the `api` guard, one `users` table for both apps. Customer tokens are named `mobile`, driver tokens `driver-app`.
- **Driver endpoints are not gated by middleware.** Each driver controller resolves `Driver::find($request->user()->id)` and does `abort_unless($driver !== null, 403, …)` itself, which is what stops a customer's token (a plain `User`) operating them. Adding a driver endpoint means repeating that, not adding a middleware.
- **`$request->user()` is not the same class in both apps.** A token is minted on the model that called `createToken()`: a customer's (`mobile`) on `User`, a driver's (`driver-app`) on **`Driver`**, so for the driver app `$request->user()` *is* a `Driver`. Anything keyed on the caller's class (`getMorphClass()`, a morph relation) splits one account in two. That is how every broadcast to drivers missed the driver app's notification list until `User::notifications()` pinned the type (2026-09-30).
- **Named rate limiters** beyond `api`: `otp`, `otp-verify`, `login`, `location`, `tracking`. Auth routes carry them individually. `location` is 60/minute because the driver reports every four seconds — it was 30, sized for a thirty-second cadence, which left no headroom for a retry on the very stream the map is drawn from. `tracking` is the customer polling that map, on its own bucket so it cannot spend the 60/minute the rest of the app shares.
- **`ComplaintCategory` has two sets, not one.** `offeredTo($audience)` is what the picker lists; `acceptedFrom($audience)` is what submit accepts, and it is wider. `support_request` — the «تواصل معنا» message box — is accepted and **never offered**, because that screen has no category chooser and the app sets it; listing it would put a screen's name among kinds of problem. `?audience=customer|driver` narrows both, mirroring `GET /faqs`, and narrowing the list without checking it on submit would make the whole thing decoration.
- **`GET /orders/{id}/driver-location`** is the moving marker's own endpoint, split from `/track` because the two are asked at completely different rates. It answers `tracking`, `poll_after_seconds` (null means stop asking), and `location` / `last_seen` in the shape `/track` uses. `location` is the recommendation — fresh enough to draw live — and `last_seen` is the fact behind it, with `age_seconds` and `is_stale`. Both sit behind the same privacy gate as the dot: the three customer-facing legs, `assigned` or `started`.
- **`config/tracking.php`** holds the freshness window and the poll cadence, both env-tunable, **and the order matters**: the window comes down *after* the apps report faster, never before. Tightening it first blinks the dot off between reports, which looks exactly like the bug it is meant to fix.
- **`?audience=driver` on `/app-settings`** swaps the four support lines for the driver's own, under the same keys so one parser serves both apps. A blank driver line falls back to the customer's — a courier stranded at a doorstep has to reach somebody, so blank means «no separate line», never «no line».
- Controllers keep a private `present*()` method per payload shape (e.g. `OrderController::presentSummary()` vs `presentDetail()`). A field added to a summary must be eager-loaded in the corresponding `index()` or it is an N+1 — there are query-count tests guarding this.
- Domain vocabulary lives in **PHP enums** under `app/Modules/{Name}/Enums/` (`OrderStatus`, `TaskType`, `PaymentMethod`, `PaymentStatus`, `TransactionReason`, …). Prefer these over string literals.
- Password reset is **two steps**, for the panel and both apps: `verify-reset-code` spends the code and issues a single-use ticket, `reset-password` takes the ticket. Shared in `app/Services/Auth/PasswordResetTicket.php`. Never accept code + new password in one call.
- Cross-field rules shared between requests go in `app/Http/Requests/Api/V1/Concerns/` (see `OneDiscountPerOrder`).
- **`POST /complaints` serves both apps, and `order_id` is optional for that reason.** A customer reaches it from an order; a driver reaches it from the account screen with no order in sight. When one *is* named it resolves through `ComplaintService::orderTheyCanName()` — orders the complainant **placed or was given a leg of**. Widening that to any order files a complaint against a stranger's laundry; narrowing it back to `$user->orders()` is the bug it replaced, where a driver naming the job they had just delivered got a 404. **One complaint per order for its customer, for good** (the owner, 2026-10-07): the customer's second naming the same order is a 422 on `order_id`, checked under a lock on the order row (`ComplaintService::hasComplained()`); a closed one still counts, a driver is not limited (two doorsteps, days apart, and no flag in the driver app to explain a refusal) and does not use up the customer's, and `support_request` neither counts nor is refused. The rule is `Complaint::scopeUsingUpTheOrder()`, which also gives the order list its `has_complaint` (a `withExists`, no query per card).

## Money, phones and dates

- `appCurrency()` reads the `Currency` setting, validates `/^[A-Z]{3}$/`, falls back to `EGP`. `moneyFormat($amount, ?string $currency = null)` formats through `NumberFormatter` with a `-u-nu-latn` locale extension, so Arabic renders **Western digits** — Arabic-Indic numerals in prices is a bug, not a locale preference.
- `phoneRegex()` is **E.164** (`/^\+[1-9]\d{7,14}$/`). Stored numbers are normalised to it; don't reintroduce a local-format regex.
- One discount per order: `coupon_code` and `offer_id` are mutually exclusive, the offer wins, and a code sent beside it is **refused with a message** rather than dropped. Enforced at the quote as well as at submit.
- **Timestamps are stored UTC and shifted only at render.** `config('app.timezone')`
  is `UTC`; `displayTimezone()` reads `app.display_timezone` and falls back to it.
  `humanDate()` is where the conversion belongs for anything **rendered** —
  doing it in the application timezone corrupts what gets written back.
  `isoDate()` is the machine-readable counterpart for API payloads. This is also
  why date columns are deliberately not searchable: matching the text somebody
  reads would need a timezone conversion in SQL.
  **There is one other legitimate conversion, and it goes the other way**:
  `DriverTaskController::applyDay()` takes the day the driver picked, resolves it
  in `displayTimezone()` and compares against the UTC range it covers. Filtering
  is not rendering, so `humanDate()` cannot do it — and a `whereDate` on the raw
  column files every hour either side of midnight under the wrong date, which
  looks right in every test written in UTC. Do not simplify it back.
  **And a third, `TimeSlot/Services/SlotClock`** (2026-09-30).
  - It is the one place a window's «08:00–10:00 on Thursday» becomes an
    instant, in `displayTimezone()`. Every leg's `due_at` (`TaskGenerator`) and
    the customer's arrival estimate (`OrderEta`) go through it, as does every
    «can today's window still be booked».
  - Before this, `TaskGenerator` read the windows as UTC, so `due_at` sat three
    hours after the moment it meant. Also, nothing refused a window that had
    already ended: an order was taken at 14:11 for that morning's 08:00–10:00,
    and its driver was «late» on the spot.
  - **A window of today closes `Slot_Booking_Cutoff_Minutes` before its end**
    (Operations tab; blank = 60, 0 = until it ends).
  - Where the closed state shows up: `GET /time-slots` carries `closed` and
    `closes_at`; `GET /delivery-window` and the reschedule options carry
    `closed`, and it is folded into `available`.
  - Who refuses it: `POST /orders` (`Concerns/WindowStillOpen`, 422 on the
    slot field, with `OrderService::place()` as the backstop), and a reschedule
    (`slot_closed`).
  - `Turnaround` needs none of this: it only compares windows with each other,
    both on the same wall clock.
  - The open legs were re-dated by
    `2026_09_30_120000_recompute_open_leg_deadlines_on_the_business_clock`.

## Helpers (`app/Helpers/`, auto-loaded via composer `files`)

`Helpers.php` (~44 functions) — `uploadOrUpdateImage($file, $dir, $existing = null)` (validates extension + 5MB cap, deletes the old file, returns the stored path, or returns `$existing` when `$file` is null), `DeleteImage()`, `getImageDashboardUrl()` (returns **raw HTML**, use `{!! !!}`), `canDo()`, `getLocalizedValue*()`, `getDefaultLanguage()`, `humanDate()`, `isoDate()`, `displayTimezone()`, `moneyFormat()`, `appCurrency()`, `phoneRegex()`, `getSettingValue()`, `realSetting()` / `isPlaceholderSetting()` (the landing page's dev-data guard), `assetVersion()`, `brandLogo()` / `brandPlaceholder()`, `webText()`, `panelIsRtl()`. **Grep before adding one** — it is large enough that duplicates get written by accident.

`LanguageHelper.php` — generates `resources/lang/{code}{,_panel,_mobile,_web}.json` from the `storage/app/{panel,mobile,web}File.php` templates.

`ApiResponse.php` — the API envelope (above).

## Caching (fragmented — check both systems)

Two overlapping caches exist:

- `CachingService` — keys from `config('constants.CACHE')` (`languages`, `settings`), 1-hour TTL. `getLanguages()` feeds the topbar language switcher via `ViewServiceProvider`'s `layouts.topbar` composer.
- `Helpers.php` — `rememberForever` on `all_languages`, `available_locales`, `default_language`, `languages_without_default`, `language_{code}`, `lang_file_{code}_{type}`, and the settings reads.

`clearLanguageCache($code)` clears the **Helpers** set only — it does **not** touch `config('constants.CACHE.LANGUAGE')`, so the topbar switcher can stay stale for up to an hour after a language change. Clear both when editing languages. Tests call `Cache::flush()` in `setUp` for this reason.

## Testing

Around eighteen hundred PHPUnit tests (1,877 on 2026-10-04, data providers
included), currently green. Real coverage exists — treat a failure as a
regression, not as a flaky stub.

- About 130 PHP test files, the bulk of them in `tests/Feature/Dashboard/`
  and `tests/Feature/Api/`, with `tests/Feature/Landing/`, `tests/Feature/Console/`
  and `tests/Unit/` behind them, plus a couple of dozen Playwright specs in
  **`tests/Browser/`** — capital B, which is what `playwright.config.js` points at
  and what a case-sensitive CI will demand.
- **The browser suite is not isolated.** It drives the real dashboard against the
  **development MySQL database** and the fixtures already in it
  (`DevFixturesSeeder`, `CatalogSeeder`, `GeoSeeder`, `TimeSlotSeeder`), so it
  reads far more than it writes and cleans up or clearly labels anything it
  creates. Keep new specs to that discipline. It runs `workers: 1` and
  `fullyParallel: false` on purpose — status toggles mutate shared rows and
  parallel workers race. The config **boots its own server** (`php artisan serve
  --port=8800`, reusing one already running), so no manual setup is needed;
  override with `APP_TEST_URL`.
- **There are no factories.** `database/factories/` holds only an unused `UserFactory`. Build rows with `Model::create()`, or better with the builders on `tests/TestCase.php`: `seedCore()`, `seedGeo()`, `seedCatalog()`, `cover()`, `addressFor()`, `grant()`, `superAdmin()`, `customer()`, `driverUser()`, `laundryWithOwner()`, `apiHeaders()`.
- **`seedCore()` is mandatory** in `setUp` — the locale helpers throw without a default language row.
- Idiom: `Tests\TestCase`, `RefreshDatabase`, `#[Test]` attributes (not `test_` prefixes), `Cache::flush()` in `setUp`, and a private `tr()` helper that json-encodes translations with `JSON_UNESCAPED_UNICODE`.
- Columns guarded against mass assignment (e.g. `redemptions_count`) need `forceFill()` after create — otherwise the test silently exercises a default row instead of the state it meant to set up.

## Docs

`docs/` is maintained by hand and drifts if you don't:

- `docs/postman/Laundo API v1.postman_collection.json` — 114 requests in 6 caller-grouped folders, one per endpoint except the eight notification/device endpoints, which both apps call and so sit in both 5.2 and 6.4, with substantive per-request descriptions. An endpoint diff will not catch a **stale request body**; check the bodies when you add a field.
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
- `docs/order-lifecycle.md` — the status table, which request produces each
  status, and the four driver legs. Written from the code, not from the design.
- `docs/order-cycle-explained.md` + `.html` + `.pdf` — the order cycle in prose,
  including the money split and the two flows that confuse people. Same
  HTML-is-the-source, PDF-rendered-from-it rule.
- `docs/mobile-api-changes.md` and `docs/api-change-notification-preferences.md`
  — running notes handed to the app teams when an endpoint's contract changed.
  Append to these rather than rewriting, and only when a mobile client is
  affected.
- **`docs/mobile-{date}-{topic}.md` is the send-as-it-is note**, one file per
  release an app team has to act on — the same content as the append to the
  running log, but standalone so it can be handed over beside the Postman
  collection without a covering explanation. **Written, handed over, then
  removed** (the owner, 2026-10-04): once the owner says it has been sent, it
  is deleted. The running log keeps its summary and git history its full text.
  The fifteen notes of 2026-09-20 → 10-01 went that way; read any of them with
  `git show b403985:docs/mobile-2026-09-20-driver-app.md` (and so on).
- `docs/driver-app-backend-answers.md` — the driver app team's `BACKEND_GAPS.md`
  answered against the code. Worth reading before building anything an app team
  reports as missing: about half that report was already shipping and its own DTO
  said it had chosen not to map it. **It carries a banner naming the answers that
  have since been overtaken** — it is a record of what was said on a date, so it
  is marked rather than rewritten, and a doc that says «read only» about an
  endpoint that now writes is worse than no doc at all. Mark the next one the
  same way rather than editing the answer under it.
- **The apps' contract as of 2026-10-04** is what those fifteen notes said, all
  of them live and sent. For the driver app:
  - the 120-second tracking window and background location;
  - the record screens, the six documents and `?audience=`;
  - **driver edits staged for approval**, the save returning the old values;
  - `expected_pieces` null until a counted leg is confirmed;
  - the order screen, with `order_id` on every task row;
  - registering the handset for push;
  - the collection waiting for the price, and `collected_amount` required on an
    unpaid delivery.

  For the customer app: coupon scope, turnaround, drawn zones, today's closed
  windows and sending `payment_method`. `docs/mobile-api-changes.md` has each
  in a few lines.
- **`docs/qc-{date}-release.html` + `.pdf` is the note for QC**, one per deploy,
  in Arabic: each change with where it is in the panel, its permission, a
  numbered «جرّب / المفروض يحصل / ✓» table and what is intended rather than a
  bug, plus the live state after the deploy and what the current apps show
  before the mobile teams update. `qc-2026-09-29-release` is the pattern. Same
  HTML-is-the-source rule; check it at phone width in a real browser (headless
  Chrome on Windows will not lay out narrower than ~500px, so its screenshots
  look cut off when nothing is).

## Known rough edges

Don't "fix" these blind, but know they're there:

- **The site runs behind Cloudflare, and `trustProxies()` is load-bearing.**
  Cloudflare terminates TLS and forwards to the origin over plain HTTP, so
  without it Laravel generates `http://` absolute URLs for an `https://` page —
  which browsers block as mixed content, taking out **every AJAX call in the
  panel** (search on all list screens, the notification bell) while leaving the
  log clean and `curl` working. It also makes `$request->ip()` Cloudflare's
  address for every visitor, which silently collapses the `otp`, `otp-verify`,
  `login` and `location` rate limiters into one shared bucket. Configured in
  `bootstrap/app.php` with `at: '*'`; `TrustedProxyTest` guards both halves.
  **`trustProxies()` is not sufficient on its own here**: Cloudflare runs in
  Flexible SSL mode, so `X-Forwarded-Proto` truthfully reports `http` for the
  edge-to-origin hop and the visitor's real scheme arrives only in `CF-Visitor`.
  `ResolveCloudflareScheme` is **prepended** to the global stack to normalise one
  into the other before `TrustProxies` reads it. Do not replace it with
  `URL::forceScheme('https')` — that fixes URL generation and leaves
  `$request->isSecure()`, cookie flags and redirects still believing the request
  is insecure.
- **Seven translatable columns are `json`, not `text`.** `cities.name`,
  `zones.name`, `services.name`, `items.name`, `item_categories.name`,
  `laundries.name` and `coupons.name` — while `banners.name`, `faqs.question`,
  `intros.title`, `journey_steps.title` and `offers.title` are `text` with
  `utf8mb4_unicode_ci` as documented above. Production runs **MariaDB**, where
  `json` is `longtext` with the binary collation **`utf8mb4_bin`**, so `LIKE`
  against it compares case-sensitively — searching `c` on Cities returned
  nothing while `C` returned Cairo. `Searchable` fixes it with
  `LOWER(CAST(col AS CHAR))` on both sides.
  `Searchable::scopeSearch()` now folds case in SQL, so the query layer is
  correct either way — **do not "simplify" it back to a bare `orWhere(...,
  'LIKE', ...)`**. `ListSearchTest` asserts the generated SQL, because the
  behaviour cannot fail on SQLite. Converting the columns would need an `ALTER`
  on seven tables holding live data; the query fix reaches the same end.
- Every module's `search()` action is wrapped in `if ($request->ajax())` and
  returns **null** otherwise, so a bare GET to `/admin/{module}/search` is an
  empty 200. Tests hitting it need `X-Requested-With: XMLHttpRequest`.
- `Banner` and `Intro` model **classes are lowercase** (`class banner`, `class intro`) — match existing usage rather than renaming casually.
- `CachingService::getSystemSettings()` plucks by a `name` column; the `settings` table has `key`. It is currently unreferenced — dead code.
- Settings are key/value rows with **PascalCase keys** (`App_Name`, `App_Logo`, `About`, `Privacy_Policy`, `Terms`, `Country_Id`, `Currency`, `Cash_Surcharge`, `Commission_Rate`); `About`/`Privacy_Policy`/`Terms` hold translatable JSON.
- **The settings screen is four tabs on one form** (عام / التواصل / المالية /
  العمليات), so a save still posts every field and a closed tab is never
  blanked. `form-validation.js`'s `reveal()` opens the tab holding the first
  refused field, and the page remembers the open tab across a save. There were
  no tabs in the panel before this — it is the pattern to copy. A switch
  (boolean) setting needs the hidden-`0` input before its checkbox, or «off» is
  never posted and never saved. `Cash_Surcharge` was validated and read at
  checkout but had no field until 2026-09-28.
- Those three hold **HTML documents, not strings**, and are printed unescaped.
  They are authored in a TinyMCE box (`setupRichText()` in
  `layouts/footer_script.blade.php`), which is used because TinyMCE 5.10.5 is
  **already loaded on every panel page** — the project's brief is to add no
  vendor bundles. Init is per-textarea because `directionality` differs per box:
  the Arabic document is authored RTL even while the panel is in English.
  `assets/js/pages/ckeditor.js` expects a `ClassicEditor` global nothing defines
  and draws nothing — don't reach for it.
- **No Arabic webfont in the panel.** `--bs-body-font-family: Nunito` has no fallback stack, and the shipped Nunito subsets are latin, latin-ext, cyrillic, cyrillic-ext and vietnamese — so every Arabic *panel* screen renders in whatever font the browser picks. The landing page self-hosts IBM Plex Sans Arabic; the panel has not been migrated.
- The **`App_Name` setting row still says `BaseCode`** while `.env` says `Laundo` — and the setting is the one the apps, the invoice and the login alt text read, via `getSettingValue('App_Name')`. `config('app.name')` is only the browser tab title. Left alone deliberately: an invoice may need a registered legal name, so it is the owner's call.
- Terms, privacy and About now hold **real legal copy**, seeded by
  `LegalContentSeeder` (registered in `DatabaseSeeder`) — no longer the draft
  placeholder text. `LegalSettingsTest` covers the editors.
- Seven images are still placeholders pending export from Figma (3 onboarding illustrations, 3 journey-step icons, 1 offer image).
- **OTP is the fixed code `123456`.** SMS delivery is not integrated (the driver
  is `LogSmsDriver`), so `OtpService` issues one static value and logs a loud
  `[OTP:STATIC-CODE — NOT RANDOM]` warning each time. Set `OTP_STATIC_CODE=` in
  the env to restore random codes once an SMS provider exists.
- **The business clock is Cairo, and it is set in two places.**
  - `config('app.display_timezone')` is `env('APP_DISPLAY_TIMEZONE',
    'Africa/Cairo')`. It had no entry in `config/app.php` until 2026-09-30, so
    what this file used to call «one env line» could not have worked.
  - The **`SetTimezone` middleware** sets it on every HTTP request from the
    `Country_Id` country's `timezone`, which on live is `Africa/Cairo`. So web
    requests already rendered in Cairo, while the console, the queue and tinker
    fell back to UTC. That is why an earlier check from tinker concluded it was
    «unset».
  - The config default now covers the processes without middleware.
  - `phpunit.xml` pins `APP_DISPLAY_TIMEZONE=UTC`, but a feature test that
    sends a request picks up the seeded country's Cairo through the middleware.
    Assert instants against `displayTimezone()`, not against a UTC wall time.
  - «Today» in the home page and the reports is still a UTC day
    (`now()->startOfDay()`, SQL `date()`). That is three hours out at the day's
    edges, and not changed yet.
- `public/storage` must be the **symlink**, not a real directory. If it is a directory, every uploaded file 404s and signed routes 403; fix with `rmdir` then `php artisan storage:link`.

## Frontend

Views are Blade under `resources/views/admin/{module}/` (with `partials/`, `forms/`, `shared/` subfolders), extending **`layouts.main`**.

**Styling is a static vendor admin template, not a build pipeline.** CSS/JS come from `public/assets/**` via `asset()` calls in `layouts/include.blade.php` and `layouts/footer_script.blade.php` — Bootstrap 5, jQuery, Font Awesome, bootstrap-icons, select2, sweetalert2, toastify, filepond, bootstrap-table, leaflet. RTL swaps to `assets/css/main/rtl.css` based on the session language. Project overrides go in `public/assets/css/theme.css` and `custom.css`; the vendor `main/app.css` often out-specifies them, so **match its selector specificity instead of relying on load order** — and before changing a property, grep for *every* rule that sets it, in both override files and the vendor CSS. More than one "fix" here has been a no-op because a second `!important` rule was still winning.

**Bootstrap's display utilities beat the `hidden` attribute.** `d-block`,
`d-flex` and the rest are `display: … !important`, so an element carrying one
and `hidden` is never hidden — the zone map's «at most 500 corners» warning was
shown on every empty map that way. Give a toggled element its own class with a
`[hidden] { display: none }` rule instead.

**Hand-edited panel assets must be cache-busted by hand.** `theme.css` and
`custom.js` have no build step and no content hash, and sit behind Cloudflare —
so a release that edits them ships Blade referring to rules the cached files do
not have (this already shipped a full-size splash image across every page).
Reference them through **`assetVersion('css/theme.css')`** — the path is
relative to `assets/`, and passing the prefix makes it look for
`assets/assets/…`, miss, and fall back to `app()->version()`, which is a
constant and busts nothing. It stamps
`filemtime()`. `landingAssetVersion()` is the same function under a narrower
name, kept because four views call it.

Vite/Tailwind are near-unused but **not dead**: `@vite` appears only in `layouts/app.blade.php` — and seven views do extend it (`auth/passwords/*`, `auth/register`, `auth/verify`, `home`, `welcome`). `Auth::routes(['register' => false])` in `routes/web.php` makes `/password/reset` reachable, so **`npm run build` is required before deploying** or that page 500s with a missing Vite manifest. It appears to work locally only because `npm run dev` leaves a gitignored `public/hot` behind. Don't route new styles through Vite unless you're deliberately migrating.

## Dashboard forms keep what you typed

`public/assets/js/custom/form-validation.js` binds to **`form.needs-validation`**
— the class every create and edit form already carried — intercepts the
submit, and posts in the background. On a 422 it paints each message beside its
field and scrolls to the first; on success it follows the controller's redirect.

**No controller was changed and none should need to be**: Laravel already
answers a request that wants JSON with `422 {message, errors}` instead of a
redirect. Before this, a failed validation was a full page load and everything
`old()` cannot carry — every file chosen, both passwords, every select2
selection — was lost.

The small action forms (approve, toggle, delete) deliberately do **not** carry
the class: there is nothing typed in them to lose, and a background submit would
only hide the page they lead to.
