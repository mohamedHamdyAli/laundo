<?php

namespace App\Modules\Order\Services;

/**
 * «التعيين التلقائي» — whether the platform hands out work by itself.
 *
 * Two switches on the settings screen, each on by default, which is how the
 * platform has always run:
 *
 *   - **Laundries** (`Auto_Assign_Laundry`). Off, a new order is placed with no
 *     laundry and waits for somebody to choose one on the order's screen. The
 *     delivery fee is still worked out from the laundry that *would* have been
 *     chosen, so the customer is shown a real price; choosing a laundry by hand
 *     works it out again, as it always has.
 *   - **Drivers** (`Auto_Assign_Driver`). Off, nothing picks a driver by itself
 *     — not when an order's four legs are created, not the ten-minute sweep,
 *     not after a failed attempt or a reschedule. The legs wait on the dispatch
 *     board. An operator pressing «وزّع» is a person deciding, so those buttons
 *     still work.
 *
 * Either way the work that waits is announced in the bell to whoever can act
 * on it — see AssignmentNotifier. Blank means on: an install that never saved
 * the switch keeps behaving exactly as before.
 */
final class AutoAssign
{
    public const LAUNDRY = 'Auto_Assign_Laundry';

    public const DRIVER = 'Auto_Assign_Driver';

    public function laundries(): bool
    {
        return $this->on(self::LAUNDRY);
    }

    public function drivers(): bool
    {
        return $this->on(self::DRIVER);
    }

    private function on(string $key): bool
    {
        $value = getSettingValue($key);

        return $value === null || $value === '' || filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
