<?php

namespace Laundo\SecondBrain\Build;

use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Graph\Vocabulary;
use Laundo\SecondBrain\Parse\FileScanner;

/**
 * Is the built brain still telling the truth?
 *
 * A stale index is the failure mode that matters: it does not error, it answers
 * — with last week's architecture. So `doctor` checks the things that would be
 * silently wrong, and says plainly which ones are real problems and which are
 * facts about the repository.
 *
 * It also enforces the security promise rather than trusting it: every indexed
 * path is re-tested against the deny list, and the index is scanned for the
 * shapes a leaked secret takes.
 */
final class Doctor
{
    /** Every check this class performs, in the order it performs them. */
    public const CHECKS = [
        'staleness',
        'secrets',
        'vocabulary',
        'routes',
        'communities',
        'features',
        'models',
    ];

    public function __construct(private readonly Brain $brain) {}

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $problems = [];
        $notes = [];

        $manifest = $this->brain->manifest();
        $graph = $this->brain->graph();
        $scanner = new FileScanner($this->brain->paths());

        // 1. Staleness — files on disk the brain has never seen, and files in
        //    the brain that are no longer on disk.
        $onDisk = $scanner->all();
        $indexed = [];
        foreach ($graph->ofType('file') as $file) {
            $indexed[$file['path']] = true;
        }

        $missing = array_values(array_diff($onDisk, array_keys($indexed)));
        $vanished = array_values(array_filter(
            array_keys($indexed),
            fn (string $path) => ! is_file($this->brain->paths()->abs($path))
        ));

        if ($missing !== []) {
            $problems[] = [
                'check' => 'staleness',
                'detail' => count($missing).' file(s) on disk are not in the brain',
                'examples' => array_slice($missing, 0, 8),
                'fix' => 'php .second-brain/bin/brain.php update',
            ];
        }
        if ($vanished !== []) {
            $problems[] = [
                'check' => 'staleness',
                'detail' => count($vanished).' indexed file(s) no longer exist',
                'examples' => array_slice($vanished, 0, 8),
                'fix' => 'php .second-brain/bin/brain.php index',
            ];
        }

        // 2. Secrets. The deny list is a promise; this is the audit of it.
        $leaked = [];
        foreach (array_keys($indexed) as $path) {
            if ($scanner->isDenied($path)) {
                $leaked[] = $path;
            }
        }
        if ($leaked !== []) {
            $problems[] = [
                'check' => 'secrets',
                'detail' => 'denied paths reached the graph',
                'examples' => array_slice($leaked, 0, 8),
                'fix' => 'A path passed the walker but fails the deny rule — the two must agree.',
            ];
        }

        $secretShapes = $this->scanForSecretShapes();
        if ($secretShapes !== []) {
            $problems[] = [
                'check' => 'secrets',
                'detail' => 'values that look like credentials are present in the index',
                'examples' => array_slice($secretShapes, 0, 5),
                'fix' => 'Add the file to `deny` or `deny_files` in .second-brain/config.php and re-index.',
            ];
        }

        // 3. Vocabulary — an edge type nothing declared.
        $unknownEdges = [];
        foreach ($graph->edges() as $edge) {
            if (! in_array($edge['type'], Vocabulary::EDGES, true)) {
                $unknownEdges[$edge['type']] = true;
            }
        }
        if ($unknownEdges !== []) {
            $problems[] = [
                'check' => 'vocabulary',
                'detail' => 'edge types not declared in Vocabulary::EDGES',
                'examples' => array_keys($unknownEdges),
                'fix' => 'Declare the type or stop producing it.',
            ];
        }

        // 4. Routes — the degraded mode, which must be visible.
        if (($manifest['route_source'] ?? null) !== 'artisan') {
            $problems[] = [
                'check' => 'routes',
                'detail' => 'routes came from the static reader, not from artisan — middleware and permissions are missing',
                'examples' => [(string) ($manifest['route_error'] ?? 'unknown reason')],
                'fix' => 'Make `php artisan route:list` runnable, then re-index.',
            ];
        }

