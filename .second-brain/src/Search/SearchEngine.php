<?php

namespace Laundo\SecondBrain\Search;

use Laundo\SecondBrain\Support\Text;

/**
 * BM25 with three things bolted on that matter more than the ranking function:
 *
 * 1. **Intent routing.** "What depends on CouponService?" is not a search, it
 *    is a graph traversal, and answering it with a ranked list of documents
 *    that mention the word "coupon" is how a brain earns a reputation for being
 *    useless. The question forms are recognised and handed to the right tool.
 * 2. **Query expansion through a domain glossary.** "Where is the discount
 *    calculated" contains no token that appears in `CouponService`; the
 *    glossary maps it. Only the query is expanded — expanding the index
 *    instead would make every laundry document match "washing".
 * 3. **Exact-symbol short-circuit.** A query that *is* a symbol (`CouponService`,
 *    `order_settlements`, `admin.offer.index`) returns that symbol first, with
 *    no chance of a docblock outranking it.
 *
 * ## Length normalisation is textbook, and that was measured, not assumed
 *
 * `Order.php` is 5.6x the average document length, and the obvious reading was
 * that BM25 was punishing the central file for being central. Two corrections
 * were tried against the frozen 20-task set:
 *
 *   b = 0.40          Top-1 45% -> 40%, Top-3 70% -> 60%
 *   ratio capped at 2 Top-1 45% -> 40%, Top-3 70% -> 60%
 *
 * Both were worse, and neither fixed the task that motivated them: `Order.php`
 * still did not appear for "add a relationship to the order model". The
 * diagnosis was wrong. `OrderMedia` carries a *higher* term frequency for
 * "order" (29.2) than `Order` itself (24.2) — a compound name repeats the term
 * across name, symbol and path — and is a fifth the length, so it wins on both
 * axes at once. No value of `b` changes that ordering.
 *
 * The real shape of the problem is that a document whose name is *entirely*
 * covered by the query (`Order`) is a more specific match than one carrying
 * tokens the query never mentioned (`OrderMedia`), and nothing here rewards
 * that. It is a sibling-crowding problem, not a length problem.
 *
 * ## Name coverage was built on that reading, and the measurement refused it
 *
 * The obvious next step was a bounded multiplicative boost for a document whose
 * whole name is covered by the query's literal terms. It was implemented in two
 * forms and both were reverted. What it did, measured:
 *
 *                     KNOWN (tuned against)        UNSEEN (held out)
 *   before            50 / 75 / 85  FP 48.1        50 / 80 / 87  FP 40.8
 *   with suffixes     35 / 85 / 90  FP 43.6        50 / 77 / 83  FP 39.8
 *   without           50 / 80 / 90  FP 47.9        43 / 67 / 77  FP 46.9
 *
 * It did exactly what it was designed to do on the two tasks it was designed
 * for: `Order.php` and the orders list view both went from absent to rank 2.
 * And it made the held-out set worse in every variant — the second one badly,
 * Top-3 80% -> 67%.
 *
 * The mechanism is instructive. Stripping the layer suffix makes `OrderService`
 * cover as `[order]`, so any query mentioning an order fully covers it, along
 * with that module's controller, repository and request; six known tasks each
 * slipped a rank behind a generic service. Keeping the suffix removes that but
 * leaves the boost firing on short, common names, which is what cost the unseen
 * set ten points of Top-5.
 *
 * So the diagnosis — that specificity is the missing signal — may well still be
 * right. Name coverage is the wrong instrument for it: a name is too short a
 * string to carry that much weight, and "fully covered" is a cliff rather than
 * a gradient. Anything tried next should be measured on the held-out set first,
 * because this one looked like a clear win right up until it was.
 */
final class SearchEngine
{
    private const K1 = 1.4;

    /** Above this token overlap, two documents are treated as siblings. */
    private const SIBLING_THRESHOLD = 0.72;

    /** What a crowded candidate keeps — marginal, so it can still win. */
    private const SIBLING_PENALTY = 0.55;

