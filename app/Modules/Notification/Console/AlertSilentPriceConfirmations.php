<?php

namespace App\Modules\Notification\Console;

use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The customer who never answered about the final price.
 *
 * An order at `reviewed` waits indefinitely, by decision — nothing auto-confirms,
 * because agreeing to a price on somebody's behalf is a dispute waiting to happen.
 * The consequence is that the clothes sit in a laundry, occupying machine time,
 * until a human notices.
 *
 * The owner's decision: after 24 hours, **nudge both sides and take no automatic
 * action.** The customer gets a second notification, in case the first was missed
 * or dismissed, and operations gets an alert so somebody can call.
 *
 * Sends **once per order, ever** — the notification log is the memory. An alert
 * that repeats every hour teaches people to ignore it, and then the one that
 * mattered is ignored too. That guarantee is the whole reason this command reads
 * the log before sending rather than just querying orders.
 */
class AlertSilentPriceConfirmations extends Command
{
    protected $signature = 'orders:alert-silent-confirmations
        {--hours=24 : How long a customer may stay silent before both sides are nudged}';

    protected $description = 'Nudge the customer and operations about orders waiting for a price confirmation';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $hours = max((int) $this->option('hours'), 1);
        $cutoff = now()->subHours($hours);

        // `reviewed` is the only status that waits on a customer. `updated_at` is
        // when it got there, since the state machine writes on every transition.
        $waiting = Order::withoutGlobalScopes()
            ->with(['customer:id,name,phone', 'laundry:id,name'])
            ->where('status', OrderStatus::Reviewed->value)
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($waiting->isEmpty()) {
            $this->info("No order has been waiting on a customer for more than {$hours}h.");

            return self::SUCCESS;
        }

        $operators = $this->operators();
        $nudged = 0;

        foreach ($waiting as $order) {
            if ($this->alreadyRaised($order)) {
                continue;
            }

            // The customer first. They are the one who can actually end the wait,
            // and if this send fails there is no point alerting operations about a
            // nudge that never happened — so the operator alert follows it.
            if ($order->customer !== null) {
                $dispatcher->send($order->customer, new NotificationMessage(
                    event: NotificationEvent::FinalPriceReady,
                    title: __('Your order is waiting for you'),
                    body: __('Order :code needs you to confirm the final price before we can start.', [
                        'code' => $order->code,
                    ]),
                    data: ['order_id' => (string) $order->id],
                    subject: $order,
                ));
            }

            if ($operators->isNotEmpty()) {
                $dispatcher->sendMany($operators, new NotificationMessage(
                    // A distinct event, so the log can tell a first notification
                    // from this nudge and the once-ever check has something to
                    // key on.
                    event: NotificationEvent::PriceConfirmationSilent,
                    title: __('A customer has not confirmed a price'),
                    body: __('Order :code has been waiting :hours hours. Somebody should call.', [
                        'code' => $order->code,
                        'hours' => $hours,
                    ]),
                    url: '/admin/order/show/'.$order->id,
                    data: ['order_id' => (string) $order->id],
                    subject: $order,
                ));
            }

            $nudged++;
        }

        $this->info("Raised {$nudged} of {$waiting->count()} silent order(s).");

        return self::SUCCESS;
    }

    /**
     * Whether this order has already been raised.
     *
     * The log records the subject of every message, so it is already the record of
     * what has been said about what. Adding a column to `orders` for this would be
     * a second source of the same truth.
     */
    private function alreadyRaised(Order $order): bool
    {
        return NotificationLog::where('event', NotificationEvent::PriceConfirmationSilent->value)
            ->where('subject_type', $order::class)
            ->where('subject_id', $order->getKey())
            ->exists();
    }

    /**
     * @return Collection<int, User>
     */
    private function operators(): Collection
    {
        return User::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->get();
    }
}
