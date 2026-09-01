<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Repeat schedules.
|
| Asks the day's due schedules «محتاج تغسل النهاردة؟». Deliberately mid-morning:
| the question is only useful if there is still time to collect today, and it
| should not arrive at 00:00. withoutOverlapping because a slow run must not be
| joined by the next one — though the prompt table's unique key would make that
| harmless anyway.
*/
Schedule::command('orders:prompt-recurring')
    ->dailyAt('09:00')
    ->withoutOverlapping();

/*
| The dispatch queue.
|
| A task queues when nobody was eligible when it was created. The events that
| make one dispatchable later — a driver coming on shift, becoming available, or
| dropping under their cap — none of them knows the queue exists, so something
| has to come back and look.
*/
Schedule::command('tasks:dispatch')
    ->everyTenMinutes()
    ->withoutOverlapping();

/*
| Work nobody has taken.
|
| Hourly, and never twice for the same task — the notification log is what makes
| that promise. A queue that re-announces itself teaches operations to ignore the
| alert, which is worse than sending none.
*/
Schedule::command('tasks:alert-stuck')
    ->hourly()
    ->withoutOverlapping();

/*
| Retention.
|
| Three tables grew without any bound: `notification_logs` (one row per channel
| per notification, including deliberate skips), `payments.payload` (the
| provider's own JSON, kept verbatim), and `device_tokens` (removed only when
| Firebase permanently rejects one, so a handset nobody uses stays for ever).
|
| Weekly and at 03:30, not daily: none of this is urgent, and a delete that walks
| a large table has no business competing with the morning's orders. Sunday
| because the week's quietest hour is the safest place for the longest job.
|
| The command chunks its deletes and takes --dry-run, so the retention windows can
| be read before they are trusted.
*/
Schedule::command('laundo:prune')
    ->weeklyOn(0, '03:30')
    ->withoutOverlapping();

/*
| The customer who never answered about the final price.
|
| An order at `reviewed` waits indefinitely — nothing auto-confirms, because
| agreeing to a price on somebody's behalf is a dispute waiting to happen. The
| cost is that the clothes sit in a laundry until a human notices.
|
| So: after 24 hours, nudge both sides and take no automatic action. Hourly rather
| than daily because "24 hours" checked once a day means up to 48; the command
| sends once per order ever, so running it often is free.
*/
Schedule::command('orders:alert-silent-confirmations')
    ->hourly()
    ->withoutOverlapping();

/*
| The weekly summary.
|
| «ملخص أسبوعي للسوبر أدمن + لكل مغسلة تقريرها» — the owner's decision. The four
| reports have always been on the dashboard and have always exported; what was
| missing is that somebody has to remember to open them, and the laundry owner who
| never logs in is the one whose numbers are falling.
|
| Sunday at 08:00, covering the seven days up to and including yesterday — so the
| email is about a week that is over, and never about a day still in progress. A
| laundry's figures come from the same report services run as that laundry's
| owner, so the emailed number cannot drift away from the one on the screen.
*/
Schedule::command('laundo:weekly-reports')
    ->weeklyOn(0, '08:00')
    ->withoutOverlapping();
