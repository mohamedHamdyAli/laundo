<?php

namespace App\Services\languages;

use App\Models\Language;
use App\Services\ActivityLogger;
use App\Support\Translation\ValidationOverrideLoader;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * The words the two apps show — «نصوص تطبيق العميل» and «نصوص تطبيق المندوب».
 *
 * Two layers per app per language, as with the validation messages:
 *   - **shipped** — `resources/lang/{code}_app_{app}.json`, the file the app
 *     team bundles, nested (`login.title`), with `{}` / `{name}` placeholders
 *     and plural maps (`tasks.count.zero|one|other`). Tracked, never written
 *     at runtime, so a new file from the team pulls cleanly;
 *   - **overrides** — what an operator typed on the screen, flat dotted keys,
 *     in `config('app.app_strings_overrides_path')`.
 *
 * The API (`GET /translations/app?app=…`) serves English shipped, English
 * overrides, the language's shipped, the language's overrides — each laid over
 * the last. So a key a language never translated reaches the app in English
 * rather than as a hole, and an app can change its copy without a release.
 *
 * **The app teams own the keys.** The screen edits values only; a key exists
 * because English — or the language's own file, for Arabic's extra plural
 * forms (`two`, `few`, `many`) — has it.
 *
 * Replaces `{code}_mobile.json`, the single flat override set nothing filled.
 */
class AppStrings
{
    /** The apps that read these files — the `app` parameter's values. */
    public const APPS = ['user', 'driver'];

    /** The language whose file is the key list and the fallback. */
    public const TEMPLATE = 'en';

    /** Bumped on every save, so `served()`'s cache never outlives an edit. */
    private const VERSION_KEY = 'app_strings_version';

    public function __construct(private readonly ActivityLogger $activity) {}

    public static function isApp(mixed $app): bool
    {
        return is_string($app) && in_array($app, self::APPS, true);
    }

    /**
     * The app team's file. Null for an app or a code that is not one — the
     * code becomes a file name, and on the API it comes off the query string.
     */
    public static function shippedPath(string $app, string $code): ?string
    {
        $name = self::named($app, $code);

        return $name === null ? null : lang_path($name);
    }

    public static function overridesPath(string $app, string $code): ?string
    {
        $name = self::named($app, $code);

        return $name === null
            ? null
            : rtrim((string) config('app.app_strings_overrides_path'), '/\\').DIRECTORY_SEPARATOR.$name;
    }

    /**
     * What the app team shipped for this language, nested.
     *
     * @return array<string, mixed>
     */
    public static function shipped(string $app, string $code): array
    {
        return self::read(self::shippedPath($app, $code));
    }

    /**
     * What an operator typed for this language, flat.
     *
     * @return array<string, string>
     */
    public static function overrides(string $app, string $code): array
    {
        return self::flat(self::read(self::overridesPath($app, $code)));
    }

    /**
     * What an app is handed, nested: English, then the base language of a
     * regional code (`ar` for `ar-EG`), then the code itself — each shipped
     * file with its typed words laid over it.
     *
     * Cached: the endpoint is public and runs on every app launch. The key
     * carries every file's mtime and size and a counter `save()` bumps, so an
     * edit or a deploy is served at once.
     *
     * @return array<string, mixed>
     */
    public static function served(string $app, string $code): array
    {
        $chain = self::chain($code);
        $paths = [];

        foreach ($chain as $link) {
            $paths[] = self::shippedPath($app, $link);
            $paths[] = self::overridesPath($app, $link);
        }

        clearstatcache();
        $stamp = md5(implode('|', array_map(
            fn (string $path) => $path.'@'.(is_file($path) ? filemtime($path).':'.filesize($path) : '-'),
            array_filter($paths),
        )).'|'.Cache::get(self::VERSION_KEY, 0));

        return Cache::remember("app_strings:{$app}:{$stamp}", now()->addDay(), function () use ($app, $chain) {
            $layers = [];

            foreach ($chain as $link) {
                $layers[] = self::shipped($app, $link);
                $layers[] = Arr::undot(self::overrides($app, $link));
            }

            return array_replace_recursive(...$layers);
        });
    }

