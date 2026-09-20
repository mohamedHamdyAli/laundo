<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Services\LaundryLoad;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\Order\Enums\SlotOverflowBehavior;
use App\Modules\Service\Models\Service;
use App\Services\Routing\Coordinate;
use App\Services\Routing\RouteLeg;
use App\Services\Routing\RoutingService;
use Carbon\CarbonInterface;

/**
 * Picks the laundry for an order.
 *
 * A candidate must satisfy three conditions, all of them declared by the tenant
 * itself in P2: it is active, it has claimed the pickup address's zone, and it
 * offers the requested service. That filter has not changed and is not
 * negotiable — everything below only decides between laundries that have
 * already said they can do the job.
 *
 * Among those that qualify, **the nearest by road wins, unless a nearly-as-close
 * one has more room.** Two rules, in that order:
 *
 *   1. Distance is measured through `RoutingService`. It was a straight line for
 *      the life of the project, which is the wrong answer on any map with a
 *      river in it.
 *   2. Everything within `Balance_Tolerance_Km` of the nearest forms a tie
 *      group, and inside the group the laundry with the **most free places** in
 *      the customer's pickup window takes the order. With the tolerance at 0 —
 *      the shipped default — the group is just the nearest, and this reproduces
 *      the old behaviour exactly.
 *
 * When nothing qualifies the answer is **null, and that is not a failure**. By
 * decision the order is still accepted, sits unassigned, and operations place
 * it. Refusing at the door would throw away a customer over a gap in our own
 * coverage data. A zone whose laundries are all *full* is the same shape of
 * problem, and `SlotOverflowBehavior` is where an operator says what to do
 * about it.
 *
 * Global scopes are dropped throughout: this runs while a *customer* is
 * authenticated, and a customer is not a tenant, so the scopes would be
 * inactive anyway — but a super admin placing an order on someone's behalf must
 * not silently narrow the search either.
 */
class LaundryAssigner
{
    public function __construct(
        private readonly RoutingService $routing,
        private readonly LaundryLoad $load,
    ) {}

    /**
     * The best laundry for this pickup and service, or null when none covers it.
     *
     * The window and date are what make the load half work. They are optional
     * because scheduling is optional in the API — without them every laundry
     * reads as empty and the choice falls back to pure distance, which is the
     * honest answer when nothing says when the clothes are being collected.
     */
    public function assign(
        Address $pickup,
        Service $service,
        ?int $slotId = null,
        CarbonInterface|string|null $date = null,
    ): ?Laundry {
        return $this->evaluate($pickup, $service, $slotId, $date)['chosen'];
    }

    /**
     * The whole decision, shown rather than just taken.
     *
     * `assign()` is this method throwing everything away but the answer. The
     * order screen keeps the rest: what each candidate measured, how full it
     * was, and why the one that was not chosen was not chosen — so an operator
     * overriding the choice is disagreeing with something they can see, rather
     * than with a black box.
     *
     * @return array{
     *     chosen: Laundry|null,
     *     candidates: array<int, array{
     *         laundry: Laundry,
     *         leg: RouteLeg|null,
     *         booked: int,
     *         capacity: int|null,
     *         remaining: int|null,
     *         full: bool,
     *         chosen: bool,
     *         reason: string|null
     *     }>,
     *     tolerance_km: float,
     *     overflow: SlotOverflowBehavior,
     *     all_full: bool
     * }
     */
    public function evaluate(
        Address $pickup,
        Service $service,
        ?int $slotId = null,
        CarbonInterface|string|null $date = null,
    ): array {
        $tolerance = $this->toleranceKm();
        $overflow = SlotOverflowBehavior::current();
        $candidates = $this->candidates($pickup, $service);

        if ($candidates === []) {
            return [
                'chosen' => null,
                'candidates' => [],
                'tolerance_km' => $tolerance,
                'overflow' => $overflow,
                'all_full' => false,
            ];
        }

        $legs = $this->measure($pickup, $candidates);
        $loads = $this->load->summaryFor(
            array_map(fn (Laundry $l) => $l->id, $candidates),
            $slotId,
            $date,
        );

        $rows = [];

        foreach ($candidates as $laundry) {
            $load = $loads[$laundry->id];

            $rows[] = [
                'laundry' => $laundry,
                'leg' => $legs[$laundry->id] ?? null,
                'booked' => $load['booked'],
                'capacity' => $load['capacity'],
                'remaining' => $load['remaining'],
                'full' => $load['full'],
                'chosen' => false,
                'reason' => null,
            ];
        }

        // Nearest first, throughout. An unmeasurable laundry sorts last rather
        // than being excluded — it is still a legitimate candidate, just an
        // unrankable one, and an operator can still pick it by hand.
        usort($rows, fn (array $a, array $b) => $this->km($a) <=> $this->km($b));

        $open = array_values(array_filter($rows, fn (array $r) => ! $r['full']));
        $allFull = $open === [];

        $winner = $allFull
            ? ($overflow->allowsOverflow() ? $rows[0] : null)
            : $this->pickFrom($open, $tolerance);

        $chosenId = $winner['laundry']->id ?? null;

        foreach ($rows as $i => $row) {
            $isChosen = $chosenId !== null && $row['laundry']->id === $chosenId;
            $rows[$i]['chosen'] = $isChosen;
            $rows[$i]['reason'] = $isChosen ? null : $this->whyNot($row, $winner, $tolerance);
        }

        return [
            'chosen' => $winner['laundry'] ?? null,
            'candidates' => $rows,
            'tolerance_km' => $tolerance,
            'overflow' => $overflow,
            'all_full' => $allFull,
        ];
    }

