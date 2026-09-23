<?php

namespace Laundo\SecondBrain\Index;

use Laundo\SecondBrain\Graph\Graph;
use Laundo\SecondBrain\Support\Text;

/**
 * The searchable index: one document per graph node, tokens weighted by the
 * field they came from.
 *
 * Why BM25 over a lexical index and not vector embeddings:
 *
 * Embeddings would need a model. Either a network call on every index and every
 * query — which puts the codebase's identifiers through a third party and stops
 * working offline — or a local model, which is a dependency the brief rules out
 * and a download nobody asked for. What is actually being searched here is
 * *identifiers*, and identifiers in this codebase are unusually informative:
 * `LaundryAssigner`, `OrderStateMachine`, `laundry_slot_capacities`. Split on
 * case boundaries and stemmed, they match the words a person would use.
 *
 * The meaning gap that remains — "where is the discount calculated" reaching
 * `CouponService` — is closed by expanding the *query* through a domain
 * glossary (`config.php → synonyms`), not by pretending to understand it. That
 * is honest about what it is, and it is checked by tests that ask real
 * questions about this repository.
 *
 * Field weights: a term in a class name is worth six of the same term buried in
 * a docblock, because a file named for what you asked about almost always is
 * what you asked about.
 */
final class Lexicon
{
    private const WEIGHTS = [
        'name' => 6.0,
        'symbol' => 4.0,   // fqcn tail, route name, table name
        'facet' => 2.5,    // methods, columns, enum cases, permission slugs
        'summary' => 2.2,  // the docblock's first sentence
        'path' => 1.5,
    ];

    /*
     * Why `summary` outweighs `path`.
     *
     * The path repeats the module name two or three times — `app/Modules/
     * Laundry/Models/Laundry.php` says "laundry" three times and means it once
     * — so weighting it above prose let any file *inside* a module outrank the
     * file that actually does the thing. `LaundryAssigner` lives under
     * `Order/Services`, and its docblock opens "Picks the laundry for an
     * order": one sentence that answers the question, against a path that
     * merely repeats a directory. This codebase's docblocks are unusually
     * substantial, which makes them the most informative field it has.
     */

    /**
     * @return array{
     *     docs:list<array<string,mixed>>,
     *     df:array<string,int>,
     *     postings:array<string,list<array{0:int,1:float}>>,
     *     total:int,
     *     avgdl:float,
     *     symbols:array<string,list<int>>,
     *     paths:array<string,int>,
     * }
     */
    public function build(Graph $graph): array
    {
        $docs = [];
        $postings = [];
        $df = [];
        $symbols = [];
        $paths = [];
        $lengthSum = 0.0;

        foreach ($graph->nodes() as $id => $node) {
            // Files are indexed through the class they contain wherever there
            // is one — two documents for `OfferController.php` would put the
            // same answer in a result list twice and halve how much fits.
            if ($node['type'] === 'file' && $this->fileHasClass($graph, $id)) {
                continue;
            }

            $fields = $this->fieldsFor($node);
            $weighted = [];

            foreach ($fields as $field => $text) {
                $weight = self::WEIGHTS[$field] ?? 1.0;
                foreach (Text::tokens($text) as $token) {
                    $weighted[$token] = ($weighted[$token] ?? 0.0) + $weight;
                }
            }

            if ($weighted === []) {
                continue;
            }

            $index = count($docs);
            $length = array_sum($weighted);
            $lengthSum += $length;

            $docs[] = [
                'id' => $id,
                'type' => $node['type'],
                'name' => $node['name'] ?? $id,
                'path' => $node['path'] ?? null,
                'module' => $node['module'] ?? null,
                'layer' => $node['layer'] ?? null,
                'summary' => $this->shortSummary($node),
                'len' => round($length, 2),
                'churn' => $node['churn'] ?? 0,
            ];

            foreach ($weighted as $token => $score) {
                $postings[$token][] = [$index, round($score, 3)];
                $df[$token] = ($df[$token] ?? 0) + 1;
            }

            // Exact-symbol lookups: `CouponService`, `coupons`, `coupon.create`.
            foreach ($this->symbolsFor($node) as $symbol) {
                $symbols[mb_strtolower($symbol)][] = $index;
            }

            if (($node['path'] ?? null) !== null) {
                $paths[$node['path']] ??= $index;
            }
        }

        ksort($postings);
        ksort($df);
        ksort($symbols);

        return [
            'docs' => $docs,
            'df' => $df,
            'postings' => $postings,
            'total' => count($docs),
            'avgdl' => count($docs) > 0 ? round($lengthSum / count($docs), 3) : 0.0,
            'symbols' => array_map(static fn (array $list) => array_values(array_unique($list)), $symbols),
            'paths' => $paths,
        ];
    }


