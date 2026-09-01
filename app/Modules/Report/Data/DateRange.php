<?php

namespace App\Modules\Report\Data;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The window a report covers.
 *
 * A value object rather than two loose parameters, because every report takes the
 * same pair and a swapped `from`/`to` is the sort of thing that returns zero rows
 * and looks like a quiet month.
 *
 * Both ends are inclusive: a range of "today to today" means today, not nothing.
 */
class DateRange
{
    /**
     * The longest window a report will cover.
     *
     * Not a performance tuning knob — a safety limit. The daily series carries one
     * entry per day in the range and the chart draws one bar per entry, so
     * `?from=1900-01-01` asks for 36,525 bars and hangs the page. The dates come
     * straight off a URL that is meant to be bookmarked and pasted, so a range
     * nobody would type on purpose still arrives eventually.
     *
     * A year and a day: long enough to compare a full year against the one before
     * it, short enough that the chart is still a chart.
     */
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    /**
     * Read a range off a request, defaulting to the last 30 days.
     */
    public static function fromRequest(Request $request): self
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->get('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->get('from'))->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        // A range entered backwards returns nothing and reads as a quiet month.
        // Swapping is what the person meant.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        // Keep the end the person asked for and walk the start forward, because a
        // report is almost always read backwards from a known date. The window
        // actually used is printed in the page heading and filled back into the
        // form inputs, so a clamped range is visible rather than silent.
        // Cast exactly as days() does. `from` is a start-of-day and `to` an
        // end-of-day, so diffInDays comes back a fraction under the whole number
        // and an uncast comparison clamps a range that is precisely at the limit.
        if ((int) $from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1)->startOfDay();
        }

        return new self($from, $to);
    }

    public static function lastDays(int $days): self
    {
        return new self(now()->subDays($days - 1)->startOfDay(), now()->endOfDay());
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * The same length of time, immediately before this range.
     *
     * What "up 12% on the previous period" is measured against.
     */
    public function previous(): self
    {
        $length = $this->days();

        return new self(
            $this->from->copy()->subDays($length)->startOfDay(),
            $this->from->copy()->subDay()->endOfDay(),
        );
    }

    /**
     * Every day in the range, as `Y-m-d`.
     *
     * Reports fill their gaps from this: a day with no orders has to appear as a
     * zero, or a chart draws a line straight over it and the quiet Tuesday
     * disappears.
     *
     * @return array<int, string>
     */
    public function eachDay(): array
    {
        $days = [];
        $cursor = $this->from->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $days;
    }

    public function label(): string
    {
        return $this->from->toDateString().' → '.$this->to->toDateString();
    }
}
