<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * The public language switch.
 *
 * `admin.language.set-current` already does this, and is **deliberately not
 * reused**: it sits inside the `['auth', 'dashboard.only']` group, so a guest
 * cannot reach it — and its URI, `/admin/set-language/{lang}`, is written out by
 * hand in 14 places across five Playwright specs. Moving it to make the landing
 * page work would have reddened the browser suite for a routing tidy-up.
 *
 * So this is the second door rather than a relocated one: same session key, same
 * effect, reachable without an account.
 */
class LocaleController extends Controller
{
    public function set(Request $request, string $code): RedirectResponse
    {
        $language = getLanguageByCode($code);

        if ($language === null) {
            // A code that is not a row is not an error worth a 404 on a public
            // page — the visitor came from a link, and the page they wanted
            // still exists in the language they already had.
            return redirect()->back(fallback: url('/'));
        }

        Session::put('language', $language);
        app()->setLocale($language->code);

        // Back to the localised address of wherever they were, so the URL and
        // the language on screen agree — a visitor who switches to Arabic on
        // `/en` must not be left with `/en` in the address bar. `intended`
        // carries the section anchor they were reading, if any.
        $target = $request->query('to');

        if (is_string($target) && str_starts_with($target, '/')) {
            return redirect(rtrim('/'.$language->code.$target, '/'));
        }

        return redirect('/'.$language->code);
    }
}
