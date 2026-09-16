<?php

namespace App\Modules\Payment\Data;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The window the laundry revenue screen covers.
 *
 * A separate value object from `Report\Data\DateRange`, which every report
 * shares, and the difference is deliberate rather than duplication:
 *
 * **This one has no maximum length.** `DateRange` caps at 366 days because the
 * reports draw one bar per day and `?from=1900-01-01` hangs the page. Nothing
 * here is drawn per day — the screen is a table of laundries — and «all time» is
 * the figure somebody opening a revenue ledger most often wants. Capping it
 * would silently answer a different question.
 *
 * **It is driven by a year and a month, not only by two dates.** That is how the
 * screen is actually read — «مايو ٢٠٢٦» — and a pair of date pickers makes the
 * commonest question the most typing. Explicit dates still win when they are
 * given, so a link with `?from=&to=` in it keeps working.
 */
class RevenueWindow
{
    /**
     * The value both dropdowns use for «no restriction».
     *
     * A string, not null, so the `<select>` can carry it as an option value and
     * the choice survives a search and a page change.
     */
    public const ALL = 'all';

    /**
     * The lower bound of «all time».
     *
     * A sentinel rather than a nullable `from`, so the repository has one code
     * path and `whereBetween` is always the comparison. No row in this system
     * predates it by decades.
     */
    private const EPOCH = '1970-01-01';

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $year = self::ALL,
        public readonly string $month = self::ALL,
        public readonly bool $explicitDates = false,
    ) {}

    /**
     * Read the window off a request.
     *
     * Order of precedence: explicit `from`/`to` beat the dropdowns, a month
     * inside a year narrows to that month, a year alone is that whole year, and
     * nothing at all is everything.
     *
     * A month chosen with «all years» is ignored rather than guessed at. «March,
     * of no particular year» is not a window, and quietly reading it as March of
     * this year would put a figure on screen under a label that does not describe
     * it.
     */
    public static function fromRequest(Request $request): self
    {
        $year = self::normaliseYear($request->get('year'));
        $month = self::normaliseMonth($request->get('month'));

        if ($request->filled('from') || $request->filled('to')) {
            [$from, $to] = self::explicit($request);

            return new self($from, $to, $year, $month, true);
        }

        if ($year === self::ALL) {
            return new self(Carbon::parse(self::EPOCH)->startOfDay(), now()->endOfDay(), self::ALL, $month);
        }

        $anchor = Carbon::create((int) $year, $month === self::ALL ? 1 : (int) $month, 1);

        $from = $anchor->copy()->startOfDay();
        $to = $month === self::ALL
            ? $anchor->copy()->endOfYear()
            : $anchor->copy()->endOfMonth();

        return new self($from, $to, $year, $month);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function explicit(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->get('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse((string) $request->get('from'))->startOfDay()
            : Carbon::parse(self::EPOCH)->startOfDay();

        // Entered backwards returns nothing and reads as a quiet month. Swapping
        // is what the person meant — the same rule `DateRange` follows.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    private static function normaliseYear(mixed $value): string
    {
        $year = (string) ($value ?? self::ALL);

        if (! preg_match('/^\d{4}$/', $year)) {
            return self::ALL;
        }

        // Bounded at both ends: a four-digit string is not yet a year somebody
        // could have traded in, and `Carbon::create(0001, …)` is a date the
        // database will not compare usefully.
        $number = (int) $year;

        return ($number >= 2000 && $number <= (int) now()->year + 1) ? $year : self::ALL;
    }

    private static function normaliseMonth(mixed $value): string
    {
        $month = (string) ($value ?? self::ALL);

        if (! preg_match('/^\d{1,2}$/', $month)) {
            return self::ALL;
        }

        $number = (int) $month;

        return ($number >= 1 && $number <= 12) ? (string) $number : self::ALL;
    }

    /**
     * True when nothing narrows the window at all.
     */
    public function isAllTime(): bool
    {
        return ! $this->explicitDates
            && $this->year === self::ALL;
    }

    /**
     * The years the dropdown offers.
     *
     * Runs back to 2026, when the platform's first order could exist, and
     * forward to the current year. Derived rather than stored: a query for
     * `min(created_at)` on every page load costs more than a list of eight
     * strings, and a year with no orders in it reads as a zero, which is a true
     * answer.
     *
     * @return array<int, string>
     */
    public static function years(): array
    {
        $current = (int) now()->year;
        $years = [];

        for ($year = $current; $year >= 2026; $year--) {
            $years[] = (string) $year;
        }

        return $years;
    }

    /**
     * What the heading says this window is.
     */
    public function label(): string
    {
        if ($this->isAllTime()) {
            return __('All time');
        }

        return $this->from->toDateString().' → '.$this->to->toDateString();
    }
}
