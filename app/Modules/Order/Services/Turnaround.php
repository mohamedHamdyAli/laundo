<?php

namespace App\Modules\Order\Services;

use App\Modules\Service\Models\Service;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Repositories\TimeSlotRepository;
use App\Modules\TimeSlot\Services\SlotCapacity;
use App\Modules\TimeSlot\Services\SlotClock;
use Generator;
use Illuminate\Support\Carbon;

/**
 * How soon after the pickup a service's pieces can come back.
 *
 * A service carries its turnaround as a range — «24–48 ساعة», «2–4 أيام» — and
 * for the life of the platform it was only displayed: the one rule on the dates
 * was that delivery is not before pickup, so a customer could book a four-day
 * wash to be collected and returned on the same afternoon. The owner's rules:
 *
 *   - **The middle of the range.** 2–4 days is 3, 24–48 hours is 36, a single
 *     figure is itself. A day range with a half in the middle rounds up.
 *   - **Hours are exact.** 36 hours after the pickup window *ends*, and the
 *     delivery window has to *start* at or after that moment. With no pickup
 *     window chosen the day is taken to end at midnight.
 *   - **Days are whole days.** Picked up on Monday, a 3-day service comes back
 *     from Thursday, in any of Thursday's windows.
 *   - **No turnaround set** still means «not before the pickup day».
 *   - **Not later than the booking window** — `Delivery_Window_Days` (14 by
 *     default) days after the earliest day, set from the settings screen, so the
 *     range the app is given for a new booking has two ends and the server holds
 *     both. It bounds a *new* booking only: a postponed delivery on an order
 *     placed weeks ago has its window measured from a pickup long past, and
 *     holding it to that would leave it with no day it could be rebooked for.
 *
 * Dates and window times are both plain local values, so they are compared as
 * they are written, without a timezone in between.
 */
class Turnaround
{
    /** How many days after the earliest day a delivery may still be booked. */
    public const WINDOW_SETTING = 'Delivery_Window_Days';

    public const DEFAULT_WINDOW_DAYS = 14;

    /** Why a delivery does not fit: before the service is done, or past the window. */
    public const TOO_EARLY = 'too_early';

    public const TOO_LATE = 'too_late';

    /** Today's window that has ended or ends too soon to send anybody (SlotClock). */
    public const CLOSED = 'closed';

    public function __construct(
        private readonly SlotCapacity $capacity,
        private readonly TimeSlotRepository $slots,
        private readonly SlotClock $clock,
    ) {}

    /**
     * The turnaround in the service's own unit — `{value: 3, unit: day}` or
     * `{value: 36, unit: hour}` — which is how the apps have to read it: three
     * days is «from the third day on», not «seventy-two hours from the moment
     * the pickup window ended». Null when unset.
     *
     * @return array{value: int|float, unit: string}|null
     */
    public function after(Service $service): ?array
    {
        $middle = $this->middle($service);

        if ($middle === null) {
            return null;
        }

        return $service->duration_unit === 'day'
            ? ['value' => (int) ceil($middle), 'unit' => 'day']
            : ['value' => $middle == (int) $middle ? (int) $middle : $middle, 'unit' => 'hour'];
    }

    /**
     * The earliest moment a delivery window may start, or null with no pickup
     * date to measure from. With no turnaround set it is the pickup day itself.
     */
    public function earliestDelivery(Service $service, mixed $pickupDate, ?TimeSlot $pickupSlot): ?Carbon
    {
        if (blank($pickupDate)) {
            return null;
        }

        $day = Carbon::parse($pickupDate)->startOfDay();
        $middle = $this->middle($service);

        if ($middle === null) {
            return $day;
        }

        if ($service->duration_unit === 'day') {
            return $day->addDays((int) ceil($middle));
        }

        // The end of the pickup window — or, with none chosen, midnight, so the
        // earliest delivery reads as a round hour rather than 23:59.
        $end = $pickupSlot?->end_time
            ? $day->copy()->setTimeFromTimeString((string) $pickupSlot->end_time)
            : $day->copy()->addDay();

        return $end->addMinutes((int) round($middle * 60));
    }

