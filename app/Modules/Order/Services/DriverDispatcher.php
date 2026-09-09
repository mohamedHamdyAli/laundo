<?php

namespace App\Modules\Order\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Notification\Services\OrderNotifier;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Decides who does a task.
 *
 * A driver qualifies on five counts, each of them something a person configured
 * rather than something inferred: the account is active, the driver is available,
 * they serve the zone the task happens in, they are in the right city, and they
 * are under their concurrent-order cap.
 *
 * When nobody qualifies the task stays **pending**, which is not a failure — it
 * is the dispatch queue, and it is the same shape as an order with no covering
 * laundry in P6. Forcing a task on an ineligible driver to avoid an empty result
 * would be worse than leaving it visible.
 *
 * Capacity is counted in **orders, not tasks**: `max_concurrent_orders` is what
 * the column is called and what a dispatcher means by it. Four legs of one order
 * are one job in a driver's day, not four.
 */
class DriverDispatcher
{
    /**
     * Assign a driver if one is eligible. Returns the driver, or null when the
     * task stays queued.
     */
    public function dispatch(OrderTask $task): ?Driver
    {
        if ($task->driver_id !== null || $task->status->isFinished()) {
            return null;
        }

        $driver = $this->pick($task);

        if (! $driver) {
            return null;
        }

        $this->assignTo($task, $driver);

        return $driver;
    }

    /**
     * Hand a task to a named driver — operations overriding the automatic choice.
     *
     * The eligibility rules still apply. An override is a person choosing between
     * qualified drivers, not a way to give a task to somebody who cannot do it.
     *
     * @throws RuntimeException
     */
    public function assign(OrderTask $task, Driver $driver): OrderTask
    {
        // A *completed* leg cannot be given to anybody: the handover happened,
        // and re-running it would ask a driver to collect clothes that are no
        // longer there.
        //
        // A *failed* one can, and must. Two failures escalate a task to
        // operations, and an escalation nobody can act on is not an escalation —
        // assigning it is precisely how a person resolves it. The attempt count
        // is deliberately left alone so the history is not laundered.
        if ($task->status === TaskStatus::Completed) {
            throw new RuntimeException('task_completed');
        }

        if (! $this->isEligible($driver, $task)) {
            throw new RuntimeException('driver_not_eligible');
        }

        $this->assignTo($task, $driver);

        return $task->refresh();
    }

    /**
     * Return a task to the queue.
     *
     * Also the way an exhausted task is put back into circulation, which is why
     * it does not refuse a failed one either.
     */
    public function release(OrderTask $task): OrderTask
    {
        $task->update([
            'driver_id' => null,
            'assigned_at' => null,
            'status' => TaskStatus::Pending,
            'started_at' => null,
        ]);

        return $task->refresh();
    }

    /**
     * Every driver who could take this task, nearest concern first.
     *
     * @return array<int, Driver>
     */
    public function candidates(OrderTask $task): array
    {
        $zoneId = $this->zoneFor($task);

        if ($zoneId === null) {
            // Nothing to match a coverage claim against. The task queues.
            return [];
        }

        $drivers = Driver::with(['profile', 'zones'])
            ->where('status', 'active')
            ->whereHas('zones', fn ($q) => $q->where('zones.id', $zoneId))
            ->whereHas('profile', fn ($q) => $q->where('is_available', true))
            ->get();

        $eligible = [];

        foreach ($drivers as $driver) {
            if ($this->isEligible($driver, $task)) {
                $eligible[] = $driver;
            }
        }

        // The least loaded driver first: it spreads the day out, and it is the
        // only ordering that does not need a second configuration screen.
        usort($eligible, fn (Driver $a, Driver $b) => $this->activeOrders($a) <=> $this->activeOrders($b));

        return $eligible;
    }

    /**
     * Why nobody can take this task.
     *
     * The screen used to say «No eligible driver» and stop there. That one
     * sentence covers five unrelated situations with five different remedies —
     * no driver serves the area, they all switched themselves unavailable, the
     * city does not match, they are all at their cap, or the address never got a
     * zone — and an operator staring at an empty dropdown cannot tell which. The
     * question came in as "the drivers appear based on what?", and answering it
     * once in a message is worth more than answering it in a chat.
     *
     * So this re-runs the same five rules and reports where the candidates went.
     * `isEligible()` stays the authority: nothing here decides eligibility, it
     * only counts who fell out at which rule, in the order the rules apply.
     *
     * @return array{reason: string, params: array<string, int|string>}|null
     *                                                                       null when a driver *is* available
     */
    public function whyNobodyEligible(OrderTask $task): ?array
    {
        if ($this->candidates($task) !== []) {
            return null;
        }

        // Rule 3 needs a zone to compare against, and without one no rule can
        // even be evaluated — so this is first, and it is a data problem rather
        // than a staffing one.
        if ($this->zoneFor($task) === null) {
            return ['reason' => 'This address has no area set, so nobody can be matched to it', 'params' => []];
        }

        $all = Driver::with(['profile', 'zones'])->get();

        if ($all->isEmpty()) {
            return ['reason' => 'There are no drivers on the system yet', 'params' => []];
        }

        $zoneId = $this->zoneFor($task);
        $taskCity = $this->cityFor($task);

        $inZone = $all->filter(fn (Driver $d) => $d->zones->contains('id', $zoneId));

        if ($inZone->isEmpty()) {
            return [
                'reason' => 'No driver covers this area — :total drivers, none assigned to it',
                'params' => ['total' => $all->count()],
            ];
        }

        $active = $inZone->filter(fn (Driver $d) => $d->status === 'active');

        if ($active->isEmpty()) {
            return [
                'reason' => ':covering cover this area, but none of their accounts is active',
                'params' => ['covering' => $inZone->count()],
            ];
        }

        $available = $active->filter(fn (Driver $d) => $d->profile?->is_available);

        if ($available->isEmpty()) {
            return [
                'reason' => ':covering cover this area, but none is switched on as available',
                'params' => ['covering' => $active->count()],
            ];
        }

        $rightCity = $available->filter(
            fn (Driver $d) => $d->profile?->city_id === null
                || $taskCity === null
                || $d->profile->city_id === $taskCity
        );

        if ($rightCity->isEmpty()) {
            return [
                'reason' => ':covering cover this area, but are set to a different city',
                'params' => ['covering' => $available->count()],
            ];
        }

        // Everything else passed, so what is left is the cap — and this is the
        // one an operator can act on immediately, which is why it names the
        // numbers rather than saying "at capacity".
        // `first()` on a collection already proven non-empty, so no nullsafe on
        // the driver itself — only on the profile, which genuinely can be absent.
        $atCap = $rightCity->first();
        $cap = $atCap->profile?->max_concurrent_orders;

        if ($cap !== null) {
            return $rightCity->count() === 1
                ? [
                    'reason' => 'One driver covers this area and is already holding :held of :cap orders',
                    'params' => ['held' => $this->activeOrders($atCap), 'cap' => $cap],
                ]
                : [
                    'reason' => ':covering cover this area and every one of them is at their order limit',
                    'params' => ['covering' => $rightCity->count()],
                ];
        }

        // Should not be reachable: no rule is left to fail. Said plainly rather
        // than returning a confident wrong answer.
        return ['reason' => 'No driver is eligible, and the usual reasons do not apply', 'params' => []];
    }

