<?php

namespace App\Modules\Pricing\Services;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Pricing\Models\PriceChange;
use App\Modules\Pricing\Models\PriceChangeItem;
use App\Modules\Setting\Services\settingCrudService;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A price rise across the whole catalogue — «زيادة سنوية تنزل على كل الأسعار».
 *
 * Two ways to apply one, and they are different acts:
 *
 *   - **For a period.** Nothing in `item_prices` changes. The rate sits in the
 *     settings (`Price_Increase_Rate`, with an optional `Price_Increase_Ends_At`)
 *     and every place that reads a piece price multiplies by it. Setting it back
 *     to nothing, or reaching the end date, takes it off everywhere at once.
 *   - **For good.** The rate is written into `item_prices` — those become the
 *     official prices — and the period rate goes back to nothing, so the rise is
 *     not charged twice.
 *
 * **It is the laundry's price that rises**, before the platform's fee is folded
 * on top — the owner's decision. So a laundry is paid its share of the new
 * price, and the customer's fee is taken on the new price too, exactly as if an
 * operator had typed every new figure into the grid by hand.
 *
 * **One definition, used everywhere a piece price is read**: the quote, the
 * review, the review form, the price grid, the app catalogue and the landing
 * page. A second copy of this arithmetic anywhere is how the price on the list
 * stops matching the price on the bill — the reason `PlatformFee` is one class.
 *
 * Rounded to the piastre per piece and nothing coarser, the owner's choice: 17
 * at 10% is 18.70, so every piece rises by exactly the rate.
 */
class PriceIncrease
{
    /**
     * The rate in force right now, as a percentage — zero when none.
     *
     * An end date that has passed is zero, read at the moment of asking: no
     * scheduled job has to remember to switch it off, and a job that did not
     * run would leave customers overcharged with nothing on any screen saying
     * why.
     */
    public function rate(): float
    {
        $configured = getSettingValue('Price_Increase_Rate');

        if ($configured === null || $configured === '') {
            return 0.0;
        }

        $endsAt = $this->endsAt();

        if ($endsAt !== null && $endsAt->isPast()) {
            return 0.0;
        }

        // Clamped rather than trusted, for the reason every rate in this
        // codebase is: the settings column is a string.
        return round(max(min((float) $configured, 100.0), 0.0), 2);
    }

