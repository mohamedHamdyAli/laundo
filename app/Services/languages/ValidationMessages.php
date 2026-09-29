<?php

namespace App\Services\languages;

use App\Models\Language;
use App\Services\ActivityLogger;
use App\Support\Translation\ValidationOverrideLoader;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * «Edit Validation Messages» — the messages a rejected request is answered
 * with, and the names the fields are called by, for one language.
 *
 * The shipped `lang/{code}/validation.php` is the default and is never
 * written: what is saved is only what differs, in `{code}_validation.json`,
 * which ValidationOverrideLoader lays over it. The rows are the fallback
 * language's messages with this language's own laid over them — a message
 * the Arabic file never translated still answers Arabic users (in English),
 * so it is exactly the one that most needs a box here.
 *
 * Two guards, both against a message that looks right and answers wrong:
 *   - **only keys the shipped files have** — a typo in a key would save a
 *     message nothing ever asks for;
 *   - **every placeholder kept** — `:attribute`, `:min`, `:date` are filled in
 *     at the moment of refusal, and a message that lost one tells the customer
 *     «must be at least characters» with the number missing.
 */
class ValidationMessages
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * The screen's rows, grouped: the messages, then the field names.
     *
     * @return list<array{label: string, hint: string, rows: list<array{key: string, default: string, value: string, tokens: list<string>}>}>
     */
    public function groups(string $code): array
    {
        $defaults = $this->defaults($code);
        $overrides = ValidationOverrideLoader::overrides($code);

        $messages = [];
        $fields = [];

        foreach ($defaults as $key => $default) {
            $row = [
                'key' => $key,
                'default' => $default,
                'value' => $overrides[$key] ?? '',
                'tokens' => ValidationOverrideLoader::placeholders($default),
            ];

            if (str_starts_with($key, 'attributes.')) {
                $fields[] = $row;
            } else {
                $messages[] = $row;
            }
        }

        return [
            [
                'label' => __('Messages'),
                'hint' => __('What a customer, a driver or an operator is told when something they sent is refused.'),
                'rows' => $messages,
            ],
            [
                'label' => __('Field names'),
                'hint' => __('What each field is called inside those messages.'),
                'rows' => $fields,
            ],
        ];
    }

    /**
     * Save what differs from the defaults. A blank box is a reset.
     *
     * All or nothing: one message that dropped a placeholder refuses the save,
     * so the file never holds half of what the operator meant.
     *
     * @param  array<string, mixed>  $submitted  key => value, from the form
     *
     * @throws ValidationException when a message drops a placeholder
     */
    public function save(Language $language, array $submitted): int
    {
        $code = (string) $language->code;

        // The code becomes a file name. It comes from the languages table, not
        // the request, but a row typed as `../x` is not going to be written.
        abort_unless(ValidationOverrideLoader::isLocale($code), 422);

        $defaults = $this->defaults($code);
        $saved = [];
        $errors = [];

        foreach ($submitted as $key => $value) {
            $key = (string) $key;

            if (! array_key_exists($key, $defaults) || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            // Blank, the same words as the default, or bytes that are not text
            // (only a hand-made request sends those): nothing to keep.
            if ($value === '' || $value === $defaults[$key] || ! mb_check_encoding($value, 'UTF-8')) {
                continue;
            }

            $missing = ValidationOverrideLoader::missingPlaceholders($defaults[$key], $value);

            if ($missing !== []) {
                // Keyed by the input's own name, `messages[min.string]`: the
                // background submit finds a field by name, and the dotted form
                // would be read as `messages[min][string]` and land in the banner.
                $errors["messages[{$key}]"] = __('Keep :tokens in this message — they are filled in when it is shown.', [
                    'tokens' => implode('، ', $missing),
                ]);

                continue;
            }

            $saved[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        ksort($saved);
        $previous = ValidationOverrideLoader::overrides($code);
        $path = ValidationOverrideLoader::pathFor($code);

        if ($saved === []) {
            File::delete($path);
        } else {
            File::ensureDirectoryExists(dirname($path));
            // `replace` writes a temporary file and renames it over the old
            // one, so a request refused mid-save reads the old set or the new
            // one — never half a file, which would decode to nothing at all.
            File::replace($path, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        }

        $this->record($language, $defaults, $previous, $saved);

        return count($saved);
    }

    /**
     * The shipped messages for this language, flat: the fallback language's,
     * with this language's own laid over them. `custom` is left out — it holds
     * per-field wording nobody has written, and a row per nothing is noise.
     *
     * @return array<string, string>
     */
    public function defaults(string $code): array
    {
        $fallback = $this->shipped((string) config('app.fallback_locale', 'en'));

        return ValidationOverrideLoader::isLocale($code)
            ? array_replace($fallback, $this->shipped($code))
            : $fallback;
    }

    /**
     * @return array<string, string>
     */
    private function shipped(string $code): array
    {
        $path = lang_path("{$code}/validation.php");
        $lines = is_file($path) ? require $path : [];

        return array_filter(
            Arr::dot(is_array($lines) ? Arr::except($lines, ['custom']) : []),
            fn ($value) => is_string($value),
        );
    }

    /**
     * Into the activity log, against the language: each row whose wording
     * changed, as the wording read before and after — the shipped default
     * where no override stood, so a reset reads as the words it went back to.
     *
     * @param  array<string, string>  $defaults
     * @param  array<string, string>  $previous
     * @param  array<string, string>  $saved
     */
    private function record(Language $language, array $defaults, array $previous, array $saved): void
    {
        $diff = [];

        foreach (array_keys($previous + $saved) as $key) {
            $old = $previous[$key] ?? null;
            $new = $saved[$key] ?? null;

            if ($old !== $new) {
                $diff['validation.'.$key] = [
                    'old' => $old ?? $defaults[$key] ?? null,
                    'new' => $new ?? $defaults[$key] ?? null,
                ];
            }
        }

        $this->activity->recordChanges($language, $diff);
    }
}