        // 5. Orphans and unplaced modules. Facts, not faults — but a reader
        //    should see them rather than discover them mid-task.
        $communities = $this->brain->architecture('communities')['communities'];
        foreach ($communities as $community) {
            if ($community['slug'] === 'unplaced' && $community['modules'] !== []) {
                $notes[] = 'Modules no community claimed: '.implode(', ', $community['modules']);
            }
            if (($community['inferred_modules'] ?? []) !== []) {
                $notes[] = $community['slug'].' gained '.implode(', ', $community['inferred_modules']).' by inference, not from config/menu.php';
            }
        }

        // 6. Features with no reachable entry point. Real and worth knowing:
        //    CLAUDE.md says four order statuses have no endpoint driving them.
        $stranded = [];
        foreach ($this->brain->features() as $feature) {
            if ($feature['entry_points'] === [] && $feature['kind'] === 'service') {
                $stranded[] = $feature['label'];
            }
        }
        if ($stranded !== []) {
            // Say how many are listed, not how many exist and then list ten of
            // them — the note read "11 domain service(s) …" above a list of 10.
            $shown = array_slice($stranded, 0, 10);
            $notes[] = count($stranded).' domain service(s) no route or command reaches'
                .(count($stranded) > count($shown) ? ', first '.count($shown) : '').': '
                .implode(', ', $shown);
        }

        // 7. Models whose table the migrations never create.
        $tables = [];
        foreach ($graph->ofType('table') as $table) {
            $tables[$table['name']] = true;
        }
        $unmapped = [];
        foreach ($graph->nodes() as $node) {
            if (($node['is_model'] ?? false) === true && ($node['table'] ?? null) !== null
                && ! isset($tables[$node['table']])) {
                $unmapped[] = $node['fqcn'].' → '.$node['table'];
            }
        }
        if ($unmapped !== []) {
            $notes[] = 'Model(s) whose table no migration creates (an aggregate or a renamed table): '
                .implode(', ', array_slice($unmapped, 0, 8));
        }

        return [
            'healthy' => $problems === [],
            'built_at' => $manifest['built_at'] ?? null,
            // Named rather than counted: a hardcoded 7 is a number that goes
            // wrong the first time somebody adds or removes a check.
            'checks_run' => self::CHECKS,
            'problems' => $problems,
            'notes' => $notes,
            'counts' => [
                'files_on_disk' => count($onDisk),
                'files_indexed' => count($indexed),
                'nodes' => $manifest['nodes'] ?? 0,
                'edges' => $manifest['edges'] ?? 0,
            ],
        ];
    }

    /**
     * The shapes a credential takes, looked for in every string the index
     * carries. Deliberately narrow: a false positive here costs somebody an
     * investigation, and the deny list is the real defence.
     *
     * @return list<string>
     */
    private function scanForSecretShapes(): array
    {
        $patterns = [
            'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
            'Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
            'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            'Laravel app key' => '/\bbase64:[A-Za-z0-9+\/]{40,}={0,2}\b/',
            'bearer token' => '/\b(?:sk|pk)_(?:live|test)_[A-Za-z0-9]{16,}\b/',
        ];

        $found = [];
        $data = $this->brain->paths()->data();

        // The parse cache is scanned too. It is gitignored, so a credential in
        // it would not be shared — but it is still a file on somebody's disk
        // holding something the deny list was meant to keep out, and the point
        // of this check is to notice that rather than to protect the commit.
        $files = [
            'graph/nodes.json',
            'index/lexical.json',
            'architecture/features.json',
            '../cache/artifacts.json',
        ];

        foreach ($files as $file) {
            $path = $data.'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);

            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $found[] = $label.' in '.$file;
                }
            }
        }

        return $found;
    }
}
