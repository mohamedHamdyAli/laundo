<?php

namespace App\Modules\Loyalty\Services;

use Illuminate\Support\Carbon;

/**
 * The points programme's terms, as the owner set them on the settings screen.
 *
 * **The one reader of the `Points_*` settings.** Every figure is clamped here,
 * because the settings column is a string and a fat-fingered value must not
 * become a programme nobody agreed to: a point worth ten thousand pounds, or an
 * order earning a million points.
 *
 * The owner's figures — ten points an order, a point a pound, a point a hanger —
 * are the defaults when a setting is blank. The switch is not: the programme is
 * **off until somebody turns it on**, because the moment it is on every order
 * completed starts paying out, and that moment is the owner's to choose.
 */
final class PointsPolicy
{
    public const ENABLED = 'Points_Enabled';

    public const PER_ORDER = 'Points_Per_Order';

    public const POINT_VALUE = 'Point_Value';

    public const PER_HANGER = 'Points_Per_Hanger';

    public const MAX_HANGERS = 'Max_Hangers_Per_Visit';

    public const MIN_REDEEM = 'Points_Min_Redeem';

    public const MAX_REDEEM_PERCENT = 'Points_Max_Redeem_Percent';

    public const MIN_ORDER_TO_EARN = 'Points_Min_Order_To_Earn';

    public const EXPIRY_DAYS = 'Points_Expiry_Days';

    public const EXPIRY_WARNING_DAYS = 'Points_Expiry_Warning_Days';

    /** «مقابل كل أوردر 10 نقاط». */
    public const DEFAULT_PER_ORDER = 10;

    /** «النقطة تساوي واحد جنيه». */
    public const DEFAULT_POINT_VALUE = 1.0;

    /** «عن كل شماعة نقطة». */
    public const DEFAULT_PER_HANGER = 1;

    /**
     * How many hangers one visit may be credited for.
     *
     * Not the owner's figure — a ceiling, because the count is typed by a driver
     * standing at the door, and a slip of the thumb (or an arrangement with the
     * customer) should not be worth a hundred pounds. The owner can raise it.
     */
    public const DEFAULT_MAX_HANGERS = 50;

    public const DEFAULT_EXPIRY_WARNING_DAYS = 7;

    public function enabled(): bool
    {
        $value = getSettingValue(self::ENABLED);

        return $value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function perOrder(): int
    {
        return $this->integer(self::PER_ORDER, self::DEFAULT_PER_ORDER, 0, 10000);
    }

    /**
     * What one point takes off an order, in the app's currency.
     */
    public function pointValue(): float
    {
        $value = getSettingValue(self::POINT_VALUE);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_POINT_VALUE;
        }

        // Never zero: a point worth nothing makes every «use my points» a no-op
        // that still spends them.
        return round(min(max((float) $value, 0.01), 1000.0), 2);
    }

    public function perHanger(): int
    {
        return $this->integer(self::PER_HANGER, self::DEFAULT_PER_HANGER, 0, 1000);
    }

    public function maxHangersPerVisit(): int
    {
        return $this->integer(self::MAX_HANGERS, self::DEFAULT_MAX_HANGERS, 0, 999);
    }

    /**
     * The fewest points that may be spent at once. Zero is «any».
     */
    public function minRedeem(): int
    {
        return $this->integer(self::MIN_REDEEM, 0, 0, 1000000);
    }

    /**
     * How much of the washing, after a coupon, points may pay for.
     */
    public function maxRedeemPercent(): float
    {
        $value = getSettingValue(self::MAX_REDEEM_PERCENT);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return 100.0;
        }

