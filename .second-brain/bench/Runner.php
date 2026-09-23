<?php

namespace Laundo\SecondBrain\Bench;

use Laundo\SecondBrain\Brain;

/**
 * The retrieval benchmark: a frozen task set in, measured numbers out.
 *
 * Separated from the task data on purpose. `tasks-known.php` is frozen and
 * this file is not — the runner may gain a metric, but a change here can never
 * quietly reword a task or widen a truth set.
 *
 * ## How a returned file is judged
 *
 * Mechanically, and identically for every task:
 *
 * - **truth** — one of the files that actually implements the task
 * - **neighbour** — same module as a truth file: context a developer would
 *   accept, neither a hit nor noise
 * - **noise** — neither; counted as a false positive
 *
 * The neighbour bucket exists because a flat right/wrong split would score
 * `OrderRepository` returned for an order task the same as `SmsSender`, and
 * those are not the same mistake.
 *
 * ## Two context numbers, and the second is the real one
 *
 * `reduction_optimistic` counts only the tasks the brain got right.
 * `reduction_honest` charges a task whose truth never appeared for the
 * grep-and-read hunt it would force — the brain's cost *plus* the naive cost.
 * A system that answers confidently and wrongly is worse than no system, and
 * only the second number says so.
 */
final class Runner
{
    private const CHARS_PER_TOKEN = 4;

    /** A realistic ceiling on how many files somebody reads before giving up. */
    private const NAIVE_READ_CAP = 12;

    /** @var array<string,int> */
    private array $sizes = [];

    /** @var list<string> */
    private array $corpus = [];

    public function __construct(private readonly Brain $brain)
    {
        $this->loadCorpus();
    }

    /**
     * @param  list<array{0:string,1:string,2:list<string>,3:list<string>}>  $tasks
     * @return array{rows:list<array<string,mixed>>,summary:array<string,mixed>}
     */
    public function run(array $tasks): array
    {
        $rows = [];

        foreach ($tasks as [$category, $query, $truth, $grep]) {
            $rows[] = $this->measure($category, $query, $truth, $grep);
        }

        return ['rows' => $rows, 'summary' => $this->summarise($rows)];
    }

