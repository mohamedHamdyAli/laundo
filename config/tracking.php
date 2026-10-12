<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How long a reading stays worth drawing as live
    |--------------------------------------------------------------------------
    |
    | Past this, `driver.location` is withheld and only `driver.last_seen`
    | answers. A marker left where it was reads as «السائق واقف» and sends the
    | customer to the telephone, so a stale point is removed rather than frozen.
    |
    | It is a config value rather than a constant because it has to move in step
    | with how often the driver app actually reports, and those two do not ship
    | together. **Tightening this before the apps report faster blinks the dot
    | off between reports**, which looks exactly like the bug it is meant to fix.
    | The order is: apps ship the faster cadence, then this comes down.
    |
    | At a four-second cadence, 30 is roughly seven missed reports.
    |
    */
    'fresh_for_seconds' => (int) env('TRACKING_FRESH_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | What the customer app is told to wait before asking again
    |--------------------------------------------------------------------------
    |
    | Sent back as `poll_after_seconds`, so the cadence is tuned from the box
    | rather than in an app release. A client that hardcodes its own interval
    | works, and cannot be slowed down when it needs to be.
    |
    | Four seconds with interpolation on the client reads as continuous motion.
    | It is what the large delivery apps do; a socket is a battery optimisation
    | on top of it, not what makes the marker move.
    |
    */
    'poll_seconds' => (int) env('TRACKING_POLL_SECONDS', 4),

    /*
    |--------------------------------------------------------------------------
    | How often the driver app should report
    |--------------------------------------------------------------------------
    |
    | Not enforced — the endpoint takes whatever arrives — but it is the figure
    | the documentation quotes, and keeping it here means the docs and the
    | freshness window above cannot drift apart silently.
    |
    */
    'report_seconds' => (int) env('TRACKING_REPORT_SECONDS', 4),

    /*
    |--------------------------------------------------------------------------
    | How often a driver with no live leg should report
    |--------------------------------------------------------------------------
    |
    | Every driver is tracked, not only one on a job (the owner's call,
    | 2026-10-12, for «خريطة السواقين»). Between jobs nobody is watching a
    | moving dot — only the operations map is — so the phone is asked for less.
    | Sent back as `report_after_seconds` from `POST /driver/location`.
    |
    | Keep it well inside `fresh_for_seconds` above, or an idle driver who is
    | reporting perfectly reads as «الإشارة مقطوعة» on the map between reports.
    |
    */
    'idle_report_seconds' => (int) env('TRACKING_IDLE_REPORT_SECONDS', 15),

    /*
    |--------------------------------------------------------------------------
    | How often the dashboard's driver map asks again
    |--------------------------------------------------------------------------
    |
    | One request redraws every driver, so this is the panel's cost, not the
    | phones'. Ten seconds against a four-second report: the map is for
    | deciding who goes where, not for watching a car turn a corner.
    |
    */
    'panel_poll_seconds' => (int) env('TRACKING_PANEL_POLL_SECONDS', 10),

];
