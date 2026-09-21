# The customer's map, when the driver's phone goes quiet

The dot vanishes two minutes after the driver app stops reporting, and the app
reports only while the driver is sitting on its map screen. Closing the app, or
walking to the door, takes the map away from the customer — and reopening it
leaves the customer on «موقع المندوب غير متاح حاليًا» until a fresh reading lands.

The reporting gap is the app's to close. What the server can stop doing is
throwing away the last thing it knew.

## Plan

- [x] `DriverCard` — add `last_seen` beside `location`: the last stored reading
      with its age, whatever that age is.
- [x] `location` stays exactly as it is — fresh-only. Widening it would draw a
      five-minute-old dot as though it were live, which is worse than drawing
      nothing, because nobody reports a wrong answer they cannot see is wrong.
- [x] Same privacy gate as `location`: the three customer-facing legs, live.
      A position that outlives the leg is a driver being followed off the clock.
- [x] Tests: fresh, stale, off-leg, never-reported.
- [x] `docs/mobile-2026-09-21-driver-tracking.md` — the contract, the 120-second
      window and why it exists, and the background-location requirement on both
      platforms.
- [x] Postman + `generate-reference.py` + Changelog.

## The decision behind it

The server was making a display decision — «this is too old to draw» — and
answering `null`, which is indistinguishable from «never reported». The app
cannot tell those apart and so cannot say anything useful. `last_seen` hands the
client the fact and its age; `location` keeps the recommendation. The app draws a
live dot from one and a faded «آخر ظهور منذ ٣ دقائق» from the other.

Non-breaking: `location` is untouched, so the shipped app keeps working.


## Review

`last_seen` ships beside `location`, twelve tests green on the file, and the
120-second window is written down for the first time — in the API reference, the
Postman description, the running mobile log and a standalone note for both app
teams.

The judgement worth recording: the obvious fix was to widen `FRESH_FOR_SECONDS`,
and it would have looked like a fix. It would have drawn a five-minute-old
position as though it were live, which is the failure nobody reports because
nobody can see it is wrong. The problem was never the threshold — it was that the
server answered a display question (`null`) where the client needed a fact. The
threshold stays; the fact is now sent alongside it, and the client decides.

Two things I got wrong on the way here, both corrected:

- I first read the whole thing as «the driver app never reports». It does — the
  owner corrected me — but only while the driver is looking at the map screen,
  which is why it worked in every manual test and never in the field.
- I then called `liveTask()` picking `deliver_to_laundry` a bug and wrote the
  fix. Two existing tests failed, and they were right: that leg being named
  without a phone or a dot is a deliberate decision, and the card is not blank —
  it names the person holding the clothes. Reverted in full.

Still the app's to fix, and not something the server can paper over: reporting
has to run off a live task, not a visible screen. The note spells out the
foreground-service and background-permission requirements for both platforms.