    /**
     * @param  list<string>  $truth
     * @param  list<string>  $grep
     * @return array<string,mixed>
     */
    private function measure(string $category, string $query, array $truth, array $grep): array
    {
        $answer = $this->brain->search($query, 5);
        $json = (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $primary = $this->primaryPaths($answer);

        // `then_read` is scored separately from the ranked hits. Folding it
        // into the ranking would let an expansion of six files paper over a
        // bad rank-1, which is exactly the thing being measured.
        $thenRead = $this->pathsOf($answer, 'then_read');

        $rank = null;
        foreach ($primary as $i => $path) {
            if (in_array($path, $truth, true)) {
                $rank = $i + 1;
                break;
            }
        }

        $truthModules = array_unique(array_map([$this, 'moduleOf'], $truth));

        $classes = [];
        foreach ($primary as $path) {
            $classes[$path] = in_array($path, $truth, true)
                ? 'truth'
                : (in_array($this->moduleOf($path), $truthModules, true) ? 'neighbour' : 'noise');
        }

        $foundPrimary = array_values(array_intersect($primary, $truth));
        $foundWithExpansion = array_values(array_intersect(array_merge($primary, $thenRead), $truth));

        // ---- context: the naive hunt against the brain's answer
        $matched = [];
        foreach ($this->corpus as $path) {
            $source = @file_get_contents($this->brain->paths()->abs($path));
            if ($source === false) {
                continue;
            }
            foreach ($grep as $needle) {
                if (stripos($source, $needle) !== false) {
                    $matched[] = $path;
                    break;
                }
            }
        }

        $naiveRead = array_slice($matched, 0, self::NAIVE_READ_CAP);
        $naiveChars = array_sum(array_map(fn ($p) => $this->sizes[$p] ?? 0, $naiveRead))
            + count($matched) * 90;   // one grep output line per hit

        $top3 = array_slice($primary, 0, 3);
        $brainChars = strlen($json) + array_sum(array_map(fn ($p) => $this->sizes[$p] ?? 0, $top3));

        return [
            'category' => $category,
            'query' => $query,
            'answered_as' => $answer['answered_as'] ?? '',
            'intent' => $answer['intent'] ?? '',
            'primary' => $primary,
            'then_read' => $thenRead,
            'classes' => $classes,
            'rank' => $rank,
            'truth_total' => count($truth),
            'truth_found' => count($foundPrimary),
            'truth_found_with_expansion' => count($foundWithExpansion),
            'missed' => array_values(array_diff($truth, $primary)),
            'missed_after_expansion' => array_values(array_diff($truth, $primary, $thenRead)),
            'expanded_with' => $answer['expanded_with'] ?? [],
            'features' => array_map(
                static fn (array $f) => $f['label'] ?? '',
                $answer['suggested_features'] ?? []
            ),
            'naive_files' => count($matched),
            'naive_chars' => $naiveChars,
            'brain_chars' => $brainChars,
            'response_chars' => strlen($json),
        ];
    }

    /**
     * The files an answer points at, whatever shape the answer takes.
     *
     * **Instrumentation, not ground truth.** A ranked answer puts them in
     * `results`; a `permission_gate` answer puts them in `controllers` and
     * `declared_in`; a dependency answer puts them on its edge lists. Reading
     * only `results` scored a permission question 0% while it was returning
     * precisely the two files the frozen truth names — measuring the response
     * shape rather than whether the brain found the code.
     *
     * No truth set, task wording or classification rule changes here. This
     * only teaches the harness to read the other envelopes.
     *
     * @param  array<string,mixed>  $answer
     * @return list<string>
     */
    private function primaryPaths(array $answer): array
    {
        if (isset($answer['results'])) {
            return $this->pathsOf($answer, 'results');
        }

        $out = [];

        // Structured answers: plain string lists of paths.
        foreach (['controllers', 'declared_in'] as $key) {
            foreach ($answer[$key] ?? [] as $path) {
                if (is_string($path) && ! in_array($path, $out, true)) {
                    $out[] = $path;
                }
            }
        }

        // Graph answers: lists of nodes carrying a `path`.
        foreach (['depends_on', 'depended_on_by', 'related'] as $key) {
            foreach ($this->pathsOf($answer, $key) as $path) {
                if (! in_array($path, $out, true)) {
                    $out[] = $path;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $answer
     * @return list<string>
     */
    private function pathsOf(array $answer, string $key): array
    {
        $out = [];

        foreach ($answer[$key] ?? [] as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? $entry['file'] ?? null) : null;
            if ($path !== null && ! in_array($path, $out, true)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function summarise(array $rows): array
    {
        $n = max(1, count($rows));
        $top = [1 => 0, 3 => 0, 5 => 0];
        $returned = $noise = $neighbour = 0;
        $truthTotal = $truthFound = $truthWithExpansion = 0;
        $naiveSum = $brainSum = $honestSum = $responseSum = 0;
        $thenReadSum = 0;
        $byCategory = [];

        foreach ($rows as $r) {
            foreach ([1, 3, 5] as $k) {
                if ($r['rank'] !== null && $r['rank'] <= $k) {
                    $top[$k]++;
                }
            }

            $returned += count($r['primary']);
            $thenReadSum += count($r['then_read']);
            $noise += count(array_filter($r['classes'], static fn ($c) => $c === 'noise'));
            $neighbour += count(array_filter($r['classes'], static fn ($c) => $c === 'neighbour'));
            $truthTotal += $r['truth_total'];
            $truthFound += $r['truth_found'];
            $truthWithExpansion += $r['truth_found_with_expansion'];
            $naiveSum += $r['naive_chars'];
            $brainSum += $r['brain_chars'];
            $honestSum += $r['rank'] === null ? ($r['brain_chars'] + $r['naive_chars']) : $r['brain_chars'];
            $responseSum += $r['response_chars'];

            $c = $r['category'];
            $byCategory[$c]['n'] = ($byCategory[$c]['n'] ?? 0) + 1;
            foreach ([1, 3, 5] as $k) {
                $byCategory[$c]['top'.$k] = ($byCategory[$c]['top'.$k] ?? 0)
                    + (($r['rank'] !== null && $r['rank'] <= $k) ? 1 : 0);
            }
            $byCategory[$c]['truth_total'] = ($byCategory[$c]['truth_total'] ?? 0) + $r['truth_total'];
            $byCategory[$c]['truth_found'] = ($byCategory[$c]['truth_found'] ?? 0) + $r['truth_found'];
            $byCategory[$c]['truth_expanded'] = ($byCategory[$c]['truth_expanded'] ?? 0) + $r['truth_found_with_expansion'];
            $byCategory[$c]['returned'] = ($byCategory[$c]['returned'] ?? 0) + count($r['primary']);
            $byCategory[$c]['noise'] = ($byCategory[$c]['noise'] ?? 0)
                + count(array_filter($r['classes'], static fn ($c2) => $c2 === 'noise'));
        }

        ksort($byCategory);

        return [
            'tasks' => count($rows),
            'top1' => round($top[1] / $n * 100, 1),
            'top3' => round($top[3] / $n * 100, 1),
            'top5' => round($top[5] / $n * 100, 1),
            'truth_total' => $truthTotal,
            'truth_found' => $truthFound,
            'truth_found_with_expansion' => $truthWithExpansion,
            'recall' => round($truthFound / max(1, $truthTotal) * 100, 1),
            'recall_with_expansion' => round($truthWithExpansion / max(1, $truthTotal) * 100, 1),
            'false_negative_rate' => round(($truthTotal - $truthFound) / max(1, $truthTotal) * 100, 1),
            'false_negative_rate_with_expansion' => round(($truthTotal - $truthWithExpansion) / max(1, $truthTotal) * 100, 1),
            'returned' => $returned,
            'neighbour' => $neighbour,
            'noise' => $noise,
            'false_positive_rate' => round($noise / max(1, $returned) * 100, 1),
            'avg_files_returned' => round($returned / $n, 2),
            'avg_then_read' => round($thenReadSum / $n, 2),
            'avg_response_chars' => (int) round($responseSum / $n),
            'avg_response_tokens' => (int) round($responseSum / $n / self::CHARS_PER_TOKEN),
            'naive_tokens' => (int) round($naiveSum / self::CHARS_PER_TOKEN),
            'brain_tokens' => (int) round($brainSum / self::CHARS_PER_TOKEN),
            'honest_tokens' => (int) round($honestSum / self::CHARS_PER_TOKEN),
            'reduction_optimistic' => round((1 - $brainSum / max(1, $naiveSum)) * 100, 1),
            'reduction_honest' => round((1 - $honestSum / max(1, $naiveSum)) * 100, 1),
            'by_category' => $byCategory,
        ];
    }

    private function moduleOf(string $path): string
    {
        if (preg_match('#^app/Modules/([^/]+)/#', $path, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#^resources/views/admin/([^/]+)/#', $path, $m) === 1) {
            return 'view:'.$m[1];
        }
        if (str_starts_with($path, 'app/Http/Controllers/Api/')) {
            return 'Api';
        }
        if (str_starts_with($path, 'database/migrations/')) {
            return 'Database';
        }

        return dirname($path);
    }

    /**
     * Every PHP and Blade file a naive `grep` would search. Deliberately wider
     * than the brain's own roots — the point is to price what somebody would
     * actually have to wade through.
     */
    private function loadCorpus(): void
    {
        $root = $this->brain->paths()->root();

        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $current) use ($root): bool {
                $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($root) + 1));

                foreach (['vendor', 'node_modules', 'storage', '.git', '.second-brain', 'public'] as $skip) {
                    if ($relative === $skip || str_starts_with($relative, $skip.'/')) {
                        return false;
                    }
                }

                return true;
            }
        ));

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (str_ends_with($relative, '.php')) {
                $this->sizes[$relative] = $file->getSize();
                $this->corpus[] = $relative;
            }
        }

        sort($this->corpus);
    }
}