    /**
     * The last moment a delivery may still be booked for: the end of the day
     * `Delivery_Window_Days` after the earliest day. Null with no pickup date.
     */
    public function latestDelivery(Service $service, mixed $pickupDate, ?TimeSlot $pickupSlot): ?Carbon
    {
        $earliest = $this->earliestDelivery($service, $pickupDate, $pickupSlot);

        return $earliest?->copy()->startOfDay()->addDays($this->windowDays())->endOfDay();
    }

    /**
     * The booking window, in days after the earliest delivery day.
     */
    public function windowDays(): int
    {
        $configured = getSettingValue(self::WINDOW_SETTING);

        return is_numeric($configured) && (int) $configured >= 1
            ? min((int) $configured, 365)
            : self::DEFAULT_WINDOW_DAYS;
    }

    /**
     * What is wrong with this delivery, or null when it fits: TOO_EARLY before
     * the service can turn the pieces round, TOO_LATE past the booking window.
     *
     * A delivery day with no window chosen is judged by the day: it is early
     * only when none of the day is left after the earliest moment, because the
     * window is picked later and a later one may well fit.
     *
     * @param  bool  $withLatest  false for a rebooking, which the window does not bound
     */
    public function problem(
        Service $service,
        mixed $pickupDate,
        ?TimeSlot $pickupSlot,
        mixed $deliveryDate,
        ?TimeSlot $deliverySlot,
        bool $withLatest = true,
    ): ?string {
        $earliest = $this->earliestDelivery($service, $pickupDate, $pickupSlot);

        if ($earliest === null || blank($deliveryDate)) {
            return null;
        }

        $early = $deliverySlot === null
            ? Carbon::parse($deliveryDate)->endOfDay()->lte($earliest)
            : $this->windowStart($deliveryDate, $deliverySlot)->lt($earliest);

        if ($early) {
            return self::TOO_EARLY;
        }

        if (! $withLatest) {
            return null;
        }

        $latest = $this->latestDelivery($service, $pickupDate, $pickupSlot);

        return $latest && $this->windowStart($deliveryDate, $deliverySlot)->gt($latest) ? self::TOO_LATE : null;
    }

    /**
     * Whether this delivery fits — neither too early nor past the window.
     */
    public function allows(Service $service, mixed $pickupDate, ?TimeSlot $pickupSlot, mixed $deliveryDate, ?TimeSlot $deliverySlot): bool
    {
        return $this->problem($service, $pickupDate, $pickupSlot, $deliveryDate, $deliverySlot) === null;
    }

    /**
     * One window on one day against the range: `too_early` / `too_late`, each
     * null when that end of the range is not known. The one place the window
     * screens draw these from, so they cannot drift from problem().
     *
     * @return array{too_early: bool|null, too_late: bool|null}
     */
    public function windowFlags(?Carbon $earliest, ?Carbon $latest, mixed $date, TimeSlot $slot): array
    {
        $start = $this->windowStart($date, $slot);

        return [
            'too_early' => $earliest === null ? null : $start->lt($earliest),
            'too_late' => $latest === null ? null : $start->gt($latest),
        ];
    }

    /**
     * The message for a problem — the same words at checkout, on a reschedule
     * and in the app's bottom sheet: «الخدمة دي محتاجة 3 أيام — أبكر ميعاد تسليم
     * 2026-10-08» / «التسليم ممكن يتحجز لحد 2026-10-22».
     */
    public function message(Service $service, string $problem, mixed $pickupDate, ?TimeSlot $pickupSlot): string
    {
        if ($problem === self::CLOSED) {
            return __('This delivery window has ended or is about to. Please choose a later one.');
        }

        if ($problem === self::TOO_LATE) {
            $latest = $this->latestDelivery($service, $pickupDate, $pickupSlot);

            return __('Delivery can be booked up to :date.', ['date' => $latest?->toDateString()]);
        }

        if ($this->after($service) === null) {
            return __('Delivery cannot be earlier than pickup.');
        }

        $earliest = $this->earliestDelivery($service, $pickupDate, $pickupSlot);

        return __('This service takes :time — the earliest delivery is :date.', [
            'time' => $this->describe($service),
            'date' => $earliest ? $this->describeEarliest($service, $earliest) : '',
        ]);
    }

