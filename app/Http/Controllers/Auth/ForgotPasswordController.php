<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\Request;

/**
 * «نسيت كلمة المرور».
 *
 * Stock Laravel, with one translation in front of it: **a laundry's business
 * address resolves to its owner's account.**
 *
 * A laundry is filed with two emails and they are usually different —
 * `laundries.email` is the shop's contact address (`info@…`, printed on the
 * receipt, shown on every screen) and `users.email` is the personal address the
 * owner actually signs in with. See `LaundryApplicationService::apply()`, which
 * writes `email` and `owner_email` to two different tables.
 *
 * The owner does not experience those as two things. They know their laundry's
 * address, they type it here, and the broker answers «We can't find a user with
 * that email address» — which is literally true and reads as «you have no
 * account», which is alarming and wrong. That is what this class is for.
 *
 * The property that makes it safe: **the link goes to the owner's real address,
 * never to the one typed.** Anyone can read a public `info@` address; nobody but
 * the owner can read their own inbox. Accepting the public address as a way of
 * saying «remind me who I am» is fine precisely because the mail does not follow
 * it. `laundries.email` is `unique`, so one contact address can only ever name
 * one business — no owner can be reached through another's shop.
 *
 * Sign-in is deliberately **not** given the same translation. Accepting a
 * public address as a login *identifier* is a different decision, and
 * `LoginController` stays the one narrow door.
 *
 * Worth knowing before extending this: **customers have no email at all.** They
 * are created by phone (`TestCase::customer()` and the app's OTP flow write no
 * `email`), so this form is for dashboard accounts — admins, laundry owners and
 * their staff — and the laundry case is most of it.
 */
class ForgotPasswordController extends Controller
{
    use SendsPasswordResetEmails;

    /**
     * What the broker actually looks up.
     *
     * @return array<string, string>
     */
    protected function credentials(Request $request): array
    {
        return ['email' => $this->accountEmailFor((string) $request->input('email'))];
    }

    /**
     * The sign-in address behind whatever was typed.
     *
     * Returns the input unchanged when it is already an account, when no
     * laundry carries it, or when the laundry that does has no owner row — in
     * every one of those the broker goes on to produce its ordinary «no such
     * user», which is the right answer.
     *
     * **A real account always wins**, even when a laundry lists the same
     * address as its shop contact: the person typing their own address wants
     * their own account, not somebody else's.
     */
    private function accountEmailFor(string $typed): string
    {
        if ($typed === '' || User::where('email', $typed)->exists()) {
            return $typed;
        }

        // `withoutGlobalScopes()` because this runs for a guest: nobody is
        // signed in, so `LaundryContext::currentId()` is null and the tenant
        // scope is already inert — being explicit keeps it that way if the
        // scope ever grows a different default.
        //
        // `first()` and not a count: `laundries.email` is `unique`, so one
        // contact address names at most one business and there is no second
        // owner this could reach by mistake.
        $laundry = Laundry::withoutGlobalScopes()
            ->where('email', $typed)
            ->with('owner')
            ->first();

        // Written out rather than as `$laundry?->owner?->email ?? $typed`:
        // larastan reads the `HasOne` signature and believes `owner` is never
        // null, so it reports the second `?->` as redundant on the left of a
        // `??`. It is not — a laundry whose owner account was deleted has none,
        // and `a_laundry_with_no_owner_row_fails_rather_than_erroring` is the
        // test that would break. This shape keeps the guard and says why.
        $owner = $laundry?->owner;

        return $owner === null ? $typed : $owner->email;
    }
}
