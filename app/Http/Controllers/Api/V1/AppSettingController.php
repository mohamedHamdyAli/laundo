<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Setting\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The settings an app is allowed to read.
 *
 * The `settings` table mixes app content with operational configuration, so this
 * ships an explicit allow-list rather than the table. A `Setting::all()` endpoint
 * would leak whatever anybody adds next, and nobody reviews an endpoint that was
 * already there.
 *
 * Excluded, and why:
 *   Country_Id   — an internal foreign key; the app has no use for a row id
 *   Tax          — a rate the server applies to totals; a client that reads it
 *                  is a client that can be argued with about the maths
 *   Login_Cover  — dashboard chrome, not app content
 *
 * `Driver_Hotline`, `Driver_Call`, `Driver_Email` and `Driver_Whats_App` are not
 * in the allow-list because they are never served under their own names: they
 * replace the four public ones when `?audience=driver` asks, so the driver app
 * reads `hotline` like everybody else and gets the right number.
 *
 * Note the table is key/value rows. `Setting`'s getAboutAttribute() and friends
 * imply columns named about/privacy_policy/terms; there are none, so those
 * accessors never fire and the decode has to happen here.
 */
class AppSettingController extends Controller
{
    /**
     * Keys the apps may read, and whether the value holds translated JSON.
     *
     * @var array<string, bool>
     */
    private const PUBLIC_KEYS = [
        'App_Name' => false,
        // Resolved through brandLogo() below rather than shipped as a raw path.
        'App_Logo' => false,

        // Translatable: {"en":"…","ar":"…"}, written by the dashboard's rich-text
        // editors, so these are HTML and the client must render them as such.
        'About' => true,
        'Privacy_Policy' => true,
        'Terms' => true,

        // «تواصل معنا» reads these.
        // The apps format their own prices; without this they would each have
        // to guess, and a guess that disagrees with the panel is a price the
        // customer sees two ways.
        'Currency' => false,

        'Hotline' => false,
        'Call' => false,
        'Email' => false,
        'Whats_App' => false,

        'Facebook_Url' => false,
        'Twitter_Url' => false,
        'Instagram_Url' => false,
        'Linkedin_Url' => false,
        'Youtube_Url' => false,
        'Snapchat_Url' => false,
        'Gmail_Url' => false,
    ];

    /** The three keys that have a screen of their own, by their public slug. */
    private const PAGES = [
        'about' => 'About',
        'privacy' => 'Privacy_Policy',
        'terms' => 'Terms',
    ];

    /**
     * Everything the account screen needs in one call.
     *
     * The long-form pages are deliberately NOT included here — About, Privacy and
     * Terms are each a wall of HTML, and shipping all three on every app launch
     * to populate a settings menu wastes the customer's data. `pages` names them
     * so a client can fetch one when it is opened.
     */
    public function index(Request $request): JsonResponse
    {
        $stored = $this->stored();
        $audience = $request->get('audience');

        $payload = [];

        foreach (self::PUBLIC_KEYS as $key => $translatable) {
            if ($translatable) {
                continue;
            }

            if ($key === 'App_Logo') {
                // Through brandLogo(), not the raw setting: an unset App_Logo
                // used to resolve to the generic missing-image placeholder, so
                // the apps were being handed that as the brand.
                $payload['app_logo'] = brandLogo('dark');

                continue;
            }

            if ($key === 'Currency') {
                // Through appCurrency() for the same reason as the logo above:
                // it is the resolved value, not the row. An unset or half-typed
                // setting hands the raw column straight to the apps — so they
                // would format prices with an empty string while every screen
                // in the panel showed EGP, which is precisely the disagreement
                // the setting exists to prevent.
                $payload['currency'] = appCurrency();

                continue;
            }

            // A key absent from the table reads as null rather than throwing. An
            // install that has not filled in its WhatsApp number is normal.
            $payload[$this->slug($key)] = $this->contact($key, $stored, $audience);
        }

        // The white mark, for anywhere the app draws on a dark surface. Shipped
        // because a client cannot derive one from the other.
        $payload['app_logo_light'] = brandLogo('light');

        $payload['pages'] = array_keys(self::PAGES);

        return successReturnData($payload);
    }

    /**
     * The four contact keys a driver may be given their own numbers for.
     *
     * @var array<int, string>
     */
    private const DRIVER_OVERRIDES = ['Hotline', 'Call', 'Email', 'Whats_App'];

    /**
     * A contact value, with the driver's own number when there is one.
     *
     * `?audience=driver` is what the driver app sends, and the fallback is the
     * point of the design rather than a convenience: a courier stranded at a
     * doorstep needs to reach somebody, and an install that has only filled in
     * one set of numbers must not answer him with null. So a blank driver line
     * reads as «no separate number», not as «no number».
     *
     * Only these four are overridable. The social links and the brand are the
     * company's, not an audience's.
     *
     * @param  array<string, string|null>  $stored
     */
    private function contact(string $key, array $stored, ?string $audience): ?string
    {
        if ($audience === 'driver' && in_array($key, self::DRIVER_OVERRIDES, true)) {
            $override = $stored['Driver_'.$key] ?? null;

            if (filled($override)) {
                return $override;
            }
        }

        return $stored[$key] ?? null;
    }

    /**
     * One long-form page: about, privacy or terms.
     */
    public function page(string $page): JsonResponse
    {
        $key = self::PAGES[strtolower($page)] ?? null;

        if ($key === null) {
            return failReturnNotFound(__('That page does not exist.'));
        }

        $raw = $this->stored()[$key] ?? null;

        return successReturnData([
            'slug' => strtolower($page),
            // Translated JSON, decoded here because the model's accessors are for
            // columns that do not exist on this key/value table.
            'body' => $this->localised($raw),
        ]);
    }

    /**
     * The settings table as a key => value map.
     *
     * Cached, and on the same key as the rest of the settings cache, so editing a
     * setting in the dashboard does not leave the apps on a stale copy while the
     * dashboard shows the new one.
     */
    private function stored(): array
    {
        /** @var array<string, string|null> $map */
        $map = Cache::remember(
            (string) config('constants.CACHE.SETTINGS', 'settings'),
            3600,
            fn () => Setting::pluck('value', 'key')->all()
        );

        return $map;
    }

    /**
     * Pull the request locale out of a translated JSON value.
     *
     * Falls back to the default language and then to any value present, because a
     * page that exists in Arabic only should still render for an English client
     * rather than showing a blank screen.
     */
    private function localised(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            // Written before the field was translatable.
            return $raw;
        }

        $locale = app()->getLocale();
        $default = (string) getDefaultLanguage('code');

        foreach ([$locale, $default] as $candidate) {
            if (filled($decoded[$candidate] ?? null)) {
                return (string) $decoded[$candidate];
            }
        }

        $first = collect($decoded)->filter(fn ($v) => filled($v))->first();

        return $first === null ? null : (string) $first;
    }

    /** `Whats_App` reads badly in a JSON body; `whats_app` does not. */
    private function slug(string $key): string
    {
        return strtolower($key);
    }
}