        return round(min(max((float) $value, 0.0), 100.0), 2);
    }

    /**
     * The smallest washing bill that earns the per-order points. Zero is «any».
     */
    public function minOrderToEarn(): float
    {
        $value = getSettingValue(self::MIN_ORDER_TO_EARN);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return 0.0;
        }

        return round(min(max((float) $value, 0.0), 100000.0), 2);
    }

    /**
     * Days a point lives once earned, or null for «they never expire».
     */
    public function expiryDays(): ?int
    {
        $value = getSettingValue(self::EXPIRY_DAYS);

        if ($value === null || $value === '' || ! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return min((int) $value, 3650);
    }

    /**
     * How long before a lot lapses its owner is told. Zero is «never tell».
     */
    public function expiryWarningDays(): int
    {
        return $this->integer(self::EXPIRY_WARNING_DAYS, self::DEFAULT_EXPIRY_WARNING_DAYS, 0, 365);
    }

    /**
     * How far ahead an expiry is «about to happen»: the end of the business day
     * `Points_Expiry_Warning_Days` from today.
     */
    public function warningHorizon(?int $days = null): Carbon
    {
        return now()->setTimezone(displayTimezone())
            ->addDays($days ?? $this->expiryWarningDays())
            ->endOfDay()
            ->utc();
    }

    /**
     * When points earned at `$from` lapse — stamped on the lot, never re-read.
     *
     * The end of that day on the business's clock rather than to the minute: a
     * customer is told «تنتهي يوم 12 نوفمبر», and points that lapsed at ten past
     * three that afternoon would make the sentence a lie for the rest of it.
     */
    public function expiresAt(?Carbon $from = null): ?Carbon
    {
        $days = $this->expiryDays();

        if ($days === null) {
            return null;
        }

        return ($from ?? now())->copy()
            ->setTimezone(displayTimezone())
            ->addDays($days)
            ->endOfDay()
            ->utc();
    }

    /**
     * How many points to spend on a washing bill, and what they take off it.
     *
     * Pure arithmetic, shared by the quote, the checkout and the review:
     *
     *   - never more than the customer holds;
     *   - never more than `Points_Max_Redeem_Percent` of `$eligible` — the
     *     washing as it stands after any coupon — at `$value` a point;
     *   - whole points, rounded down, so the discount never overshoots the cap;
     *   - nothing at all below `Points_Min_Redeem`.
     *
     * @return array{points: int, discount: float}
     */
    public function redeemable(int $available, float $eligible, ?float $value = null, ?float $capPercent = null, ?int $minimum = null): array
    {
        $value ??= $this->pointValue();
        $capPercent ??= $this->maxRedeemPercent();
        $minimum ??= $this->minRedeem();

        if ($available < 1 || $eligible <= 0 || $value <= 0) {
            return ['points' => 0, 'discount' => 0.0];
        }

        $cap = round($eligible * $capPercent / 100, 2);
        // A hair of tolerance before flooring: 0.3 / 0.1 is 2.9999999999999996
        // in floating point, and a cap the customer can see is exactly three
        // points must not buy two.
        $byCap = (int) floor($cap / $value + 1e-9);
        $points = min($available, $byCap);

        if ($points < 1 || $points < $minimum) {
            return ['points' => 0, 'discount' => 0.0];
        }

        return ['points' => $points, 'discount' => round($points * $value, 2)];
    }

    /**
     * The terms an order is placed under, to be stamped on it.
     *
     * @return array{point_value: float, cap_percent: float, earn_min: float}
     */
    public function terms(): array
    {
        return [
            'point_value' => $this->pointValue(),
            'cap_percent' => $this->maxRedeemPercent(),
            'earn_min' => $this->minOrderToEarn(),
        ];
    }

    /**
     * Everything an app needs to explain the programme.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled(),
            'point_value' => $this->pointValue(),
            'per_order' => $this->perOrder(),
            'per_hanger' => $this->perHanger(),
            'max_hangers_per_visit' => $this->maxHangersPerVisit(),
            'min_redeem' => $this->minRedeem(),
            'max_redeem_percent' => $this->maxRedeemPercent(),
            'min_order_to_earn' => $this->minOrderToEarn(),
            'expiry_days' => $this->expiryDays(),
        ];
    }

    private function integer(string $key, int $default, int $min, int $max): int
    {
        $value = getSettingValue($key);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $default;
        }

        return (int) min(max((int) $value, $min), $max);
    }
}
