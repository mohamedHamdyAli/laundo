<?php

namespace App\Modules\Order\Services;

use App\Modules\Loyalty\Services\OrderPoints;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «تأكيد استلام من المغسلة لعدد الشماعات» — the client's sheet, item 11
 * (2026-11-08).
 *
 * The driver counts the hangers a customer hands back at the door, and until
 * now each one earned the customer points on the spot. The owner: the laundry
 * confirms the hangers that reached it, and the points are paid **after** that
 * confirmation, **on its count**.
 *
 * - The laundry the order belongs to confirms (its legs are reached through
 *   the tenant-scoped order, so another laundry's are not found); the platform
 *   may too, for a laundry with nobody on the panel. `order.update`.
 * - Once per leg, under its row lock. The count is 0 up to what one visit may
 *   be credited for (`Max_Hangers_Per_Visit`) — it may differ from the
 *   driver's, which is the point of asking.
 * - The points: `OrderPoints::earnForHangers()`, now reading the confirmed
 *   count, keyed on the leg so a replay pays nothing.
 */
class HangerReceipt
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderPoints $points,
        private readonly OrderStateMachine $machine,
    ) {}

    public function confirm(OrderTask $task, int $count, User $by): OrderTask
    {
        if (! $task->type->takesHangers() || (int) $task->hangers_returned < 1) {
            throw new RuntimeException('no_hangers');
        }

        // Up to what the driver collected, never more: the points are the
        // platform's money, and a laundry confirming 20 for the 1 a driver
        // brought would hand out 20 hangers' worth (code review, 2026-11-08).
        if ($count < 0 || $count > (int) $task->hangers_returned || $count > $this->points->hangersAllowed()) {
            throw new RuntimeException('hangers_too_many');
        }

        return DB::transaction(function () use ($task, $count, $by) {
            $locked = $this->orders->lockTask($task->id);

            if (! $locked || $locked->hangers_confirmed_at !== null) {
                throw new RuntimeException('already_confirmed');
            }

            $locked->update([
                'hangers_confirmed' => $count,
                'hangers_confirmed_at' => now(),
                'hangers_confirmed_by' => $by->id,
            ]);

            $this->machine->note(
                $locked->order,
                __('The laundry confirmed :count of :reported hangers.', ['count' => $count, 'reported' => (int) $locked->hangers_returned]),
                $by->laundry_id !== null ? 'laundry' : 'admin',
                $by
            );

            $this->points->earnForHangers($locked->refresh());

            return $locked;
        });
    }
}
