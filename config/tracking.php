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

];
