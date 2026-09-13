<?php

namespace App\Support;

use App\Models\Role;
use App\Modules\User\Models\User;

/**
 * Whose wallet is «the platform's».
 *
 * Wallets are per user — `wallets.user_id` is unique and constrained — so the
 * platform's own share of an order has to land on a real account. This is the
 * one place that decides which one, for the same reason `LaundryContext` is the
 * one place that decides what a tenant may see: a rule about money that is
 * re-derived at three call sites is a rule that will eventually be derived three
 * different ways.
 *
 * **The oldest super admin wins.** Nothing in the schema stops a second super
 * admin existing, and «the newest one» would move the platform's balance to a
 * different account the day somebody is promoted — silently, and only visibly
 * once the totals stop adding up. Ordering by id is the same reasoning that
 * picks a laundry's owner out of its users.
 *
 * Returns null rather than creating anything. An install with no super admin is
 * broken in a way this class must not paper over, and inventing an account to
 * hold real money is worse than refusing to move it: the settlement stays
 * pending, which is visible on the screen, instead of being paid to a ghost.
 */
class PlatformAccount
{
    /**
     * The account the platform's share of an order is credited to.
     */
    public static function user(): ?User
    {
        return User::whereHas('role', fn ($query) => $query->where('slug', Role::SUPER_ADMIN))
            ->orderBy('id')
            ->first();
    }
}
