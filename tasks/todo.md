# Live tracking, step two: a cheap endpoint and a fast cadence

Step one is the driver app's — reporting has to run off a live task rather than a
visible screen, and nothing on the server substitutes for it. This is the half
that makes the dot *move* once it does, and it is the half that survives a later
move to WebSockets untouched, because the payload shape does not change.

## Why not just poll `/track`

It carries the timeline, both addresses, the ETA, the steps and the driver card —
the whole screen. Asking for all of it every four seconds to move one marker is
the wrong trade. A dedicated endpoint is one query and a few dozen bytes.

## Plan

- [x] `config/tracking.php` — the freshness window and the poll interval, both
      env-tunable. The window has to stay at 120s until the apps actually report
      faster: tightening it first would blink the dot off between reports.
- [x] `DriverCard::trackingFor()` — public, and built on the **same** private gate
      the card uses. A second copy of "may this customer watch this leg" is how a
      position leaks from a leg that is none of their business.
- [x] `GET /api/v1/orders/{id}/driver-location` on `OrderController`.
- [x] The server drives the cadence: `poll_after_seconds` in the response, so the
      interval is tuned from the box rather than in an app release.
- [x] Rate limits. `location` is **30/min**, sized for a 30-second cadence; at
      4 seconds a driver sends 15/min and has almost no headroom for a retry.
      Raise it, and give the customer's polling its own limiter so it cannot eat
      the 60/min every other call shares.
- [x] Tests, docs, Postman, reference, the mobile note, Changelog.

## Shape

```
GET /api/v1/orders/42/driver-location
{
  "tracking": true,            // a leg the customer may watch is live
  "poll_after_seconds": 4,     // null when there is nothing to follow
  "location":  {...} | null,   // fresh enough to draw as live
  "last_seen": {...} | null    // last known, any age
}
```

Same two fields as the card, so the app parses one shape in both places.


## Review

Shipped. Twenty-six tests green on the tracking file, and the endpoint is in the
reference (104), the Postman collection (104) and the note for both app teams.

Two things the investigation turned up that would have bitten on the first busy
day, neither of them part of the ask:

- The `location` limiter was **30/minute** — sized when the cadence was thirty
  seconds. At four seconds a driver sends fifteen a minute, so a single retry
  storm would have started throttling the exact stream the map is drawn from.
  Raised, and the customer's polling given a bucket of its own.
- The freshness window had to stay at 120s. Tightening it in the same release
  would have been the obvious tidy-up and would have made the dot blink off
  between the app's current thirty-second reports — the bug, reintroduced while
  claiming to fix it. It is config now, so it comes down when the apps are ready,
  from the env, without a deploy.

What this does not do, and the note says so twice: make the driver app report in
the background. Until that lands there is a fast, cheap channel carrying a
position that only updates while somebody is looking at a screen.

The WebSocket question stays open and is unblocked by this rather than
foreclosed: the payload shape does not change, so moving to a socket later is a
transport swap. Self-hosting Reverb needs `Linger=no` fixed on the box, which is
one root command the owner can get; a hosted service needs none.
