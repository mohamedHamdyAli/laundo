<?php

namespace App\Modules\User\Services;

use App\Models\Role;
use App\Modules\Coupon\Services\ReferralService;
use App\Modules\User\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * «مرجع العميل» — the permanent number printed on a customer's bag.
 *
 * Sequential over customers alone, which is the whole point of not reusing the
 * user id: drivers and staff live in the same table, so ids skip.
 *
 * Assignment is a `created` hook on the user rather than a call at each
 * registration path, because there are four ways a customer row gets made — the
 * app, the dashboard, the seeders and the tests — and a path that forgets to ask
 * produces a bag with no reference on it, which is discovered by a person
 * holding the bag.
 */
class CustomerReference
{
    /** How many times to retry a number that somebody else took first. */
    private const ATTEMPTS = 5;

    public static function assign(User $user): void
    {
        if (! self::isCustomer($user)) {
            return;
        }

        // «رمز الدعوة» rides along with the same hook for the same reason: four
        // paths make a customer, and a customer with no code opens «ادعُ
        // أصدقاءك» on a blank screen.
        if (blank($user->referral_code)) {
            $code = ReferralService::mintCode();
            DB::table('users')->where('id', $user->getKey())->update(['referral_code' => $code]);
            $user->setAttribute('referral_code', $code);
            $user->syncOriginalAttribute('referral_code');
        }

        if (filled($user->customer_reference)) {
            return;
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $candidate = self::next();

            try {
                // A direct update, not `save()`: this runs inside the model's own
                // `created` event, and saving the model again from there
                // re-enters the observer chain.
                DB::table('users')->where('id', $user->getKey())
                    ->update(['customer_reference' => $candidate]);

                $user->setAttribute('customer_reference', $candidate);
                $user->syncOriginalAttribute('customer_reference');

                return;
            } catch (UniqueConstraintViolationException) {
                // Two registrations in the same instant read the same maximum.
                // The index is the arbiter; the loser simply counts again.
                continue;
            }
        }
    }

    /**
     * The next free number.
     *
     * Read off the highest reference rather than a row count, because a deleted
     * customer must not hand their number to somebody else — the reference is
     * printed on physical labels that outlive the account.
     */
    public static function next(): string
    {
        // `unsigned` is MySQL's spelling and `integer` is SQLite's; the app runs
        // on the first and the test suite on the second, and the wrong one is a
        // syntax error rather than a wrong answer.
        $type = DB::connection()->getDriverName() === 'sqlite' ? 'integer' : 'unsigned';

        $highest = DB::table('users')
            ->whereNotNull('customer_reference')
            ->selectRaw("max(cast(substr(customer_reference, 3) as {$type})) as n")
            ->value('n');

        return 'C-'.str_pad((string) ((int) $highest + 1), 3, '0', STR_PAD_LEFT);
    }

    private static function isCustomer(User $user): bool
    {
        $roleId = $user->role_id;

        if ($roleId === null) {
            return false;
        }

        return $roleId === self::customerRoleId();
    }

    /**
     * Looked up every time, deliberately. Memoising it across a process is what
     * makes a suite that rebuilds the database between tests assign references
     * against a role id that no longer exists.
     */
    private static function customerRoleId(): ?int
    {
        $id = Role::where('slug', Role::USER)->value('id');

        return $id === null ? null : (int) $id;
    }
}