    /**
     * Every laundry that could take this order.
     *
     * @return array<int, Laundry>
     */
    public function candidates(Address $pickup, Service $service): array
    {
        if ($pickup->zone_id === null) {
            // No zone means no coverage claim can match it. The order is accepted
            // unassigned; operations will place it.
            return [];
        }

        return Laundry::withoutGlobalScopes()
            ->whereIn('id', $this->coveringLaundryIds($pickup->zone_id, $service->id))
            ->where('status', 'active')
            ->get()
            ->all();
    }

    /**
     * The laundries that have claimed this zone and, when a service is named,
     * offer it.
     *
     * Split out of `candidates()` so the slot list can ask the same question
     * without an Address and a Service in hand. One definition of «covers this
     * customer», so a window the app hides is a window the assigner would also
     * have refused.
     *
     * @return array<int, int>
     */
    public function coveringLaundryIds(int $zoneId, ?int $serviceId = null): array
    {
        $inZone = LaundryZone::withoutGlobalScopes()
            ->where('zone_id', $zoneId)
            ->pluck('laundry_id');

        if ($inZone->isEmpty()) {
            return [];
        }

        if ($serviceId === null) {
            return $inZone->unique()->values()->all();
        }

        return LaundryService::withoutGlobalScopes()
            ->where('service_id', $serviceId)
            ->where('status', 'active')
            ->whereIn('laundry_id', $inZone)
            ->pluck('laundry_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Road distance from the pickup address to each candidate, in one call.
     *
     * @param  array<int, Laundry>  $candidates
     * @return array<int, RouteLeg> keyed by laundry id; a laundry with no
     *                              coordinates is simply absent
     */
    public function measure(Address $pickup, array $candidates): array
    {
        $origin = Coordinate::from($pickup->lat, $pickup->lng);

        if ($origin === null) {
            return [];
        }

        $destinations = [];

        foreach ($candidates as $laundry) {
            $point = Coordinate::from($laundry->lat, $laundry->lng);

            if ($point !== null) {
                $destinations[$laundry->id] = $point;
            }
        }

        return $this->routing->matrix($origin, $destinations);
    }

    /**
     * The balancing rule, applied to the laundries that still have room.
     *
     * @param  array<int, array<string, mixed>>  $open  sorted nearest first
     * @return array<string, mixed>|null
     */
    private function pickFrom(array $open, float $tolerance): ?array
    {
        $nearest = $open[0] ?? null;

        if ($nearest === null) {
            return null;
        }

        if ($tolerance <= 0) {
            // Balancing switched off. Nearest wins, which is what this service
            // did before capacity existed.
            return $nearest;
        }

        $ceiling = $this->km($nearest) + $tolerance;

        // Everything close enough to the nearest that the extra driving does not
        // matter. Compared against the *nearest*, not pairwise: a chain of
        // laundries each within the tolerance of the last would otherwise let
        // the order drift arbitrarily far from the customer.
        $group = array_values(array_filter($open, fn (array $r) => $this->km($r) <= $ceiling));

        $best = $nearest;

        foreach ($group as $row) {
            if ($this->freePlaces($row) > $this->freePlaces($best)) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * Why this candidate did not get the order, in words an operator can act on.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $winner
     */
    private function whyNot(array $row, ?array $winner, float $tolerance): string
    {
        if ($row['leg'] === null) {
            return __('No coordinates — cannot be measured');
        }

        if ($row['full']) {
            return __('Full for this window');
        }

        if ($winner === null) {
            return __('Not selected');
        }

        $extra = $this->km($row) - $this->km($winner);

        if ($extra > $tolerance) {
            return __(':km km further away', ['km' => number_format($extra, 1)]);
        }

        $free = $this->freePlaces($row) === PHP_INT_MAX ? '∞' : (string) $this->freePlaces($row);

        // A negative difference means this one is actually *nearer* than the
        // laundry that won — it lost on room, inside the tolerance. Saying
        // «same distance» there reads as a bug to the operator looking at 3.7 km
        // beside 18.2 km, and the first question is then whether the panel is
        // broken rather than whether the tolerance is too wide.
        if ($extra < 0) {
            return __('Nearer, but less room (:free free)', ['free' => $free]);
        }

        return __('Same distance, less room (:free free)', ['free' => $free]);
    }

    /**
     * Sort key: road kilometres, or infinity for a laundry we cannot locate.
     *
     * @param  array<string, mixed>  $row
     */
    private function km(array $row): float
    {
        return $row['leg']->km ?? PHP_FLOAT_MAX;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function freePlaces(array $row): int
    {
        return $row['remaining'] ?? PHP_INT_MAX;
    }

    /**
     * How much further than the nearest a laundry may be and still be considered.
     *
     * Zero by default, which switches balancing off entirely. A number that
     * started at anything else would mean an install that upgraded and changed
     * nothing began routing orders somewhere new on its own.
     */
    private function toleranceKm(): float
    {
        return max(0.0, (float) getSettingValue('Balance_Tolerance_Km'));
    }
}
