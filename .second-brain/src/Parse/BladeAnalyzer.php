<?php

namespace Laundo\SecondBrain\Parse;

/**
 * What a Blade file points at.
 *
 * The panel is 278 Blade files and the question asked of them is almost always
 * "which view does this screen render, and what does it call" — so this reads
 * the four things that answer it and nothing else: the layout it extends, the
 * partials it includes, the routes it names, and the permission slugs it checks
 * through `canDo()`.
 *
 * `canDo()` matters because it is one of the three enforcement points in this
 * project (route middleware, this helper, and the sidebar), and a screen whose
 * button is gated on a different slug from its route is exactly the kind of
 * thing somebody needs to find before changing a permission.
 */
final class BladeAnalyzer
{
    /**
     * @return array<string,mixed>
     */
    public function analyse(string $source): array
    {
        return [
            // The human-readable half. Without it a Blade file is searchable
            // only by its own filename: "add a filter dropdown to the orders
            // list screen" matched nothing at all, because none of the words a
            // person uses about a screen — filter, dropdown, checkbox, matrix,
            // heading — appeared anywhere in the index.
            'headings' => $this->headings($source),
            'labels' => $this->labels($source),
            'fields' => $this->fields($source),
            'translation_keys' => $this->translationKeys($source),

            'extends' => $this->first($source, '/@extends\(\s*[\'"]([^\'"]+)[\'"]/'),
            'section' => $this->first($source, '/@section\(\s*[\'"]([^\'"]+)[\'"]/'),
            'includes' => $this->all($source, '/@(?:include|includeIf|includeWhen|each)\(\s*[\'"]([^\'"]+)[\'"]/'),
            'components' => $this->all($source, '/<x-([a-z0-9._-]+)/i'),
            'routes' => $this->all($source, '/\broute\(\s*[\'"]([^\'"]+)[\'"]/'),
            'permissions' => $this->all($source, '/\bcanDo\(\s*[\'"]([^\'"]+)[\'"]/'),
            'web_text_keys' => $this->all($source, '/\bwebText\(\s*[\'"]([^\'"]+)[\'"]/'),
            'stacks' => $this->all($source, '/@push\(\s*[\'"]([^\'"]+)[\'"]/'),
            'client_side' => array_values(array_filter([
                str_contains($source, 'setupAjaxSearch') ? 'setupAjaxSearch' : null,
                str_contains($source, 'setupClientFilter') ? 'setupClientFilter' : null,
                str_contains($source, 'setupRichText') ? 'setupRichText' : null,
            ])),
        ];
    }

    /** `resources/views/admin/offer/index.blade.php` → `admin.offer.index` */
    public function viewName(string $relativePath): string
    {
        $name = preg_replace('#^resources/views/#', '', $relativePath) ?? $relativePath;
        $name = preg_replace('#\.blade\.php$#', '', $name) ?? $name;

        return str_replace('/', '.', $name);
    }

    /**
     * Section titles and headings — what the screen calls itself.
     *
     * @return list<string>
     */
    private function headings(string $source): array
    {
        $out = [];

        foreach ([
            '#<h[1-6][^>]*>(.*?)</h[1-6]>#is',
            '#<legend[^>]*>(.*?)</legend>#is',
            '#<caption[^>]*>(.*?)</caption>#is',
            '#<th[^>]*>(.*?)</th>#is',
            '#@section\(\s*[\'"]title[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]#i',
            '#<x-page-title[^>]*title=["\']([^"\']+)#i',
        ] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] ?? [] as $raw) {
                $text = $this->humanText($raw);
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $this->bounded($out, 40);
    }

    /**
     * Anything the person using the screen actually reads: field labels,
     * button captions, placeholders, option text.
     *
     * @return list<string>
     */
    private function labels(string $source): array
    {
        $out = [];

        foreach ([
            '#<label[^>]*>(.*?)</label>#is',
            '#<button[^>]*>(.*?)</button>#is',
            '#<option[^>]*>(.*?)</option>#is',
            '#placeholder=["\']([^"\']{2,80})["\']#i',
            '#<a[^>]*class=["\'][^"\']*btn[^"\']*["\'][^>]*>(.*?)</a>#is',
            '#value=["\']([A-Za-z][^"\']{2,60})["\'][^>]*type=["\']submit#i',
        ] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] ?? [] as $raw) {
                $text = $this->humanText($raw);
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $this->bounded($out, 60);
    }