    public function isEligible(Driver $driver, OrderTask $task): bool
    {
        if ($driver->status !== 'active') {
            return false;
        }

        $profile = $driver->profile;

        if (! $profile || ! $profile->is_available) {
            return false;
        }

        $zoneId = $this->zoneFor($task);

        if ($zoneId === null || ! $driver->zones->contains('id', $zoneId)) {
            return false;
        }

        // Single city, as decided in P6. Null means unconfigured, which is not
        // the same as "any city" — but refusing every driver until somebody fills
        // the field in would stop dispatch dead, so an unset city does not
        // disqualify. The dashboard now exposes the field so it can be set.
        $taskCity = $this->cityFor($task);

        if ($profile->city_id !== null && $taskCity !== null && $profile->city_id !== $taskCity) {
            return false;
        }

        $cap = $profile->max_concurrent_orders;

        if ($cap !== null && $this->activeOrders($driver) >= $cap) {
            return false;
        }

        return true;
    }

    /**
     * How many orders this driver currently has in hand.
     *
     * Distinct orders, not tasks: the cap is on jobs, and one order is one job
     * however many legs it has left.
     */
    public function activeOrders(Driver $driver): int
    {
        return (int) OrderTask::where('driver_id', $driver->id)
            ->open()
            ->distinct()
            ->count('order_id');
    }

    private function assignTo(OrderTask $task, Driver $driver): void
    {
        DB::transaction(function () use ($task, $driver) {
            $task->update([
                'driver_id' => $driver->id,
                'assigned_at' => now(),
                'status' => TaskStatus::Assigned,
            ]);
        });

        // After the transaction, not inside it: a driver told about a task that
        // then rolled back would go to a door for nothing.
        try {
            app(OrderNotifier::class)
                ->taskAssigned($task->refresh());
        } catch (\Throwable $e) {
            Log::warning('[notifications] task assignment', [
                'task' => $task->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The zone a leg happens in — where the driver has to be, not where the order
     * came from.
     */
    private function zoneFor(OrderTask $task): ?int
    {
        $order = $task->order;

        if (! $order) {
            return null;
        }

        return match ($task->type) {
            TaskType::PickupFromCustomer => $order->pickupAddress?->zone_id,
            TaskType::DeliverToCustomer => $order->deliveryAddress?->zone_id,
            // Both laundry legs happen at the laundry. It has no zone of its own,
            // so the pickup zone stands in — the laundry was chosen for covering
            // it in the first place.
            default => $order->pickupAddress?->zone_id,
        };
    }

    private function cityFor(OrderTask $task): ?int
    {
        $order = $task->order;

        if (! $order) {
            return null;
        }

        return match ($task->type) {
            TaskType::DeliverToCustomer => $order->deliveryAddress?->city_id,
            default => $order->pickupAddress?->city_id,
        };
    }

    /**
     * Sweep the queue.
     *
     * Tasks queue when nobody was eligible at the moment they were created — a
     * driver later coming on shift, becoming available, or finishing a job is
     * exactly the event that makes them dispatchable, and none of those events
     * knows about the queue. So something has to come back and look.
     *
     * @return int how many found a driver
     */
    public function sweep(?User $actor = null): int
    {
        $assigned = 0;

        OrderTask::queued()
            ->with(['order.pickupAddress', 'order.deliveryAddress'])
            ->orderBy('due_at')
            ->chunkById(100, function ($tasks) use (&$assigned) {
                foreach ($tasks as $task) {
                    // Exhausted tasks are operations' problem now, not the pool's.
                    if ($task->isExhausted()) {
                        continue;
                    }

                    if ($this->dispatch($task)) {
                        $assigned++;
                    }
                }
            });

        return $assigned;
    }

    private function pick(OrderTask $task): ?Driver
    {
        return $this->candidates($task)[0] ?? null;
    }
}