    /**
     * The screen's rows, one group per top-level section, in English's order.
     * Each box holds the operator's own words, if any; its placeholder is
     * what a reset brings back — the language's shipped words, or English's.
     *
     * @return list<array{label: string, rows: list<array{key: string, default: string, value: string, english: string, multiline: bool}>}>
     */
    public function groups(string $app, string $code): array
    {
        $english = self::flat(self::shipped($app, self::TEMPLATE));
        $defaults = $this->defaultsOver($english, $app, $code);
        $overrides = self::overrides($app, $code);

        $groups = [];

        foreach ($defaults as $key => $default) {
            $value = $overrides[$key] ?? '';
            $section = strstr($key, '.', true) ?: $key;

            $groups[$section]['label'] = $section;
            $groups[$section]['rows'][] = [
                'key' => $key,
                'default' => $default,
                'value' => $value,
                'english' => $english[$key] ?? '',
                // An <input> drops a newline, and two of these messages are
                // written across lines on purpose.
                'multiline' => str_contains($default.$value, "\n") || mb_strlen($default) > 90,
            ];
        }

        return array_values($groups);
    }

    /**
     * What a reset brings back, per key: everything the app would be served
     * without this language's own typed words — English with its edits, the
     * base language with its edits, the language's shipped file. Its keys are
     * the screen's keys.
     *
     * @return array<string, string>
     */
    public function defaults(string $app, string $code): array
    {
        return $this->defaultsOver(self::flat(self::shipped($app, self::TEMPLATE)), $app, $code);
    }

    /**
     * @param  array<string, string>  $english  English's shipped words, already read
     * @return array<string, string>
     */
    private function defaultsOver(array $english, string $app, string $code): array
    {
        $code = strtolower($code);
        $layers = [$english];

        foreach (self::chain($code) as $link) {
            if ($link !== self::TEMPLATE) {
                $layers[] = self::flat(self::shipped($app, $link));
            }

            if ($link !== $code) {
                $layers[] = self::overrides($app, $link);
            }
        }

        return array_replace(...$layers);
    }

