<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * The single source of truth for "which laundry is the current actor confined to".
 *
 * Every tenant filter in the application resolves through this class, so the
 * bypass rules live in exactly one place and can be reasoned about as a whole:
 *
 *   - No authenticated actor  -> null. Console commands, seeders, queue workers
 *                                and the public API must see unfiltered data.
 *   - Super admin             -> null. Mirrors the bypass already used by
 *                                CheckPermission and MenuBuilder.
 *   - Actor with no laundry   -> null. Moderators and customers are not tenants.
 *   - Actor with a laundry    -> that laundry's id. Filtering is then mandatory.
 *
 * Returning null means "no restriction", so any change here widens or narrows
 * data access across the whole system. Treat it as security-critical.
 */
class LaundryContext
{
    /**
     * The laundry the current actor is confined to, or null for no restriction.
     */
    public static function currentId(): ?int
    {
        if (! Auth::hasUser() && ! Auth::check()) {
            return null;
        }

        $user = Auth::user();

        if (! $user) {
            return null;
        }

        if (self::isSuperAdmin()) {
            return null;
        }

        $laundryId = $user->getAttribute('laundry_id');

        return $laundryId === null ? null : (int) $laundryId;
    }

    /**
     * True when the actor is confined to a single laundry.
     */
    public static function isTenant(): bool
    {
        return self::currentId() !== null;
    }

    /**
     * Super admins are never scoped, matching CheckPermission and MenuBuilder.
     */
    public static function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->role?->slug === 'super_admin';
    }
}