    private const B = 0.72;

    /**
     * @param  array<string,mixed>  $index
     * @param  array<string,list<string>>  $synonyms
     */
    public function __construct(
        private readonly array $index,
        private readonly array $synonyms = [],
    ) {}

    /**
     * @return array{
     *     intent:string,
     *     subject:?string,
     *     terms:list<string>,
     *     expanded:list<string>,
     *     results:list<array<string,mixed>>,
     * }
     */
    public function search(string $query, int $limit = 10, ?string $type = null, ?string $module = null): array
    {
        $intent = $this->intentOf($query);

        $terms = Text::tokens($intent['subject'] ?? $query);
        $weights = $this->termWeights($intent['subject'] ?? $query);
        $expanded = $this->expand($terms);

        $scores = $this->score($expanded, $terms, $weights);
        $scores = $this->applyExactSymbolBoost($query, $scores);

        $results = [];
        $docs = $this->index['docs'];

        arsort($scores);

        // One hit per file. Without this, asking for `CouponService` came back
        // as the class followed by four of its own methods — five lines of
        // result to say one thing, and the second-best *file* pushed off the
        // page. A method still wins a place when its class did not already
        // take one, because "the method that does it" is sometimes the answer.
        $seenPaths = [];

        // **Sibling diversification.** This repository contains 26 near-identical
        // `*CrudService.php` files, 34 `_*_table_body.blade.php` partials and a
        // model per order sub-entity. Their documents differ by one word, so a
        // generic query returned an arbitrary handful of them: "add a new field
        // to the offer create and edit form" came back with the City, Country,
        // Coupon and Item CRUD services beneath the Offer one.
        //
        // The penalty is marginal, not absolute: a candidate too similar to
        // something already accepted is pushed down, and it comes back if it
        // carries terms the accepted ones do not. Bounded to the accepted list
        // (at most `limit` entries), so this is O(results²) on a handful of
        // items and never a corpus-wide comparison.
        $accepted = [];
        $discounted = [];

        foreach ($scores as $docIndex => $score) {
            $doc = $docs[$docIndex] ?? null;
            if ($doc === null) {
                continue;
            }
            if ($type !== null && $doc['type'] !== $type) {
                continue;
            }
            if ($module !== null && strcasecmp((string) $doc['module'], $module) !== 0) {
                continue;
            }

            $path = $doc['path'];
            if ($path !== null && $type === null) {
                if (isset($seenPaths[$path])) {
                    continue;
                }
                $seenPaths[$path] = true;
            }

            $signature = $this->signatureOf($doc);
            $crowded = false;

            foreach ($accepted as $takenSignature) {
                if ($this->similarity($signature, $takenSignature) >= self::SIBLING_THRESHOLD) {
                    $crowded = true;
                    break;
                }
            }

            // A crowded candidate is not dropped — it is re-queued at a
            // discount, so it still wins its place if nothing better turns up.
            // Dropping outright would hide the second-best answer whenever the
            // best one happened to have a near-twin.
            if ($crowded && ! isset($discounted[$docIndex])) {
                $discounted[$docIndex] = true;
                $scores[$docIndex] = $score * self::SIBLING_PENALTY;

                continue;
            }

            $accepted[] = $signature;

            $results[] = [
                'id' => $doc['id'],
                'type' => $doc['type'],
                'name' => $doc['name'],
                'path' => $doc['path'],
                'module' => $doc['module'],
                'layer' => $doc['layer'],
                'summary' => $doc['summary'],
                'relevance' => 0.0,   // normalised below
                '_raw' => $score,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        // Normalise against the best hit, so a caller reads 1.00 / 0.71 / 0.44
        // rather than an unbounded BM25 number that means nothing on its own.
        $best = $results[0]['_raw'] ?? 0.0;
        foreach ($results as $i => $result) {
            $results[$i]['relevance'] = $best > 0 ? round($result['_raw'] / $best, 3) : 0.0;
            unset($results[$i]['_raw']);
        }

        return [
            'intent' => $intent['kind'],
            'subject' => $intent['subject'],
            'terms' => $terms,
            'expanded' => array_values(array_diff($expanded, $terms)),
            'results' => $results,
        ];
    }

    /**
     * A cheap shape for a document: its name, path and layer tokens.
     *
     * Not its full term vector — that lives in the postings, and rebuilding it
     * per result would cost more than the ranking did. Name and path are what
     * actually separate `cityCrudService` from `offerCrudService`, and
     * `_offer_table_body` from `_coupon_table_body`.
     *
     * @param  array<string,mixed>  $doc
     * @return array<string,true>
     */
    private function signatureOf(array $doc): array
    {
        $tokens = Text::tokens(
            ($doc['name'] ?? '').' '.($doc['path'] ?? '').' '.($doc['layer'] ?? '')
        );

        return array_fill_keys($tokens, true);
    }

    /**
     * Jaccard overlap: one intersection, one union. No library, no matrix, and
     * it runs at most `limit`² times per query — never across the corpus.
     *
     * @param  array<string,true>  $a
     * @param  array<string,true>  $b
     */
    private function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $union = count($a + $b);

        return $union === 0 ? 0.0 : count(array_intersect_key($a, $b)) / $union;
    }

    /**
     * The question forms worth recognising. Everything else is a plain search —
     * this deliberately does not try to parse natural language, it spots five
     * shapes that a graph answers better than a ranking does.
     *
     * @return array{kind:string,subject:?string}
     */
    public function intentOf(string $query): array
    {
        $normalised = trim(preg_replace('/\s+/', ' ', $query) ?? $query);

        $patterns = [
            // Permission questions are a graph walk — route --guarded_by-->
            // permission — and answering them with a ranked list of documents
            // that happen to contain the word "permission" is how "which
            // permission gates the commission rules screen" returned four
            // Payment classes and neither the controller nor the route. The
            // edges were always there; nothing asked them.
            'permission_gate' => '/^(?:which|what|who)\s+(?:permissions?|authoris?z?ations?|middlewares?|roles?)?\s*'
                .'(?:permission\s+)?(?:middleware\s+)?'
                .'(?:gates?|guards?|protects?|is\s+required\s+(?:for|by)|are\s+required\s+(?:for|by)|'
                .'is\s+used\s+by|controls?\s+access\s+to|can\s+access)\s+(.+?)\??$/i',
            'permission_gate_alt' => '/^who\s+can\s+(?:access|see|open|reach|use)\s+(.+?)\??$/i',
            'permission_gate_what' => '/^what\s+(?:permission|authorisation|authorization)\s+(?:does\s+)?(.+?)\s*(?:need|require|use)?\??$/i',

            'dependents' => '/^(?:what|which|who)\s+(?:files?\s+|classes?\s+)?(?:depends?\s+on|uses?|calls?|consumes?)\s+(.+?)\??$/i',
            'dependencies' => '/^what\s+does\s+(.+?)\s+(?:depend\s+on|use|call|need)\??$/i',
            'route_for' => '/^(?:which|what)\s+routes?\s+(?:reach(?:es)?|hits?|maps?\s+to|calls?)\s+(.+?)\??$/i',
            'tables_for' => '/^(?:which|what)\s+(?:database\s+)?tables?\s+(?:are\s+involved\s+in|belong\s+to|back|for)\s+(.+?)\??$/i',
            'related' => '/^(?:show\s+me\s+)?everything\s+(?:related\s+to|about|in)\s+(.+?)\??$/i',
            'locate' => '/^where\s+(?:is|are|does|do|should)\s+(.+?)\s*(?:implemented|handled|calculated|defined|live|go|happen)?\??$/i',
        ];

        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $normalised, $matches) === 1) {
                return ['kind' => $kind, 'subject' => trim($matches[1])];
            }
        }

        return ['kind' => 'search', 'subject' => $normalised];
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    public function expand(array $terms): array
    {
        $expanded = $terms;

        foreach ($terms as $term) {
            foreach ($this->synonyms[$term] ?? [] as $synonym) {
                foreach (Text::tokens($synonym) as $token) {
                    $expanded[] = $token;
                }
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Terms from the head of the question count for more than terms from its
     * tail.
     *
     * "Where does an order status change, **and what validates it**?" is two
     * questions in one sentence, and the second half is a follow-up. Weighting
     * them equally let `validate` — a rare word, so a high IDF — carry three
     * `FormRequest` classes to the top of a question about the order
     * lifecycle. The subject of the sentence is what the asker is looking at.
     *
     * @return array<string,float>
     */
    private function termWeights(string $query): array
    {
        // The word boundaries are load-bearing: without them `and` splits
        // "demand" and "handle", and `then` splits "strengthen".
        $clauses = preg_split('/\s*(?:,|;|\band\b|\bthen\b)\s*/i', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [$query];

        $weights = [];

        foreach ($clauses as $position => $clause) {
            $weight = $position === 0 ? 1.0 : 0.5;

            foreach (Text::tokens($clause) as $token) {
                $weights[$token] = max($weights[$token] ?? 0.0, $weight);
            }
        }

        return $weights;
    }

    /**
     * @param  list<string>  $expanded
     * @param  list<string>  $original
     * @param  array<string,float>  $weights
     * @return array<int,float>
     */
    private function score(array $expanded, array $original, array $weights = []): array
    {
        $scores = [];
        $covered = [];
        $total = max(1, (int) $this->index['total']);
        $avgdl = max(0.001, (float) $this->index['avgdl']);
        $original = array_flip($original);

        foreach ($expanded as $term) {
            $postings = $this->index['postings'][$term] ?? null;

            // A term nobody wrote: try it as a prefix, which is what makes
            // "settle" find `SettlementService` and "assign" find `LaundryAssigner`.
            if ($postings === null) {
                $postings = $this->prefixPostings($term);
                if ($postings === []) {
                    continue;
                }
                $df = count($postings);
            } else {
                $df = (int) ($this->index['df'][$term] ?? count($postings));
            }

            $idf = log(1 + (($total - $df + 0.5) / ($df + 0.5)));

            // A synonym is a suggestion, not the question. Two-thirds weight
            // keeps it from outranking a literal match; a term from a trailing
            // clause is discounted again on top of that.
            $fieldWeight = isset($original[$term])
                ? ($weights[$term] ?? 1.0)
                : 0.62 * ($weights[$term] ?? 0.8);

            foreach ($postings as [$docIndex, $tf]) {
                $length = (float) ($this->index['docs'][$docIndex]['len'] ?? $avgdl);

                // Length normalisation is left at textbook BM25. Two
                // alternatives were measured against the frozen 20 and both
                // were worse — see the note above the class.
                $denominator = $tf + self::K1 * (1 - self::B + self::B * ($length / $avgdl));

                $scores[$docIndex] = ($scores[$docIndex] ?? 0.0)
                    + $fieldWeight * $idf * (($tf * (self::K1 + 1)) / max(0.001, $denominator));

                if (isset($original[$term])) {
                    $covered[$docIndex][$term] = $weights[$term] ?? 1.0;
                }
            }
        }

        foreach ($scores as $docIndex => $score) {
            $doc = $this->index['docs'][$docIndex] ?? [];

            // **Coverage.** BM25 alone happily puts a document that says
            // "laundry" three times above one that says "laundry" once and
            // "order" once — which is how "which laundry gets the order"
            // answered `LaundryRepository` instead of `LaundryAssigner`.
            // Matching more of what was asked is the stronger signal.
            $matched = array_sum($covered[$docIndex] ?? []);
            $wanted = max(0.001, array_sum(array_map(
                static fn (string $term) => $weights[$term] ?? 1.0,
                array_keys($original)
            )));
            $score *= 0.45 + 0.55 * min(1.0, $matched / $wanted);

            // **Type prior.** A class is where a reader starts; a method of it
            // is a detail they will see when they open the file. A route and a
            // feature are landing points too.
            $score *= match ($doc['type'] ?? '') {
                'class', 'enum', 'trait', 'interface' => 1.15,
                'feature', 'route', 'table', 'command' => 1.08,
                'view', 'permission' => 1.0,
                'method' => 0.82,
                'test' => 0.78,
                default => 0.95,
            };

            // **Layer prior.** Almost every question put to this thing is
            // "where does X happen", and in this codebase the answer to that is
            // a service — the layer contract puts the rules there and nothing
            // else. Tests are pushed down hard for the same reason they used to
            // come first: a test names the concept in its docblock and in half
            // its method names, so it wins on words while being the one file
            // that does *not* implement the behaviour.
            $score *= match ($doc['layer'] ?? '') {
                'service' => 1.30,
                'repository', 'command' => 1.12,
                'model', 'enum' => 1.06,
                'controller', 'api-controller' => 1.04,
                'support', 'trait', 'helper' => 1.02,
                'request', 'view' => 0.92,
                'migration', 'seeder' => 0.80,
                'test', 'browser-test' => 0.55,
                'doc' => 0.70,
                default => 1.0,
            };

            // A file that changes every week is more likely to be the one
            // somebody is asking about than one untouched since it was
            // written. Small, so it orders ties rather than deciding matches.
            $churn = (int) ($doc['churn'] ?? 0);
            if ($churn > 0) {
                $score *= 1 + min(0.12, $churn / 200);
            }

            $scores[$docIndex] = $score;
        }

        return $scores;
    }

    /** @return list<array{0:int,1:float}> */
    private function prefixPostings(string $term): array
    {
        if (mb_strlen($term) < 4) {
            return [];
        }

        $out = [];
        foreach ($this->index['postings'] as $token => $postings) {
            if (str_starts_with((string) $token, $term)) {
                foreach ($postings as $posting) {
                    // Discounted: a prefix hit is weaker evidence than the word.
                    $out[] = [$posting[0], $posting[1] * 0.7];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int,float>  $scores
     * @return array<int,float>
     */
    private function applyExactSymbolBoost(string $query, array $scores): array
    {
        $needle = mb_strtolower(trim($query));
        $candidates = [];

        foreach ([$needle, ltrim($needle, '\\')] as $key) {
            foreach ($this->index['symbols'][$key] ?? [] as $docIndex) {
                $candidates[] = $docIndex;
            }
        }

        // A path typed verbatim is also an exact hit.
        foreach ($this->index['paths'] as $path => $docIndex) {
            if (mb_strtolower($path) === $needle) {
                $candidates[] = $docIndex;
            }
        }

        $best = $scores === [] ? 1.0 : max($scores);

        foreach (array_unique($candidates) as $docIndex) {
            // A node whose *own name* is the query beats one that merely lists
            // it as a secondary symbol. Searching `order_settlements` matched
            // both the table and the `OrderSettlement` model — the model
            // carries its table name as a symbol — and the type prior then
            // handed the answer to the model. Somebody typing a table name
            // wants the table.
            $doc = $this->index['docs'][$docIndex] ?? [];
            $isPrimary = mb_strtolower((string) ($doc['name'] ?? '')) === $needle
                || mb_strtolower((string) ($doc['path'] ?? '')) === $needle;

            $scores[$docIndex] = ($scores[$docIndex] ?? 0.0) + $best * 2 + ($isPrimary ? 40 : 10);
        }

        return $scores;
    }

    /**
     * Symbol lookup on its own, for the callers that want it without ranking.
     *
     * @return list<array<string,mixed>>
     */
    public function symbol(string $name): array
    {
        $out = [];

        foreach ($this->index['symbols'][mb_strtolower($name)] ?? [] as $docIndex) {
            $doc = $this->index['docs'][$docIndex] ?? null;
            if ($doc !== null) {
                $out[] = $doc;
            }
        }

        return $out;
    }
}