    /**
     * Save what differs from the shipped words. A blank box is a reset.
     *
     * A key the form did not send keeps its override: a form cut short (PHP
     * stops reading at `max_input_vars`) must not read as «reset the rest».
     *
     * All or nothing: one value bringing a placeholder the app will not fill
     * refuses the save, so the file never holds half of what was meant.
     *
     * @param  array<string, mixed>  $submitted  dotted key => value, from the form
     *
     * @throws ValidationException when a value brings a placeholder the app will not fill
     */
    public function save(Language $language, string $app, array $submitted): int
    {
        $code = strtolower((string) $language->code);
        $path = self::overridesPath($app, $code);

        abort_if($path === null, 422);

        // Read, change, write — two operators saving at once would otherwise
        // each write what they read plus their own, and the later one wins.
        return Cache::lock("app_strings_save:{$app}:{$code}", 30)
            ->block(10, fn () => $this->saveLocked($language, $app, $code, $path, $submitted));
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private function saveLocked(Language $language, string $app, string $code, string $path, array $submitted): int
    {
        $english = self::flat(self::shipped($app, self::TEMPLATE));
        $defaults = $this->defaultsOver($english, $app, $code);
        $previous = self::overrides($app, $code);
        $next = $previous;
        $errors = [];

        foreach ($submitted as $key => $value) {
            $key = (string) $key;

            // Laravel turns an emptied box into null before it gets here.
            if (! array_key_exists($key, $defaults) || ($value !== null && ! is_scalar($value))) {
                continue;
            }

            $value = str_replace(["\r\n", "\r"], "\n", trim((string) $value));

            // Bytes that are not text: only a hand-made request sends those.
            if (! mb_check_encoding($value, 'UTF-8')) {
                continue;
            }

            if ($value === '' || $value === $defaults[$key]) {
                unset($next[$key]);

                continue;
            }

            $unknown = self::unknownPlaceholders($english[$key] ?? $defaults[$key], $value);

            if ($unknown !== []) {
                // Keyed by the input's own name, as on the validation screen,
                // so the background submit paints it beside the box.
                $errors["strings[{$key}]"] = __('The app does not fill :tokens here — use only the placeholders the English text has.', [
                    'tokens' => implode('، ', $unknown),
                ]);

                continue;
            }

            $next[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        ksort($next);

        if ($next === []) {
            File::delete($path);
        } else {
            File::ensureDirectoryExists(dirname($path));
            // `replace` writes a temporary file and renames it over the old
            // one: an app fetching mid-save reads the old set or the new one.
            File::replace($path, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        }

        self::forgetServed();

        $this->record($language, $app, $defaults, $previous, $next);

        return count($next);
    }

    /**
     * Placeholders in `$value` the app will not fill: a named one the
     * reference does not use, or more `{}` than it has. Fewer is allowed —
     * Arabic says «منذ دقيقتين» where English needs the number.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $reference, string $value): array
    {
        preg_match_all('/\{[^{}]*\}/u', $reference, $known);
        preg_match_all('/\{[^{}]*\}/u', $value, $used);

        $unknown = array_values(array_unique(array_filter(
            $used[0],
            fn (string $token) => $token !== '{}' && ! in_array($token, $known[0], true),
        )));

        $positional = fn (array $tokens) => count(array_filter($tokens, fn (string $token) => $token === '{}'));

        if ($positional($used[0]) > $positional($known[0])) {
            $unknown[] = '{}';
        }

        return $unknown;
    }

    /**
     * A language's typed words follow it when its code changes — named by the
     * code, left behind they would stop answering, and answer instead for the
     * next language given the old code. Words already under the new code win.
     */
    public static function moveOverrides(?string $from, string $to): void
    {
        foreach (self::APPS as $app) {
            $source = $from === null ? null : self::overridesPath($app, $from);
            $target = self::overridesPath($app, $to);

            if ($source !== null && $target !== null && $source !== $target && is_file($source) && ! is_file($target)) {
                File::move($source, $target);
            }
        }

        self::forgetServed();
    }

    private static function forgetServed(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    /**
     * English first, then a regional code's base language, then the code —
     * `ar-EG` is served `ar`'s words where it has none of its own.
     *
     * @return list<string>
     */
    private static function chain(string $code): array
    {
        $code = strtolower($code);
        $chain = [self::TEMPLATE];

        if (ValidationOverrideLoader::isLocale($code)) {
            $chain[] = (string) preg_split('/[-_]/', $code)[0];
            $chain[] = $code;
        }

        return array_values(array_unique($chain));
    }

    private static function named(string $app, string $code): ?string
    {
        if (! self::isApp($app) || ! ValidationOverrideLoader::isLocale($code)) {
            return null;
        }

        return strtolower($code)."_app_{$app}.json";
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $strings
     * @return array<string, string>
     */
    private static function flat(array $strings): array
    {
        return array_filter(Arr::dot($strings), fn ($value) => is_string($value));
    }

    /**
     * Into the activity log, against the language: each key whose words
     * changed, before and after — the shipped words where no override stood,
     * so a reset reads as the words it went back to.
     *
     * @param  array<string, string>  $defaults
     * @param  array<string, string>  $previous
     * @param  array<string, string>  $next
     */
    private function record(Language $language, string $app, array $defaults, array $previous, array $next): void
    {
        $diff = [];

        foreach (array_keys($previous + $next) as $key) {
            $old = $previous[$key] ?? null;
            $new = $next[$key] ?? null;

            if ($old !== $new) {
                $diff["app_{$app}.{$key}"] = [
                    'old' => $old ?? $defaults[$key] ?? null,
                    'new' => $new ?? $defaults[$key] ?? null,
                ];
            }
        }

        $this->activity->recordChanges($language, $diff);
    }
}