    /**
     * The names a form posts under — `name="status"`, `wire:model`, and the
     * element kinds themselves, so "checkbox", "select" and "dropdown" are
     * searchable words rather than markup nobody indexed.
     *
     * @return list<string>
     */
    private function fields(string $source): array
    {
        $out = [];

        preg_match_all('#<(input|select|textarea)\b[^>]*>#i', $source, $elements);

        foreach ($elements[0] ?? [] as $i => $element) {
            $kind = strtolower($elements[1][$i]);
            $out[] = $kind;

            if ($kind === 'select') {
                $out[] = 'dropdown';
            }

            if (preg_match('#\btype=["\']([a-z]+)["\']#i', $element, $type) === 1) {
                $out[] = strtolower($type[1]);
            }

            if (preg_match('#\bname=["\']([^"\']+)["\']#i', $element, $name) === 1) {
                // `items[3][price]` is three useful words, not one opaque key.
                foreach (preg_split('/[^A-Za-z0-9_]+/', $name[1], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                    $out[] = $part;
                }
            }
        }

        return $this->bounded($out, 80);
    }

    /** @return list<string> */
    private function translationKeys(string $source): array
    {
        preg_match_all('/\b__\(\s*[\'"]([^\'"]{2,80})[\'"]/', $source, $matches);

        return $this->bounded($matches[1] ?? [], 60);
    }

    /**
     * Blade markup to something a person would have typed.
     *
     * Directives, echoes and PHP are removed rather than indexed: `{{
     * $row->id }}` is not a word anybody searches for, and leaving it in would
     * fill the index with variable names that match every screen.
     */
    private function humanText(string $raw): string
    {
        // `{{ __('Orders') }}` and `@lang('Orders')` — keep the string, drop
        // the scaffolding around it.
        $raw = preg_replace('/\{\{[^}]*?[\'"]([^\'"]{2,80})[\'"][^}]*?\}\}/s', ' $1 ', $raw) ?? $raw;
        $raw = preg_replace('/@lang\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', ' $1 ', $raw) ?? $raw;

        // Everything else that is code rather than copy.
        $raw = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}|<\?php.*?\?>/s', ' ', $raw) ?? $raw;
        $raw = preg_replace('/@[a-zA-Z]+(\s*\(.*?\))?/s', ' ', $raw) ?? $raw;
        $raw = preg_replace('/<[^>]*>/s', ' ', $raw) ?? $raw;

        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        // Template residue. A `{{ }}` echo that straddles the tag boundary
        // leaves half an expression behind — one `<th>` came out as
        // `0 ? 'text-warning' : '' }}"> unassigned`. Anything still carrying
        // the marks of code is dropped rather than cleaned further: a
        // half-parsed expression is not a label, and guessing which half was
        // the copy is how nonsense gets into the index.
        foreach (['{{', '}}', '{!!', '!!}', '<?', '?>', '->', '::', '$'] as $residue) {
            if (str_contains($text, $residue)) {
                return '';
            }
        }

        // A fragment that is only punctuation, digits or a stray symbol is not
        // copy. Two characters is the shortest real label here («ar», «en»).
        if (mb_strlen($text) < 2 || mb_strlen($text) > 120) {
            return '';
        }

        return preg_match('/\p{L}/u', $text) === 1 ? $text : '';
    }

    /**
     * Deduplicate, cap, and keep a stable order.
     *
     * Stable because the graph is written to disk and compared build to build:
     * a set that reshuffled would make every rebuild a diff.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function bounded(array $values, int $limit): array
    {
        $seen = [];
        $out = [];

        foreach ($values as $value) {
            $key = mb_strtolower(trim($value));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = trim($value);

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function first(string $source, string $pattern): ?string
    {
        return preg_match($pattern, $source, $matches) === 1 ? $matches[1] : null;
    }

    /** @return list<string> */
    private function all(string $source, string $pattern): array
    {
        preg_match_all($pattern, $source, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
