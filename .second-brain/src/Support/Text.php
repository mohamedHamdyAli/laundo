<?php

namespace Laundo\SecondBrain\Support;

/**
 * Turning identifiers into words.
 *
 * `LaundryAssigner` has to match a search for "laundry assignment", and
 * `order_status_logs` has to match "order status". Everything in the search
 * path goes through `tokens()` so the query and the document are cut the same
 * way — a splitter used on one side only is how "OrderTask" stops matching
 * "task".
 */
final class Text
{
    /**
     * Words too common in this codebase to carry any signal.
     *
     * `blade` was removed from this list and `app` deliberately kept, and both
     * decisions are measured rather than argued:
     *
     *   app    2,522 of 4,833 documents (52%)  idf 0.65  — every path under
     *          `app/` contains it, so it separates nothing and adds a
     *          near-zero-weight term to every query that mentions it
     *   src    0 documents — this repository has no `src/`
     *   blade  278 documents  idf 2.85 — it selects views, which is exactly
     *          the category the index was weakest on
     *
     * Unstopping `app` was tried on the theory that `app-settings` needed it;
     * it did not fix that task and cost a rank elsewhere. The word that tells
     * `AppSettingController` apart is `setting` (idf 3.76), and what the task
     * actually needed was the route `api.v1.app-settings` to rank — a
     * different problem in a different place.
     *
     * What remains is English grammar and PHP keywords: words that appear in
     * every document and therefore separate none of them.
     */
    private const STOP = [
        'the', 'a', 'an', 'of', 'and', 'or', 'to', 'in', 'is', 'it', 'for', 'on',
        'where', 'what', 'which', 'how', 'does', 'do', 'that', 'this', 'with',
        'from', 'by', 'at', 'be', 'are', 'was', 'as', 'i', 'we', 'you', 'me',
        'php', 'class', 'function', 'public', 'return', 'app',
    ];

    /**
     * Split on case boundaries, separators and digits, lowercase, drop stop
     * words and single letters.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $text = str_replace(['\\', '/', '.', '-', '_', ':', '{', '}', '(', ')', '@'], ' ', $text);

        // camelCase and PascalCase, including the ABBRev+Word case.
        $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text) ?? $text;
        $text = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $text) ?? $text;

        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 2 || in_array($part, self::STOP, true)) {
                continue;
            }
            $out[] = self::stem($part);
        }

        return $out;
    }

    /**
     * The smallest stemmer that earns its place: plurals and a couple of
     * suffixes, no more. A real Porter stemmer would fold `settlement` and
     * `settle` together, which is right, and `pricing` and `price`, which is
     * also right — but it folds `rating` to `rate` and then a search for
     * "rating" starts returning the commission rate, which is wrong here.
     */
    public static function stem(string $word): string
    {
        if (mb_strlen($word) < 4) {
            return $word;
        }

        // Words that merely end in `s` and are not plurals at all. Without
        // these, `bonus` became `bonu` — which is how a scheduled feature came
        // to be labelled «Driver Close Bonu Month» — and `status` became
        // `statu` while `statuses` became `status`, so the two never matched.
        if (preg_match('/(us|ss|is)$/u', $word) === 1) {
            return $word;
        }

        if (mb_strlen($word) > 4 && str_ends_with($word, 'ies')) {
            return mb_substr($word, 0, -3).'y';          // laundries -> laundry
        }

        // A true `-es` plural only follows a sibilant. Applying it to every
        // word ending in `es` turned `rules` into `rul` while `rule` stayed
        // `rule`, so a query saying "commission rules" could not match a route
        // named `commission_rule` — which is exactly how the permission
        // question failed.
        if (preg_match('/(ch|sh|s|x|z)es$/u', $word) === 1) {
            return mb_substr($word, 0, -2);              // boxes -> box, classes -> class
        }

        if (str_ends_with($word, 's')) {
            return mb_substr($word, 0, -1);              // rules -> rule, orders -> order
        }

        return $word;
    }

    /** The first sentence of a docblock, cleaned of its asterisks and tags. */
    public static function summarise(?string $docblock, int $limit = 220): string
    {
        if ($docblock === null || $docblock === '') {
            return '';
        }

        // Split on the three real line endings, never on `\R`. Outside UTF-8
        // mode `\R` also matches the single byte 0x85, which is the second byte
        // of `م` — so a docblock in Arabic was being cut through the middle of
        // its characters and the summary came out as mojibake.
        $lines = preg_split("/\r\n|\n|\r/", $docblock) ?: [];
        $text = [];

        foreach ($lines as $line) {
            // `[ \t]` rather than `\s`, for the same byte-safety reason.
            $line = trim(preg_replace('#^[ \t]*(/\*\*|\*/|\*)[ \t]?#', '', $line) ?? '');

            if ($line === '' && $text !== []) {
                break;              // blank line ends the summary paragraph
            }
            if ($line === '' || str_starts_with($line, '@')) {
                if (str_starts_with($line, '@')) {
                    break;
                }
                continue;
            }
            $text[] = $line;
        }

        $summary = trim(implode(' ', $text));
        $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;

        if (mb_strlen($summary) > $limit) {
            $summary = rtrim(mb_substr($summary, 0, $limit - 1)).'…';
        }

        return $summary;
    }

    /**
     * `admin.driver_bonus_rule.index` → `Driver Bonus Rule`, for a label.
     *
     * Splits like `tokens()` but never stems — this is display text, and the
     * stemmer turned «Driver Close Bonus Month» into «Driver Close Bonu Month»
     * on every scheduled feature's label.
     */
    public static function humanise(string $identifier): string
    {
        $text = str_replace(['\\', '/', '.', '-', '_', ':'], ' ', $identifier);
        $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text) ?? $text;
        $text = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $text) ?? $text;

        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            static fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1),
            $words
        ));
    }

    public static function snake(string $studly): string
    {
        $s = preg_replace('/(?<!^)[A-Z]/', '_$0', $studly) ?? $studly;

        return strtolower($s);
    }
}
