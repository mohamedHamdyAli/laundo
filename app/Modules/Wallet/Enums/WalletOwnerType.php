<?php

namespace App\Modules\Wallet\Enums;

use App\Models\Role;

/**
 * Who a wallet belongs to, as the operator thinks of it.
 *
 * One wallets table serves everybody — «a driver's earnings and a customer's
 * refund are the same mechanism pointed at different people» — which is right
 * for the ledger and wrong for the screen: «كام فلوس عند المغاسل» and «كام عند
 * السواقين» are different questions and the unfiltered list answers neither.
 *
 * A layer over role slugs rather than the slugs themselves, because the two do
 * not map one to one: `super_admin` and `admin` are both the platform's own
 * money, and an operator filtering for «المنصة» means both. Keeping the mapping
 * here is what stops five call sites each inventing their own list.
 */
enum WalletOwnerType: string
{
    case Customer = 'customer';
    case Driver = 'driver';
    case Laundry = 'laundry';
    case LaundryStaff = 'laundry_staff';
    case Platform = 'platform';

    /**
     * The role slugs this type covers.
     *
     * @return array<int, string>
     */
    public function roleSlugs(): array
    {
        return match ($this) {
            self::Customer => [Role::USER],
            self::Driver => [Role::DRIVER],
            self::Laundry => [Role::LAUNDRY_OWNER],
            self::LaundryStaff => ['laundry_staff'],
            // The platform's own money lives on a real user account, because
            // `wallets.user_id` is unique and constrained — see PlatformAccount.
            // Both dashboard roles count: an operator asking «المنصة» is not
            // asking which of them happens to hold it.
            self::Platform => [Role::SUPER_ADMIN, 'admin'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customers',
            self::Driver => 'Drivers',
            self::Laundry => 'Laundries',
            self::LaundryStaff => 'Laundry staff',
            self::Platform => 'Platform',
        };
    }

    /**
     * The label for a single row rather than a filter option.
     */
    public function singular(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Driver => 'Driver',
            self::Laundry => 'Laundry',
            self::LaundryStaff => 'Laundry staff',
            self::Platform => 'Platform',
        };
    }

    /**
     * The pill tone for a row badge, so the four audiences are distinguishable
     * at a glance rather than only by reading.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Customer => 'tone-live',
            self::Driver => 'tone-warn',
            self::Laundry => 'tone-ok',
            self::LaundryStaff => 'tone-neutral',
            self::Platform => 'tone-brand',
        };
    }

    /**
     * The type a role slug belongs to, or null for one nothing covers.
     *
     * Null rather than a default case: a role added later must show as unknown
     * on the screen instead of being quietly filed under Customers, which is
     * how a moderator's wallet would end up in the customer totals.
     */
    public static function forRoleSlug(?string $slug): ?self
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if (in_array($slug, $case->roleSlugs(), true)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
