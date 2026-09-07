<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Modules\Setting\Models\Setting;
use App\Services\Landing\LandingContentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * The public marketing page, and the two legal pages its footer links to.
 *
 * HTTP only: locale resolution, then `LandingContentService` for the data and
 * the Web File for the words.
 *
 * ## Three URLs, on purpose
 *
 * `/` resolves the language the way the rest of the app does. `/ar` and `/en`
 * pin it. Both exist because `hreflang` needs one address per language, and a
 * session-based switch on a single `/` cannot express that — a search engine
 * would only ever index whichever language it happened to be served. So the
 * locale URLs are canonical and `/` is `x-default`.
 */
class LandingController extends Controller
{
    public function __construct(private readonly LandingContentService $content) {}

    /**
     * `/`, `/ar` and `/en`.
     *
     * Route parameters are read off the request rather than declared as
     * arguments. Laravel binds them to controller arguments **by position**
     * (`RouteDependencyResolverTrait`), not by name, and `->defaults()` appends
     * its values after the ones in the URI — so a signature that reads
     * naturally can silently receive `locale` where it expects `page`, and does
     * so on only some of the routes that share the method. Reading them
     * explicitly cannot be broken by reordering.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $locale = $request->route('locale');
        $locale = is_string($locale) ? $locale : null;

        // Unchanged from the closure this replaced, and pinned by
        // `ExampleTest::test_a_signed_in_dashboard_user_is_redirected_to_home`:
        // somebody with a session wants their panel, not the sales pitch.
        //
        // Only on `/` though. `/ar` is an explicit request for the Arabic
        // marketing page, and an operator who typed it meant it.
        if ($locale === null && Auth::check()) {
            return redirect('/admin/home');
        }

        // A two-letter code that is not a language row is a 404, not a soft
        // fallback: serving `/zz` the default language would publish the same
        // page under an unbounded set of addresses.
        abort_if($locale !== null && getLanguageByCode($locale) === null, 404);

        $language = $this->resolveLanguage($request, $locale);

        app()->setLocale($language->code);

        return view('landing.index', [
            ...$this->content->pageData(),
            ...$this->content->requestData(),
            'language' => $language,
            'isRtl' => $language->is_rtl === 'true',
            'canonical' => url('/'.$language->code),
        ]);
    }

    /**
     * «الشروط والأحكام» and «سياسة الخصوصية».
     *
     * The footer of a commercial site links to these, and until now they existed
     * only as `Terms` / `Privacy_Policy` settings rows readable through the API
     * and the panel. Nothing renders them on the web.
     *
     * The stored copy is currently marked "DRAFT — pending legal review" in the
     * value itself, so this publishes what is really there rather than dressing
     * it up. Replacing it is a settings edit, not a deploy.
     */
    public function legal(Request $request): View
    {
        // Both read off the route, for the reason `index()` documents: the
        // binding is positional, and `/terms` (no URI parameter) and
        // `/{locale}/terms` (one) would otherwise disagree about which
        // argument is which — each breaking the one the other got right.
        $page = (string) $request->route('page');
        $locale = $request->route('locale');
        $locale = is_string($locale) ? $locale : null;

        abort_unless(in_array($page, ['terms', 'privacy'], true), 404);
        abort_if($locale !== null && getLanguageByCode($locale) === null, 404);

        $language = $this->resolveLanguage($request, $locale);

        app()->setLocale($language->code);

        $key = $page === 'terms' ? 'Terms' : 'Privacy_Policy';

        return view('landing.legal', [
            'page' => $page,
            'title' => webText("landing.footer.legal_{$page}"),
            'body' => $this->localisedSetting($key),
            'language' => $language,
            'isRtl' => $language->is_rtl === 'true',
            'canonical' => url("/{$language->code}/{$page}"),
            ...$this->content->requestData(),
        ]);
    }

    /**
     * Which language this request is in.
     *
     * In order:
     *
     *   1. **The URL**, when it names one. `/ar` is not a preference, it is an
     *      address.
     *   2. **The session**, which is what the switcher writes — and what
     *      `panelLanguage()` already consulted before this controller ran.
     *   3. **`Accept-Language`**, once, and only for a guest who has no session
     *      language yet. This is the only new behaviour: the product is
     *      Cairo-facing and its content is Arabic-first, so a browser asking for
     *      Arabic should not be handed English because `en` happens to hold the
     *      `default` flag. Written to the session so the choice is stable and so
     *      the header is not re-read on every page.
     *   4. `panelLanguage()`'s answer, unchanged.
     *
     * Deliberately no GeoIP. It would mean a dependency and a data file to keep
     * current, to answer worse than the header the browser already sends.
     *
     * `panelLanguage()` itself is untouched — this narrows the "no session"
     * case for one route rather than changing how the panel resolves anything.
     */
    private function resolveLanguage(Request $request, ?string $locale): Language
    {
        if ($locale !== null && ($pinned = getLanguageByCode($locale)) !== null) {
            return $pinned;
        }

        if (! Auth::check() && ! Session::has('language')) {
            $available = Language::getAllLanguages();

            $preferred = $request->getPreferredLanguage(
                $available->pluck('code')->all()
            );

            // getPreferredLanguage() falls back to the first of the offered
            // locales when nothing matches, so this is only a real signal when
            // the header actually asked for it.
            if ($preferred !== null && $request->hasHeader('Accept-Language')) {
                $detected = $available->firstWhere('code', $preferred);

                if ($detected !== null) {
                    Session::put('language', $detected);

                    return $detected;
                }
            }
        }

        return panelLanguage() ?? Language::getAllLanguages()->first();
    }

    /**
     * One translatable settings value in the current language.
     *
     * `Setting`'s own `getTermsAttribute()` and friends imply columns called
     * `terms` / `privacy_policy`; the table is key/value rows, so those
     * accessors never fire and the decode has to happen at the read. Same
     * reasoning as `AppSettingController`, which says so in its own docblock.
     */
    private function localisedSetting(string $key): ?string
    {
        $raw = Setting::query()->where('key', $key)->value('value');

        if (blank($raw)) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            // Written before the field was translatable.
            return (string) $raw;
        }

        return pickTranslation((object) $decoded, (string) app()->getLocale(), '') ?: null;
    }
}
