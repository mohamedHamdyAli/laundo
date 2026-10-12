<?php

namespace App\Modules\Driver\Services;

use App\Modules\Driver\Enums\VehicleType;
use App\Modules\Driver\Models\Driver;
use App\Modules\Driver\Repositories\DriverRepository;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Repositories\LaundryRepository;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Zone\Repositories\ZoneRepository;
use App\Services\Routing\Coordinate;
use App\Services\Routing\RoutingService;
use App\Support\LaundryContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * «خريطة السواقين» — every driver, where they are, and what they are carrying.
 *
 * Built for the person routing the day: who is free, who is on a job and where
 * it is going, who has gone quiet in the middle of one, and which orders are
 * still waiting for anybody at all.
 *
 * **A platform screen, never a laundry's.** Drivers belong to Laundo and move
 * between laundries; where every one of them is standing is not a tenant's to
 * see. The route asks for `driver.view`, which no laundry role holds — and the
 * refusal is repeated here, because a permission can be granted by mistake and
 * this is the line that must not depend on that.
 *
 * **Every driver is tracked, always** (the owner's call, 2026-10-12). What the
 * map knows is what `POST /driver/location` last wrote: one point and its time.
 * A reading is `live` inside `tracking.fresh_for_seconds` and `stale` after it.
 * A driver on a job whose reading is not live is «الإشارة مقطوعة» — the case
 * somebody should pick up the phone about.
 *
 * Distances here are **straight lines**, on purpose: the map is asked every
 * few seconds, and measuring the road for every driver on every ask would be a
 * paid Google request per pair. They rank; they do not price.
 */
class DriverMap
{
    /** How many eligible drivers a waiting pin offers. */
    private const CANDIDATES = 8;

    /**
     * How close an assigned leg's window has to be before a silent phone is an
     * alarm. Legs are handed out ahead — both return legs the moment the
     * laundry marks an order ready, a pickup for tomorrow's window — and a
     * driver holding one with the app closed overnight is not «الإشارة
     * مقطوعة»; a driver who has started, or whose window is upon them, is.
     */
    private const UNDER_WAY_WITHIN_MINUTES = 60;

    public function __construct(
        private readonly DriverRepository $drivers,
        private readonly ZoneRepository $zones,
        private readonly LaundryRepository $laundries,
        private readonly DriverDispatcher $dispatcher,
        private readonly RoutingService $routing,
    ) {}

    /**
     * What the page draws once: the zones, the laundries, and what this person
     * may do from it.
     *
     * @return array<string, mixed>
     */
    public function shredData(): array
    {
        $this->refuseInsideALaundry();

        $zones = $this->zones->everyZone();

        return [
            'zones' => $zones
                ->filter(fn ($zone) => $zone->isDrawn())
                ->map(fn ($zone) => [
                    'id' => (int) $zone->id,
                    'name' => getLocalizedValueDashboard($zone, 'name'),
                    'points' => $zone->polygon()?->points(),
                    // The one definition: the zone's switch and its city's.
                    'serving' => $zone->isServing(),
                ])->values()->all(),
            'laundries' => $this->laundries->pinned()
                ->map(fn (Laundry $laundry) => [
                    'id' => (int) $laundry->id,
                    'name' => getLocalizedValueDashboard($laundry, 'name'),
                    'address' => $laundry->address,
                    'lat' => (float) $laundry->lat,
                    'lng' => (float) $laundry->lng,
                    'active' => $laundry->status === 'active',
                ])->values()->all(),
            'zoneOptions' => $zones->map(fn ($zone) => [
                'id' => (int) $zone->id,
                'name' => getLocalizedValueDashboard($zone, 'name'),
            ])->values()->all(),
            'abilities' => $this->abilities(),
            'pollSeconds' => (int) config('tracking.panel_poll_seconds', 10),
        ];
    }

    /**
     * What the map asks for every few seconds.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $this->refuseInsideALaundry();

        $fresh = $this->freshForSeconds();
        $abilities = $this->abilities();

        $drivers = $this->drivers->forMap()
            ->map(fn (Driver $driver) => $this->presentDriver($driver, $fresh, $abilities))
            ->values();

        $waiting = $abilities['waiting'] ? $this->waiting($abilities) : [];

        $counts = [
            'all' => $drivers->count(),
            'on_job' => $drivers->where('status', 'on_job')->count(),
            'available' => $drivers->where('status', 'available')->count(),
            'unavailable' => $drivers->where('status', 'unavailable')->count(),
            'inactive' => $drivers->where('status', 'inactive')->count(),
            'signal_lost' => $drivers->where('signal_lost', true)->count(),
            'late' => $drivers->where('is_late', true)->count(),
            'waiting' => count($waiting),
        ];

        return [
            'generated_at' => isoDate(now()),
            'poll_after_seconds' => (int) config('tracking.panel_poll_seconds', 10),
            'fresh_for_seconds' => $fresh,
            'counts' => $counts,
            'drivers' => $drivers->all(),
            'waiting' => $waiting,
        ];
    }

    /**
     * Who could take this waiting leg, nearest first.
     *
     * Eligibility is `DriverDispatcher::candidates()` — the same five rules the
     * assignment itself will apply — so the map never offers somebody the
     * assign button then refuses. Distance only orders them, from the driver's
     * live position to where the leg begins; a driver with no live position
     * is still offered, last, with no distance rather than a guessed one.
     *
     * @return array<string, mixed>|null null when the leg is not waiting any more
     */
    public function candidates(int $taskId): ?array
    {
        $this->refuseInsideALaundry();

        $task = $this->drivers->waitingLegForMap($taskId);

        if (! $task) {
            return null;
        }

        $order = $task->order ?? new Order;
        $start = $task->type->startCoordinatesFor($order);
        $from = $start ? new Coordinate($start['lat'], $start['lng']) : null;
        $fresh = $this->freshForSeconds();

        $rows = collect($this->dispatcher->candidates($task))
            ->map(function (Driver $driver) use ($from, $fresh) {
                $location = $this->location($driver, $fresh);
                $distance = $from && $location && $location['signal'] === 'live'
                    ? round($this->routing->straightLineKm($from, new Coordinate($location['lat'], $location['lng'])), 1)
                    : null;

                return [
                    'id' => (int) $driver->id,
                    'name' => $driver->name,
                    'distance_km' => $distance,
                    'signal' => $location['signal'] ?? 'none',
                    'driver' => $driver,
                    'cap' => $driver->profile?->max_concurrent_orders,
                ];
            })
            // Measured first, nearest first; the unmeasured after, by name.
            ->sort(function (array $a, array $b) {
                if ($a['distance_km'] === null || $b['distance_km'] === null) {
                    return ($a['distance_km'] === null) <=> ($b['distance_km'] === null)
                        ?: strcmp($a['name'], $b['name']);
                }

                return $a['distance_km'] <=> $b['distance_km'];
            })
            ->take(self::CANDIDATES)
            // The load is a query per driver: counted for the ones shown only.
            ->map(function (array $row) {
                $row['orders_in_hand'] = $this->dispatcher->activeOrders($row['driver']);
                unset($row['driver']);

                return $row;
            })
            ->values()
            ->all();

        $why = $rows === [] ? $this->dispatcher->whyNobodyEligible($task) : null;

        return [
            'task_id' => (int) $task->id,
            'candidates' => $rows,
            'nobody' => $why ? __($why['reason'], $why['params']) : null,
            'assign_url' => route('admin.order.tasks.assign', $task->id),
        ];
    }

    /**
     * @param  array<string, bool>  $abilities
     * @return array<string, mixed>
     */
    private function presentDriver(Driver $driver, int $fresh, array $abilities): array
    {
        $profile = $driver->profile;
        $legs = $driver->tasks;
        $location = $this->location($driver, $fresh);

        // On a job outranks everything else: a suspended account still holding
        // a leg is somebody carrying a customer's clothes, and that is what the
        // map has to show first. `account_active` says the rest.
        $status = match (true) {
            $legs->isNotEmpty() => 'on_job',
            ! $driver->isActive() => 'inactive',
            (bool) $profile?->is_available => 'available',
            default => 'unavailable',
        };

        $vehicle = VehicleType::parse($profile?->vehicle_type);
        $presentedLegs = $legs
            // The leg under way first — it is the one the line is drawn to.
            ->sortBy(fn (OrderTask $task) => $task->status === TaskStatus::Started ? 0 : 1)
            ->map(fn (OrderTask $task) => $this->presentLeg($task, $abilities))
            ->values()
            ->all();

        return [
            'id' => (int) $driver->id,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'photo' => getImageassetUrl($driver->image_profile),
            'plate' => $profile?->plate_number,
            'vehicle' => $vehicle ? __($vehicle->label()) : ($profile?->vehicle_type ?: null),
            'status' => $status,
            'account_active' => $driver->isActive(),
            'available' => (bool) $profile?->is_available,
            'zone_ids' => $driver->zones->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'zones' => $driver->zones->map(fn ($zone) => getLocalizedValueDashboard($zone, 'name'))->values()->all(),
            'signal' => $location['signal'] ?? 'none',
            'signal_lost' => $this->underWay($legs) && ($location['signal'] ?? 'none') !== 'live',
            'location' => $location,
            'orders_in_hand' => $legs->pluck('order_id')->unique()->count(),
            'cap' => $profile?->max_concurrent_orders,
            'is_late' => $legs->contains(fn (OrderTask $task) => $task->isLate()),
            'legs' => $presentedLegs,
            'url' => route('admin.driver.show', $driver->id),
        ];
    }

    /**
     * @param  array<string, bool>  $abilities
     * @return array<string, mixed>
     */
    private function presentLeg(OrderTask $task, array $abilities): array
    {
        $order = $task->order ?? new Order;

        // Who the customer is and where they live are the dispatch board's
        // rows (`order_task.view`), not a driver record's: a role given the
        // drivers alone sees what each one is doing, not whose door it is.
        $customerSide = $abilities['waiting'];

        return [
            'id' => (int) $task->id,
            'type' => $task->type->value,
            'type_label' => __($task->type->label()),
            'status' => $task->status->value,
            'status_label' => __($task->status->label()),
            'order_id' => (int) $task->order_id,
            'order_code' => $order->code,
            'customer' => $customerSide ? $order->customer?->name : null,
            'laundry' => $order->laundry ? getLocalizedValueDashboard($order->laundry, 'name') : null,
            'destination' => $customerSide ? $task->type->destinationFor($order) : null,
            'destination_point' => $customerSide ? $task->type->coordinatesFor($order) : null,
            'due' => $task->due_at ? $this->when($task->due_at) : null,
            'is_late' => $task->isLate(),
            'order_url' => $abilities['orders'] ? route('admin.order.show', $task->order_id) : null,
        ];
    }

    /**
     * One pin per order: its first leg nobody holds, placed where that leg
     * begins. An order whose whole chain is waiting is one place a driver has
     * to get to, not four.
     *
     * @param  array<string, bool>  $abilities
     * @return array<int, array<string, mixed>>
     */
    private function waiting(array $abilities): array
    {
        return $this->drivers->waitingLegsForMap()
            ->groupBy('order_id')
            ->map(function ($legs) use ($abilities) {
                /** @var OrderTask $task */
                $task = $legs->first();
                $order = $task->order ?? new Order;
                $start = $task->type->startCoordinatesFor($order);

                return [
                    'task_id' => (int) $task->id,
                    'order_id' => (int) $task->order_id,
                    'order_code' => $order->code,
                    'type_label' => __($task->type->label()),
                    'status' => $task->status->value,
                    'status_label' => __($task->status->label()),
                    'legs_waiting' => $legs->count(),
                    'customer' => $order->customer?->name,
                    'place' => $task->type->startsAtLaundry()
                        ? ($order->laundry ? getLocalizedValueDashboard($order->laundry, 'name') : null)
                        : $order->pickupAddress?->street,
                    'point' => $start,
                    // The zone dispatch matches the leg on — the delivery leg is
                    // filed under the delivery address, as its drivers are.
                    'zone_id' => $this->dispatcher->zoneFor($task),
                    'due' => $task->due_at ? $this->when($task->due_at) : null,
                    'is_late' => $task->isLate(),
                    'order_url' => $abilities['orders'] ? route('admin.order.show', $task->order_id) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Whether the driver should be reporting right now: a leg started, or an
     * assigned one overdue or due within the hour.
     *
     * @param  Collection<int, OrderTask>  $legs
     */
    private function underWay(Collection $legs): bool
    {
        $soon = now()->addMinutes(self::UNDER_WAY_WITHIN_MINUTES);

        return $legs->contains(fn (OrderTask $task) => $task->status === TaskStatus::Started
            || ($task->due_at !== null && $task->due_at->lte($soon)));
    }

    /**
     * The driver's last reading and how old it is.
     *
     * @return array{lat: float, lng: float, at: string|null, age_seconds: int, seen: string, signal: string}|null
     */
    private function location(Driver $driver, int $fresh): ?array
    {
        $profile = $driver->profile;

        if (! $profile?->located_at || $profile->last_lat === null || $profile->last_lng === null) {
            return null;
        }

        $age = max(0, (int) $profile->located_at->diffInSeconds(now()));

        return [
            'lat' => (float) $profile->last_lat,
            'lng' => (float) $profile->last_lng,
            'at' => isoDate($profile->located_at),
            'age_seconds' => $age,
            'seen' => humanDate($profile->located_at),
            'signal' => $age <= $fresh ? 'live' : 'stale',
        ];
    }

    /**
     * «الساعة ٣:٠٠ م» today, the date as well otherwise.
     */
    private function when(Carbon $at): string
    {
        $local = $at->copy()->setTimezone(displayTimezone());

        return humanDate($at, $local->isSameDay(now()->setTimezone(displayTimezone())) ? 'g:i A' : 'j M, g:i A');
    }

    /**
     * What this person may do from the map, decided once per request.
     *
     * @return array{waiting: bool, assign: bool, orders: bool}
     */
    private function abilities(): array
    {
        return [
            // The waiting pins are the dispatch board's rows on a map.
            'waiting' => canDo('order_task.view'),
            'assign' => canDo('order_task.update'),
            'orders' => canDo('order.view'),
        ];
    }

    private function freshForSeconds(): int
    {
        return (int) config('tracking.fresh_for_seconds', 120);
    }

    private function refuseInsideALaundry(): void
    {
        if (LaundryContext::currentId() !== null) {
            throw new HttpException(403, __('The drivers map is not available to a laundry.'));
        }
    }
}
