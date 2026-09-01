<?php

namespace App\Modules\Payment\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * «تمت إضافة أرباحك إلى الرصيد المعلق».
 *
 * A driver earns a share of the delivery fee, split across the legs they actually
 * did. The share is a **setting**, not a constant: it is a commercial number, it
 * will be argued about, and burying it in code would mean a deploy every time it
 * moves.
 *
 * Earnings are **pending** until the order completes. By then the money has
 * arrived; paying for a delivery that was later returned would have to be clawed
 * back, and clawing back from a driver who has already withdrawn is a
 * conversation nobody wants.
 *
 * The rate is stored on every row alongside the result. A driver asking why a job
 * paid 12.50 has to be shown the sum, and a rate that changes next month must not
 * silently restate last month's earnings — the same rule as copying prices onto
 * an order.
 */
class EarningService
{
    /**
     * The fallback share when nothing is configured.
     *
     * Deliberately conservative: a wrong number that pays too little is a
     * complaint, and a wrong number that pays too much is a loss nobody notices.
     */
    private const DEFAULT_RATE = 0.20;

    public function __construct(private readonly WalletService $wallets) {}

    /**
     * The configured share of the delivery fee, as a fraction.
     */
    public function rate(): float
    {
        $setting = getSettingValue('Driver_Earning_Rate');

        if ($setting === null || $setting === '') {
            return self::DEFAULT_RATE;
        }

        // Stored as a percentage in the dashboard, because that is how anybody
        // setting it thinks about it.
        return max(0.0, min((float) $setting / 100, 1.0));
    }

    /**
     * Record what a completed leg earned.
     *
     * Idempotent: the unique key on `order_task_id` means a replayed completion
     * cannot pay a driver twice for one journey.
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
        $rate = $this->rate();

        // Split evenly across the four legs: the driver is paid for the journeys
        // they made, and one driver doing all four earns the whole share.
        $basis = round((float) $order->delivery_fee / 4, 2);
        $amount = round($basis * $rate, 2);

        if ($amount <= 0) {
            // An unpriced delivery earns nothing, and a zero row would only make
            // the driver's history harder to read.
            return null;
        }

        return DB::transaction(function () use ($task, $order, $basis, $rate, $amount) {
            $earning = DriverEarning::create([
                'driver_id' => $task->driver_id,
                'order_id' => $order->id,
                'order_task_id' => $task->id,
                'amount' => $amount,
                'basis' => $basis,
                'rate' => $rate,
                'status' => DriverEarning::PENDING,
            ]);

            $driver = $task->driver;

            if ($driver) {
                $this->wallets->addPending($driver, $amount);
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