    /**
     * When the period rate comes off by itself, if anybody said.
     */
    public function endsAt(): ?Carbon
    {
        $configured = getSettingValue('Price_Increase_Ends_At');

        if ($configured === null || $configured === '') {
            return null;
        }

        try {
            return Carbon::parse($configured);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The laundry's price for a piece listed at `$base`, at today's rate.
     *
     * Right when a price is being quoted for the first time. An order already
     * placed must use `onBaseAt()` with its own stamped rate.
     */
    public function onBase(float $base): float
    {
        return $this->onBaseAt($base, $this->rate());
    }

    /**
     * The same, at a rate given rather than read.
     *
     * **This is the one an order in flight must use.** An order is priced twice
     * — at placement and again when the laundry counts the pieces — and the
     * review reads the matrix afresh. A period rate that ended while the bag sat
     * in the laundry must not drop the final bill below what the customer agreed
     * to, nor one that started raise it; so the rate is stamped on the order and
     * this is handed that.
     */
    public function onBaseAt(float $base, float $rate): float
    {
        // Negative only on an order re-stamped when a rise it was placed under
        // was made permanent below its own rate — see restampInFlight().
        $rate = max(min($rate, 100.0), -99.0);

        return round($base * (1 + $rate / 100), 2);
    }

    /**
     * Raise the rate for a period, or take it off (a rate of zero).
     *
     * Recorded either way: the rise that was running is closed in the history,
     * and a new one opened when there is one.
     */
    public function applyForPeriod(float $rate, ?Carbon $endsAt = null, ?User $actor = null): void
    {
        $rate = round(max(min($rate, 100.0), 0.0), 2);

        DB::transaction(function () use ($rate, $endsAt, $actor) {
            PriceChange::where('mode', PriceChange::PERIOD)
                ->whereNull('ended_at')
                ->get()
                ->each(fn (PriceChange $running) => $running->update(['ended_at' => now()]));

            if ($rate > 0) {
                PriceChange::create([
                    'rate' => $rate,
                    'mode' => PriceChange::PERIOD,
                    'ends_at' => $endsAt,
                    'applied_by' => $actor->id ?? auth()->id(),
                ]);
            }

            app(settingCrudService::class)->updateSettings([
                'Price_Increase_Rate' => $rate > 0 ? (string) $rate : '',
                'Price_Increase_Ends_At' => $rate > 0 && $endsAt ? $endsAt->toDateTimeString() : '',
            ]);
        });
    }

    /**
     * Write the rate into the catalogue for good, and clear the period rate.
     *
     * One transaction: half a catalogue raised is two price lists at once, and
     * whichever half a customer happened to order from would decide what they
     * paid. Each row is rounded here, per piece, by the same arithmetic the
     * period rate uses — so a period rise made permanent leaves every price the
     * customer sees exactly where it was.
     *
     * **Every price is recorded before and after**, so the rise can be undone
     * from the history if it was a mistake.
     *
     * @return int how many prices were raised
     */
    public function applyPermanently(float $rate, ?User $actor = null): int
    {
        $rate = round(max(min($rate, 100.0), 0.0), 2);

        if ($rate <= 0) {
            return 0;
        }

        $count = DB::transaction(function () use ($rate, $actor) {
            $change = PriceChange::create([
                'rate' => $rate,
                'mode' => PriceChange::PERMANENT,
                'applied_by' => $actor->id ?? auth()->id(),
            ]);

            $count = 0;

            ItemPrice::query()->orderBy('id')->lockForUpdate()->get()->each(function (ItemPrice $price) use ($rate, $change, &$count) {
                $before = (float) $price->price;
                $after = $this->onBaseAt($before, $rate);

                $price->update(['price' => $after]);

                PriceChangeItem::create([
                    'price_change_id' => $change->id,
                    'item_id' => $price->item_id,
                    'service_id' => $price->service_id,
                    'price_before' => $before,
                    'price_after' => $after,
                ]);

                $count++;
            });

            $change->update(['prices_count' => $count]);

            // Cleared in the same transaction: a catalogue raised by 10% with the
            // period rate still at 10% would charge every customer 21% more.
            $this->applyForPeriod(0, null, $actor);

            // …and the same for orders already placed under that period rate:
            // the review applies the stamped rate to the catalogue again, which
            // now carries the rise. 10% then 10% made permanent is 0 left to add.
            $this->restampInFlight(
                fn (float $stamped) => ((1 + $stamped / 100) / (1 + $rate / 100) - 1) * 100,
                fn ($query) => $query->whereNotNull('price_increase_rate'),
            );

            return $count;
        });

        Log::info('[pricing] catalogue raised permanently', [
            'rate' => $rate,
            'prices' => $count,
            'by' => $actor->id ?? auth()->id(),
        ]);

        return $count;
    }

    /**
     * Undo a permanent rise: put each price back to what it was before.
     *
     * **Only the latest one still standing.** Undoing an older rise while a
     * newer one stands on top of it would restore prices the newer rise then
     * moved — so the newer one has to go first, and the history says so.
     *
     * **Only prices still holding the rise's value.** A price corrected by hand
     * since is somebody's decision; restoring over it would undo their fix
     * rather than the rise. Those are counted and left.
     *
     * **Orders not yet counted keep the price they were placed at.** One placed
     * under the rise — or re-stamped when a period rise became it — agreed to
     * the raised price, so its stamp grows by the rise the catalogue just lost.
     * One placed before any of it, with no stamp, is left: it agreed to the old
     * price, which is what the catalogue goes back to. (A price corrected by
     * hand and so kept is the one case this cannot see.)
     *
     * @return array{restored: int, kept: int}
     */
    public function undo(PriceChange $change, ?User $actor = null): array
    {
        $result = DB::transaction(function () use ($change, $actor) {
            $locked = PriceChange::lockForUpdate()->findOrFail($change->id);

            if (! $locked->isPermanent() || $locked->isUndone()) {
                throw new RuntimeException('not_undoable');
            }

            $newer = PriceChange::where('mode', PriceChange::PERMANENT)
                ->whereNull('undone_at')
                ->where('id', '>', $locked->id)
                ->exists();

            if ($newer) {
                throw new RuntimeException('newer_first');
            }

            $restored = 0;
            $kept = 0;

            foreach ($locked->items()->get() as $item) {
                $price = ItemPrice::where('item_id', $item->item_id)
                    ->where('service_id', $item->service_id)
                    ->lockForUpdate()
                    ->first();

                if ($price && round((float) $price->price, 2) === round((float) $item->price_after, 2)) {
                    $price->update(['price' => $item->price_before]);
                    $restored++;
                } else {
                    // Changed by hand since, or removed: left as it is.
                    $kept++;
                }
            }

            $rate = (float) $locked->rate;

            $this->restampInFlight(
                fn (float $stamped) => ((1 + $stamped / 100) * (1 + $rate / 100) - 1) * 100,
                fn ($query) => $query->where(fn ($q) => $q
                    ->whereNotNull('price_increase_rate')
                    ->orWhere('created_at', '>=', $locked->created_at)),
            );

            $locked->update([
                'undone_at' => now(),
                'undone_by' => $actor->id ?? auth()->id(),
                'restored_count' => $restored,
            ]);

            return ['restored' => $restored, 'kept' => $kept];
        });

        Log::info('[pricing] permanent rise undone', [
            'price_change' => $change->id,
            'restored' => $result['restored'],
            'kept' => $result['kept'],
            'by' => $actor->id ?? auth()->id(),
        ]);

        return $result;
    }

    /**
     * Move the rise stamped on every order not yet counted, so the review —
     * which reads the catalogue again — still lands on the price the customer
     * agreed to after the catalogue itself moved. One model at a time, so each
     * change reaches the activity log.
     *
     * @param  \Closure(float): float  $restamp  the stamped rate in, the new one out
     * @param  \Closure(Builder<Order>): mixed  $which
     */
    private function restampInFlight(\Closure $restamp, \Closure $which): void
    {
        $query = Order::withoutGlobalScopes()
            ->whereNull('final_total')
            ->whereNotIn('status', [
                OrderStatus::Cancelled->value, OrderStatus::Returned->value, OrderStatus::Completed->value,
            ]);

        $which($query);

        $query->lockForUpdate()->get()->each(function (Order $order) use ($restamp) {
            $rate = round($restamp((float) $order->price_increase_rate), 2);

            $order->update(['price_increase_rate' => abs($rate) < 0.005 ? null : $rate]);
        });
    }

    /**
     * The most recent rises, newest first, for the history under the card.
     *
     * @return Collection<int, PriceChange>
     */
    public function history(int $limit = 20)
    {
        return PriceChange::with(['appliedBy:id,name', 'undoneBy:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The one rise the history offers to undo: the latest permanent one still
     * standing. Null when there is none.
     */
    public function undoable(): ?PriceChange
    {
        return PriceChange::where('mode', PriceChange::PERMANENT)
            ->whereNull('undone_at')
            ->latest('id')
            ->first();
    }
}