    private function fileHasClass(Graph $graph, string $fileId): bool
    {
        foreach ($graph->out($fileId, ['contains']) as $out) {
            if (str_starts_with($out['id'], 'class:') || str_starts_with($out['id'], 'view:') || str_starts_with($out['id'], 'test:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,string>
     */
    private function fieldsFor(array $node): array
    {
        $fields = [
            'name' => (string) ($node['name'] ?? ''),
            'symbol' => '',
            'path' => (string) ($node['path'] ?? ''),
            'facet' => '',
            'summary' => (string) ($node['summary'] ?? ''),
        ];

        switch ($node['type']) {
            case 'route':
                $fields['symbol'] = trim(($node['name'] ?? '').' '.($node['uri'] ?? ''));

                // A route is an architectural entity, not a line in a file:
                // the verb, the URI and its prefix, the name, the controller
                // and action, the permission slug and the owning module are
                // all searchable.
                //
                // The **middleware stack is deliberately not here**, though it
                // was tried. `web`, `Authenticate` and `EnsureDashboardRole`
                // sit on hundreds of routes, so adding them lifted every route
                // document at once and displaced real answers: truth recall
                // fell 63.9% -> 61.1% and the false-positive rate rose 48.1%
                // -> 50.0% on the frozen set, with no compensating gain.
                // Permission questions are answered by graph traversal
                // instead — see Brain::permissionGate().
                $fields['facet'] = trim(implode(' ', array_filter(array_merge(
                    $node['methods'] ?? [],
                    $this->uriSegments((string) ($node['uri'] ?? '')),
                    [
                        (string) ($node['permission'] ?? ''),
                        (string) ($node['surface'] ?? ''),
                        (string) ($node['action'] ?? ''),
                        (string) ($node['module'] ?? ''),
                    ],
                ))));
                break;

            case 'table':
                $fields['symbol'] = (string) ($node['name'] ?? '');
                $fields['facet'] = implode(' ', array_slice($node['columns'] ?? [], 0, 60));
                break;

            case 'permission':
                $fields['symbol'] = (string) ($node['name'] ?? '');
                $fields['facet'] = (string) ($node['menu_title'] ?? '');
                break;

            case 'view':
                $fields['symbol'] = (string) ($node['name'] ?? '');

                // Field names, element kinds and components are *identifiers*
                // — `status`, `select`, `checkbox`, `x-status-toggle-button` —
                // so they belong in the facet beside a class's method names.
                $fields['facet'] = implode(' ', array_merge(
                    $node['permissions'] ?? [],
                    $node['client_side'] ?? [],
                    $node['fields'] ?? [],
                    $node['components'] ?? [],
                ));

                // Headings, labels and translation keys are prose — what a
                // person reads on the screen and therefore what they type when
                // looking for it. They join the summary, which is the field
                // weighted for exactly that.
                $fields['summary'] = trim(
                    (string) ($node['summary'] ?? '')
                    .' '.implode(' ', $node['headings'] ?? [])
                    .' '.implode(' ', $node['labels'] ?? [])
                    .' '.implode(' ', $node['translation_keys'] ?? [])
                );
                break;

            case 'test':
                $fields['facet'] = implode(' ', array_slice($node['cases'] ?? [], 0, 40));
                break;

            case 'command':
                $fields['symbol'] = (string) ($node['name'] ?? '');
                break;

            case 'method':
                $fields['symbol'] = (string) ($node['fqcn'] ?? '');
                break;

            default:
                $fields['symbol'] = (string) ($node['fqcn'] ?? '');
                $fields['facet'] = implode(' ', array_merge(
                    array_slice($node['methods'] ?? [], 0, 40),
                    // `present*`, `scope*`, `shredData` — contracts that are
                    // not public and so are not nodes, but which are exactly
                    // what somebody searches for. See `significant_methods`
                    // in config.php.
                    array_slice($node['significant_methods'] ?? [], 0, 30),
                    array_slice($node['cases'] ?? [], 0, 40),
                    array_slice($node['fillable'] ?? [], 0, 40),
                    array_slice($node['scopes'] ?? [], 0, 20),
                    array_slice($node['validates'] ?? [], 0, 40),
                    [(string) ($node['table'] ?? '')],
                ));
        }

        // The module name is worth a little on every document — it is how
        // "coupon" reaches a repository whose class name never says so.
        $fields['facet'] .= ' '.($node['module'] ?? '').' '.($node['layer'] ?? '');

        return $fields;
    }

    /**
     * `/admin/commission-rule/{id}` → `admin commission-rule`.
     *
     * The prefix is what somebody means by "the admin screens", and a bound
     * parameter is not a word anybody searches for.
     *
     * @return list<string>
     */
    private function uriSegments(string $uri): array
    {
        $segments = [];

        foreach (explode('/', trim($uri, '/')) as $segment) {
            if ($segment === '' || str_starts_with($segment, '{')) {
                continue;
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /** @param array<string,mixed> $node @return list<string> */
    private function symbolsFor(array $node): array
    {
        $symbols = array_filter([
            $node['name'] ?? null,
            $node['fqcn'] ?? null,
            $node['table'] ?? null,
            $node['uri'] ?? null,
        ]);

        if (($node['fqcn'] ?? null) !== null) {
            $separator = strrpos($node['fqcn'], '\\');
            if ($separator !== false) {
                $symbols[] = substr($node['fqcn'], $separator + 1);
            }
        }

        return array_values(array_unique(array_map('strval', $symbols)));
    }

    /** @param array<string,mixed> $node */
    private function shortSummary(array $node): string
    {
        $summary = trim((string) ($node['summary'] ?? ''));

        if ($summary !== '') {
            return mb_strlen($summary) > 150 ? rtrim(mb_substr($summary, 0, 149)).'…' : $summary;
        }

        // No docblock: say what it is instead of nothing at all.
        return match ($node['type']) {
            'route' => trim(implode('|', $node['methods'] ?? []).' '.($node['uri'] ?? '')),
            'table' => count($node['columns'] ?? []).' columns',
            'permission' => 'permission slug',
            default => '',
        };
    }
}
