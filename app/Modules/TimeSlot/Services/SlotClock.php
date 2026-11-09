<?php

namespace App\Modules\TimeSlot\Services;

use App\Modules\TimeSlot\Models\TimeSlot;
use Illuminate\Support\Carbon;

/**
 * A time window as a moment in time — the one place that turns «08:00–10:00 on
 * Thursday» into an instant that can be compared with the clock.
 *
 * A window's times are the business's wall clock (Cairo), and timestamps are
 * UTC. Everything that measures a window against *now* — a leg's deadline,
 * «late», whether today's window can still be booked — goes through here, in
 * `displayTimezone()`. Until 2026-09-30 they were built in UTC: every window
 * read three hours off Cairo, and an order was taken into a window that had
 * already ended, its driver «late» the moment it was placed.
 *
 * What only compares windows with each other (`Turnaround`: the end of the
 * pickup window against the start of the delivery window) does not need this —
 * the same wall clock on both sides.
 */
class SlotClock
{
    /**
     * The booking cut-off when the setting is blank: a quarter of an hour
     * before the window **starts** (the client's sheet, item 8, 2026-11-08 —
     * «آخر وقت لعمل أوردر يكون قبل بداية كل فترة بـ 15 دقيقة»). It used to be
     * an hour before the window ended.
     */
    public const DEFAULT_CUTOFF_MINUTES = 15;

    /**
     * The instant a wall-clock time on a date means, in UTC.
     */
    public function at(mixed $date, ?string $time): ?Carbon
    {
        if (! $date) {
            return null;
        }

        $day = Carbon::parse(Carbon::parse($date)->toDateString(), displayTimezone());

        if (! $time) {
            return $day->endOfDay()->utc();
        }

        return $day->setTimeFromTimeString($time)->utc();
    }

    public function start(mixed $date, ?TimeSlot $slot): ?Carbon
    {
        return $this->at($date, $slot?->start_time);
    }

    /**
     * When the window ends. A window that ends at or before it starts
     * (22:00–01:00) ends the next day.
     */
    public function end(mixed $date, ?TimeSlot $slot): ?Carbon
    {
        $end = $this->at($date, $slot?->end_time);
        $start = $this->at($date, $slot?->start_time);

        if ($end && $start && $slot?->end_time && $end->lte($start)) {
            $end->addDay();
        }

        return $end;
    }

    /**
     * Minutes before a window's **start** after which it can no longer be
     * booked — `Slot_Booking_Cutoff_Minutes` (operations tab). Blank is the
     * default quarter hour; 0 is «until it starts».
     */
    public function cutoffMinutes(): int
    {
        $value = getSettingValue('Slot_Booking_Cutoff_Minutes');

        return is_numeric($value) ? max(0, (int) $value) : self::DEFAULT_CUTOFF_MINUTES;
    }

    /**
     * The last moment the window can be booked: the cut-off before it starts.
     *
     * Measured from the end until 2026-11-08, so a window could be booked
     * while it was already running; the client wants the driver's day known
     * before the window opens.
     */
    public function closesAt(mixed $date, TimeSlot $slot): ?Carbon
    {
        return $this->start($date, $slot)?->subMinutes($this->cutoffMinutes());
    }

    /**
     * Too late to book: the window starts too soon (or has started) —
     * 10:00–13:00 can be booked until 09:45 with the default quarter hour.
     */
    public function isClosed(mixed $date, TimeSlot $slot, ?Carbon $now = null): bool
    {
        $closes = $this->closesAt($date, $slot);

        return $closes !== null && ($now ?? now())->gte($closes);
    }
}
