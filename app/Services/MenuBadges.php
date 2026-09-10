<?php

namespace App\Services;

use App\Modules\Complaint\Models\Complaint;
use App\Modules\Driver\Models\DriverApplication;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Payment\Models\Refund;

/**
 * The counts the sidebar puts beside a menu item.
 *
 * Here rather than in `config/menu.php` because a config file has to survive
 * `config:cache`, and a closure in one does not. `MenuBuilder` asks this class
 * for a model and gets a number or nothing.
 *
 * **Only work that is waiting on a person.** Not "how many rows are in this
 * table" — a badge beside Zones saying 25 is a number nobody asked for, and
 * once every item carries one nobody reads any of them. Every count below
 * answers the same question: is there something here that nobody has replied
 * to, decided on, or assigned. That is the same question the home page's
 * «Waiting for a person» queue asks; this puts the answer where somebody sees
 * it without opening the home page first.
 *
 * **Tenant-safe by inheritance.** `Order`, `OrderTask` and the rest carry
 * `BelongsToLaundry`, so a laundry owner's badge counts their own rows and a
 * super admin's counts everything. Nothing here bypasses a global scope, and
 * anything added later must not either — a badge is a number shown to whoever
 * is looking at the menu.
 *
 * **Not cached.** These are indexed `COUNT(*)`s and there are at most six of
 * them per page, against a stale badge on a queue being worse than no badge
 * at all: it reads as "nothing waiting" to somebody who then does not look.
 */
class MenuBadges
{
    /**
     * How many rows behind this menu item are waiting on somebody.
     */
    public static function for(string $model): ?int
    {
        $count = match ($model) {
            // Leads from the public «انضم لنا» form that nobody has rung.
            'driver_application' => DriverApplication::waiting()->count(),

            // Laundries that applied through the public form and cannot sign
            // in until an operator decides.
            'laundry' => Laundry::withoutGlobalScopes()->pending()->count(),

            // Nothing times these out. They wait until a person acts:
            // an order with no laundry can go nowhere at all, and one the
            // customer has not confirmed a price on is holding machine time.
            'order' => Order::unassigned()->active()->count()
                + Order::where('status', OrderStatus::Reviewed->value)->count()
                + OrderPriceQuery::open()->whereIn('order_id', Order::query()->select('id'))->count(),

            // Journeys dispatch could not place, and ones that ran out of
            // attempts and now need a person to intervene.
            'order_task' => OrderTask::queued()->count()
                + OrderTask::where('status', 'failed')
                    ->where('attempts', '>=', OrderTask::MAX_ATTEMPTS)
                    ->count(),

            // Answered by phone, so nothing closes itself.
            'complaint' => Complaint::open()->count(),

            // Requested and undecided, plus approved and never paid — the
            // second is the worse of the two: approved is a promise.
            'refund' => Refund::pending()->count()
                + Refund::where('status', Refund::APPROVED)->whereNull('settled_at')->count(),

            default => null,
        };

        // Zero is not a badge. An empty queue should look like every other
        // finished thing on the list, not like a queue reporting itself empty.
        return $count > 0 ? $count : null;
    }
}
