<?php

namespace App\Support\Translation;

use Illuminate\Contracts\Translation\Loader;

/**
 * Laravel's own loader, with the validation messages edited from the panel laid
 * over the shipped ones.
 *
 * Validation messages live in `lang/{code}/validation.php` — a PHP file, which
 * no screen can safely write. What «Edit Validation Messages» saves goes to
 * `{code}_validation.json` in `config('app.validation_overrides_path')`
 * instead, one flat key per message (`required`, `min.string`,
 * `attributes.phone`), and this lays it over the group when Laravel asks for
 * `validation`. So the shipped file stays the default, a cleared box is a
 * reset, and a language added later — which has no `validation.php` of its
 * own — can still be given its messages.
 *
 * Every other group, the JSON strings and namespaces go straight through.
 */
class ValidationOverrideLoader implements Loader
{
    public const GROUP = 'validation';

    public function __construct(private readonly Loader $inner, private readonly string $fallbackLocale) {}

    public static function directory(): string
    {
        return rtrim((string) config('app.validation_overrides_path', storage_path('app/lang')), '/\\');
    }

    public static function pathFor(string $locale): string
    {
        return self::directory().DIRECTORY_SEPARATOR."{$locale}_validation.json";
    }

    /**
     * A locale is a language code — `ar`, `en`, `pt_BR`, `zh-Hant-TW` — and
     * anything else is not something to build a file name from.
     */
    public static function isLocale(string $locale): bool
    {
        return (bool) preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,2}$/', $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if ($group !== self::GROUP || ($namespace !== null && $namespace !== '*')) {
            return $lines;
        }

        $overrides = self::overrides((string) $locale);

        if ($overrides === []) {
            return $lines;
        }

        // A language that ships no messages of its own starts from the
        // fallback's. Laravel falls back one message at a time, but it reads
        // `validation.attributes` as one array — so naming a single field here
        // would otherwise un-name every other.
        if ($lines === [] && $locale !== $this->fallbackLocale) {
            $lines = $this->inner->load($this->fallbackLocale, $group, $namespace);
        }

        return self::lay((array) $lines, $overrides);
    }

    /**
     * The saved overrides for a language, flat — `{"attributes.phone": "…"}`.
     *
     * @return array<string, string>
     */
    public static function overrides(string $locale): array
    {
        if (! self::isLocale($locale)) {
            return [];
        }

        $path = self::pathFor($locale);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded)
            ? array_filter($decoded, fn ($value, $key) => is_string($key) && is_string($value) && $value !== '', ARRAY_FILTER_USE_BOTH)
            : [];
    }

    /**
     * Lay flat overrides onto the group.
     *
     * Not `Arr::undot()`: a message is `rule` or `rule.type` (`min.string`) and
     * never deeper, but a field's name is keyed by the field as the request
     * names it, and that can hold dots of its own — `items.*.quantity`. Undotted,
     * that becomes three nested arrays, and a field `name` beside a `name.en`
     * turns the string into an array and loses the name. So everything after the
     * first dot is one key, which is how Laravel reads `attributes` anyway
     * (it dots the array before looking a field up).
     *
     * Skipped rather than applied, so the shipped message answers instead:
     * - an override that would turn a message into an array, or an array into
     *   a message — only a file edited by hand can hold one;
     * - an override missing a placeholder the shipped message has — saved
     *   before a release added `:min` to it, say. The screen refuses these on
     *   save; this holds the same line for what was saved before.
     *
     * @param  array<string, mixed>  $lines
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    public static function lay(array $lines, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            [$head, $rest] = array_pad(explode('.', $key, 2), 2, null);

            $shipped = $lines[$head] ?? null;

            if ($rest !== null) {
                if ($shipped !== null && ! is_array($shipped)) {
                    continue;
                }

                $shipped = $shipped[$rest] ?? null;
            } elseif (is_array($shipped)) {
                continue;
            }

            if (is_string($shipped) && self::missingPlaceholders($shipped, $value) !== []) {
                continue;
            }

            if ($rest === null) {
                $lines[$head] = $value;
            } else {
                $lines[$head][$rest] = $value;
            }
        }

        return $lines;
    }

    /**
     * The placeholders in a message — `:attribute`, `:min` — as Laravel will
     * fill them. It fills `:min`, `:Min` and `:MIN` alike, so those three are
     * one placeholder; any other spelling (`:mIN`) it leaves on the page as
     * typed, so that is not the placeholder at all.
     *
     * @return list<string>
     */
    public static function placeholders(string $message): array
    {
        preg_match_all('/:([a-zA-Z_]+)/', $message, $matches);

        $found = [];

        foreach ($matches[1] as $name) {
            $lower = strtolower($name);

            $found[] = in_array($name, [$lower, ucfirst($lower), strtoupper($name)], true)
                ? ':'.$lower
                : ':'.$name;
        }

        return array_values(array_unique($found));
    }

    /**
     * What the default fills in that the message would not.
     *
     * @return list<string>
     */
    public static function missingPlaceholders(string $default, string $message): array
    {
        return array_values(array_diff(self::placeholders($default), self::placeholders($message)));
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces()
    {
        return $this->inner->namespaces();
    }

    /**
     * Anything else FileLoader offers (`addPath`, `paths`, …) — packages reach
     * for those, and a wrapper that dropped them would break them.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}