    /**
     * Everything `GET /delivery-window` answers, for a service and a pickup —
     * the range, whether the chosen delivery fits, a suggestion, and the day's
     * windows — so the app can hold the delivery to the service's time and fix
     * a conflict in place.
     *
     * @return array<string, mixed>
     */
    public function window(Service $service, string $pickupDate, ?TimeSlot $pickupSlot, ?string $deliveryDate, ?TimeSlot $deliverySlot): array
    {
        $earliest = $this->earliestDelivery($service, $pickupDate, $pickupSlot);
        $latest = $this->latestDelivery($service, $pickupDate, $pickupSlot);
        $problem = $deliveryDate !== null
            ? $this->problem($service, $pickupDate, $pickupSlot, $deliveryDate, $deliverySlot)
            : null;

        // Today's delivery window that has ended or is about to: the order is
        // refused for it (`WindowStillOpen`), so «valid» here would send the
        // customer on to a 422 the sheet exists to prevent.
        if ($problem === null && $deliveryDate !== null && $deliverySlot !== null
            && $this->clock->isClosed($deliveryDate, $deliverySlot)) {
            $problem = self::CLOSED;
        }

        $suggestion = $this->firstDelivery($service, $pickupDate, $pickupSlot);

        // The day the window list is about: the one the customer is looking at
        // when it is inside the range, otherwise the suggestion's.
        $inRange = $deliveryDate !== null && $earliest !== null && $latest !== null
            && Carbon::parse($deliveryDate)->endOfDay()->gt($earliest)
            && Carbon::parse($deliveryDate)->startOfDay()->lte($latest);
        $windowsDate = $inRange
            ? Carbon::parse($deliveryDate)->startOfDay()
            : ($suggestion['date'] ?? $earliest?->copy()->startOfDay());

        $after = $this->after($service);

        return [
            'turnaround' => $after ? $after + ['label' => $this->describe($service)] : null,
            'earliest' => $earliest ? [
                'date' => $earliest->toDateString(),
                // The hour, for a service measured in hours: a window has to start
                // at or after it. Null for a day service — any window that day.
                'time' => ($after['unit'] ?? null) === 'hour' ? $earliest->format('H:i') : null,
            ] : null,
            'latest' => $latest ? ['date' => $latest->toDateString()] : null,
            'chosen' => $deliveryDate === null ? null : [
                'valid' => $problem === null,
                'reason' => $problem,
                'message' => $problem ? $this->message($service, $problem, $pickupDate, $pickupSlot) : null,
            ],
            'suggestion' => $suggestion ? [
                'date' => $suggestion['date']->toDateString(),
                'time_slot' => $this->presentSlot($suggestion['slot']),
            ] : null,
            'windows_date' => $windowsDate?->toDateString(),
            'windows' => $windowsDate === null ? [] : $this->slots->activeFor('delivery')
                ->sortBy('start_time')
                ->map(function (TimeSlot $slot) use ($windowsDate, $earliest, $latest) {
                    $flags = $this->windowFlags($earliest, $latest, $windowsDate, $slot);
                    $remaining = $this->capacity->remaining($slot, $windowsDate);
                    $full = $remaining !== null && $remaining < 1;
                    $closed = $this->clock->isClosed($windowsDate, $slot);

                    return $this->presentSlot($slot) + [
                        'remaining' => $remaining,
                        'is_full' => $full,
                        'too_early' => (bool) $flags['too_early'],
                        'too_late' => (bool) $flags['too_late'],
                        // Today's window, ended or ending too soon to book.
                        'closed' => $closed,
                        // The one flag to draw from: can the customer pick it.
                        'available' => ! $flags['too_early'] && ! $flags['too_late'] && ! $full && ! $closed,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * A window as `GET /delivery-window` and `GET /time-slots` show it.
     *
     * @return array{id: int, from: string, to: string, label: string}
     */
    public function presentSlot(TimeSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'from' => substr((string) $slot->start_time, 0, 5),
            'to' => substr((string) $slot->end_time, 0, 5),
            'label' => $slot->label(),
        ];
    }

    /**
     * The first delivery window from the earliest moment on that still has room.
     *
     * @return array{date: Carbon, slot: TimeSlot}|null
     */
    public function firstDelivery(Service $service, mixed $pickupDate, ?TimeSlot $pickupSlot): ?array
    {
        foreach ($this->deliveryWindows($service, $pickupDate, $pickupSlot) as $window) {
            return $window;
        }

        return null;
    }

    /**
     * Every delivery window with room from the earliest moment to the end of
     * the booking window, in time order — so a caller whose claim on one is
     * beaten by somebody else can take the next rather than fail.
     *
     * The places already taken are counted once per capped window for the
     * whole search, not once per window per day.
     *
     * @return Generator<int, array{date: Carbon, slot: TimeSlot}>
     */
    public function deliveryWindows(Service $service, mixed $pickupDate, ?TimeSlot $pickupSlot): Generator
    {
        $earliest = $this->earliestDelivery($service, $pickupDate, $pickupSlot);

        if ($earliest === null) {
            return;
        }

        $slots = $this->slots->activeFor('delivery')->sortBy('start_time')->values();
        $from = $earliest->copy()->startOfDay();
        $to = $from->copy()->addDays($this->windowDays());

        $booked = [];

        foreach ($slots as $slot) {
            if ($slot->capacity !== null) {
                $booked[$slot->id] = $this->capacity->bookedBetween($slot, $from, $to);
            }
        }

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            foreach ($slots as $slot) {
                if ($this->windowStart($day, $slot)->lt($earliest)) {
                    continue;
                }

                // Today's window that has ended, or ends too soon to send
                // anybody, is no suggestion.
                if ($this->clock->isClosed($day, $slot)) {
                    continue;
                }

                if ($slot->capacity !== null && ($booked[$slot->id][$day->toDateString()] ?? 0) >= (int) $slot->capacity) {
                    continue;
                }

                yield ['date' => $day->copy(), 'slot' => $slot];
            }
        }
    }

    /**
     * «3 أيام» / «36 ساعة» — the turnaround the way a message says it.
     */
    public function describe(Service $service): string
    {
        $after = $this->after($service);

        if ($after === null) {
            return '';
        }

        if ($after['unit'] === 'day') {
            return $after['value'] === 1 ? __('one day') : __(':count days', ['count' => $after['value']]);
        }

        return __(':count hours', ['count' => $after['value']]);
    }

    /**
     * The earliest delivery as a message shows it: the day alone for a service
     * measured in days, the day and the hour for one measured in hours.
     */
    public function describeEarliest(Service $service, Carbon $earliest): string
    {
        return $service->duration_unit === 'day' || $this->middle($service) === null
            ? $earliest->toDateString()
            : $earliest->format('Y-m-d H:i');
    }

    public function windowStart(mixed $date, ?TimeSlot $slot): Carbon
    {
        $day = Carbon::parse($date)->startOfDay();

        return $slot?->start_time ? $day->setTimeFromTimeString((string) $slot->start_time) : $day;
    }

    private function middle(Service $service): ?float
    {
        if ($service->duration_min === null && $service->duration_max === null) {
            return null;
        }

        $min = (float) ($service->duration_min ?? $service->duration_max);
        $max = (float) ($service->duration_max ?? $service->duration_min);
        $middle = ($min + $max) / 2;

        return $middle > 0 ? $middle : null;
    }
}
