<?php

namespace Laundo\SecondBrain\Parse;

use Laundo\SecondBrain\Support\Paths;

/**
 * The parts of git history that help somebody decide where to look.
 *
 * Not a changelog — the repository has one. Three signals only, and each has
 * to earn a place in a search result:
 *
 * - **churn**: how often a file has changed. A file touched thirty times is
 *   where the work happens; one touched twice since it was written is settled.
 * - **recency**: days since a file last changed, which is the difference
 *   between "this is the current implementation" and "this is the one that was
 *   replaced".
 * - **co-change**: files that keep changing in the same commit. This finds
 *   couplings no import expresses — a Blade partial and the controller that
 *   renders it, a mobile doc and the endpoint it documents.
 *
 * Bounded to the last N commits so a repository with ten thousand of them does
 * not turn indexing into a git operation.
 */
final class GitHistory
{
    public function __construct(
        private readonly Paths $paths,
        private readonly int $commitLimit = 400,
    ) {}

    public function available(): bool
    {
        return is_dir($this->paths->abs('.git'));
    }

    /**
     * @return array{
     *     available:bool,
     *     head:?string,
     *     commits:int,
     *     churn:array<string,int>,
     *     last_changed:array<string,string>,
     *     co_change:array<string,list<array{path:string,count:int}>>,
     *     recent:list<array{sha:string,date:string,subject:string,files:int}>,
     * }
     */
    public function collect(FileScanner $scanner): array
    {
        $empty = [
            'available' => false, 'head' => null, 'commits' => 0,
            'churn' => [], 'last_changed' => [], 'co_change' => [], 'recent' => [],
        ];

        if (! $this->available()) {
            return $empty;
        }

        // One call, one pass. `--name-only` with a record separator we can
        // split on keeps this to a single process even at 400 commits.
        $log = $this->git([
            'log', '--no-merges', '--date=short',
            '--pretty=format:%x01%H%x02%ad%x02%s', '--name-only',
            '-n', (string) $this->commitLimit,
        ]);

        if ($log === null) {
            return $empty;
        }

        $churn = [];
        $lastChanged = [];
        $pairs = [];
        $recent = [];
        $commits = 0;

        foreach (explode("\x01", $log) as $chunk) {
            $chunk = trim($chunk, "\n");
            if ($chunk === '') {
                continue;
            }

            $lines = explode("\n", $chunk);
            $header = explode("\x02", array_shift($lines));
            if (count($header) < 3) {
                continue;
            }

            [$sha, $date, $subject] = $header;
            $commits++;

            $files = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || ! $scanner->accepts($line)) {
                    continue;
                }
                $files[] = $line;

                $churn[$line] = ($churn[$line] ?? 0) + 1;
                $lastChanged[$line] ??= $date;   // the log is newest first
            }

            if ($recent === [] || count($recent) < 15) {
                $recent[] = [
                    'sha' => substr($sha, 0, 8),
                    'date' => $date,
                    'subject' => $subject,
                    'files' => count($files),
                ];
            }

            // A commit touching half the repository ("update", a formatter run)
            // says nothing about coupling and would pair everything with
            // everything. Twelve is where a deliberate change stops.
            if (count($files) < 2 || count($files) > 12) {
                continue;
            }

            sort($files);
            foreach ($files as $i => $a) {
                foreach (array_slice($files, $i + 1) as $b) {
                    $key = $a."\x00".$b;
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
        }

        arsort($churn);

        return [
            'available' => true,
            'head' => $this->git(['rev-parse', 'HEAD']),
            'commits' => $commits,
            'churn' => $churn,
            'last_changed' => $lastChanged,
            'co_change' => $this->topCoChanges($pairs),
            'recent' => $recent,
        ];
    }

    /**
     * @param  array<string,int>  $pairs
     * @return array<string,list<array{path:string,count:int}>>
     */
    private function topCoChanges(array $pairs): array
    {
        $byFile = [];

        foreach ($pairs as $key => $count) {
            if ($count < 2) {
                continue;   // one shared commit is a coincidence
            }
            [$a, $b] = explode("\x00", $key);
            $byFile[$a][] = ['path' => $b, 'count' => $count];
            $byFile[$b][] = ['path' => $a, 'count' => $count];
        }

        foreach ($byFile as $path => $partners) {
            usort($partners, static fn ($x, $y) => $y['count'] <=> $x['count']);
            $byFile[$path] = array_slice($partners, 0, 6);
        }

        ksort($byFile);

        return $byFile;
    }

    /**
     * Files changed against a reference, for the incremental update. Staged,
     * unstaged and untracked, because work in progress is exactly what somebody
     * re-indexing wants picked up.
     *
     * @return list<string>
     */
    public function changedSince(string $reference = 'HEAD'): array
    {
        if (! $this->available()) {
            return [];
        }

        // `Process::run` takes an argument array and never a shell, so there
        // is no command injection here — but a value beginning with `-` would
        // still be read by git as an *option* rather than as a revision. The
        // reference comes from a `--since` flag somebody typed, so this is
        // belt and braces rather than a hole being closed; the shape of a
        // revision is well defined and anything else is a typo worth refusing.
        if (preg_match('#^[A-Za-z0-9._/\^~@{}-]{1,200}$#', $reference) !== 1 || str_starts_with($reference, '-')) {
            throw new \InvalidArgumentException("Not a usable git reference: [{$reference}]");
        }

        $changed = [];

        foreach ([
            ['diff', '--name-only', $reference],
            ['diff', '--name-only', '--cached', $reference],
            ['ls-files', '--others', '--exclude-standard'],
        ] as $arguments) {
            $output = $this->git($arguments);
            if ($output === null) {
                continue;
            }
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $changed[] = $line;
                }
            }
        }

        return array_values(array_unique($changed));
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): ?string
    {
        $result = \Laundo\SecondBrain\Support\Process::run(
            array_merge(['git'], $arguments),
            $this->paths->root(),
        );

        return $result['status'] === 0 ? trim($result['stdout']) : null;
    }
}
