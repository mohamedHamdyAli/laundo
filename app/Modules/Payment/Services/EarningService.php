<?php

namespace App\Modules\Payment\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Driver\Services\BonusResolver;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * «تمت إضافة أرباحك إلى الرصيد المعلق» — the immediate half of a driver's bonus.
 *
 * **A driver's salary is not in this system.** The owner's decision: it is paid
 * outside, entirely — «ملناش دعوة بيه خالص». What moves here is a bonus on top
 * of it, and only for a driver who has been put on a rule.
 *
 * What decides the amount is `DriverBonusRule`, resolved per driver by
 * `BonusResolver`. It used to be a hardcoded `DEFAULT_RATE = 0.20` behind a
 * setting with no field on the settings form and no seeder row — so every driver
 * was paid a fifth of every delivery fee at a rate nobody could see, change or
 * stop. **No rule now means no bonus**, which is the safe direction for a
 * default nobody has yet chosen.
 *
 * Bonuses are **pending** until the order completes. By then the money has
 * arrived; paying for a delivery that was later returned would have to be clawed
 * back, and clawing back from a driver who has already withdrawn is a
 * conversation nobody wants.
 *
 * The basis and rate are stored on every row alongside the result. A driver
 * asking why a job paid 12.50 has to be shown the sum, and terms that change
 * next month must not silently restate last month — the same rule as copying
 * prices onto an order.
 */
class EarningService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly BonusResolver $bonuses,
    ) {}

    /**
     * Record what a completed leg earned under this driver's rule.
     *
     * Idempotent: the unique key on `order_task_id` means a replayed completion
     * cannot pay a driver twice for one journey.
     *
     * Returns null — writing no row and touching no wallet — whenever the leg
     * earns nothing: the driver is on no rule, the rule pays only monthly, or
     * the basis is `per_order` and this is one of the three legs that is not the
     * handover to the customer. A row of zero would only make a history harder
     * to read.
     */
    public function recordFor(OrderTask $task): ?DriverEarning
    {
        if (! $task->driver_id || ! $task->order) {
            return null;
        }

        $existing = DriverEarning::where('order_task_id', $task->id)->first();

        if ($existing) {
            return $existing;
        }

        $order = $task->order;

        $share = $this->bonuses->forLeg(
            $this->bonuses->ruleFor($task->driver_id),
            $order,
            $task->type,
        );

        if ($share === null) {
            return null;
        }

        return DB::transaction(function () use ($task, $order, $share) {
            $earning = DriverEarning::create([
                'driver_id' => $task->driver_id,
                'order_id' => $order->id,
                'order_task_id' => $task->id,
                'amount' => $share['amount'],
                'basis' => $share['basis'],
                'rate' => $share['rate'],
                'status' => DriverEarning::PENDING,
            ]);

            $driver = $task->driver;

            if ($driver) {
                $this->wallets->addPending($driver, $share['amount']);
            }

            return $earning;
        });
    }

    /**
     * Make an order's pending earnings withdrawable.
     *
     * Called when the order completes, which is the point the money is certainly
     * ours to share.
     *
     * @return int how many were released
     */
    public function releaseFor(Order $order): int
    {
        $earnings = DriverEarning::where('order_id', $order->id)->pending()->get();
        $released = 0;

        foreach ($earnings as $earning) {
            $driver = Driver::find($earning->driver_id);

            if (! $driver) {
                continue;
            }

            DB::transaction(function () use ($earning, $driver, $order) {
                $earning->update(['status' => DriverEarning::RELEASED, 'released_at' => now()]);

                $this->wallets->release(
                    $driver,
                    (float) $earning->amount,
                    TransactionReason::Earning,
                    $earning,
                    __('Delivery earning for order :code', ['code' => $order->code])
                );
            });

            $released++;
        }

        return $released;
    }

    /**
     * Cancel what an abandoned order would have paid.
     */
    public function cancelFor(Order $order): int
    {
        $earnings = DriverEarning::where('order_id', $order->id)->pending()->get();

        foreach ($earnings as $earning) {
            $driver = Driver::find($earning->driver_id);

            $earning->update(['status' => DriverEarning::CANCELLED]);

            if ($driver) {
                // Taken back out of pending, where it never became spendable.
                $wallet = $this->wallets->forUser($driver);
                $wallet->update([
                    'pending_balance' => round(max((float) $wallet->pending_balance - (float) $earning->amount, 0), 2),
                ]);
            }
        }

        return $earnings->count();
    }

    /**
     * @return array{pending: float, released: float, total: float}
     */
    public function summaryFor(Driver $driver): array
    {
        $pending = (float) DriverEarning::where('driver_id', $driver->id)->pending()->sum('amount');
        $released = (float) DriverEarning::where('driver_id', $driver->id)->released()->sum('amount');

        return [
            'pending' => round($pending, 2),
            'released' => round($released, 2),
            'total' => round($pending + $released, 2),
        ];
    }
}
