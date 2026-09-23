<?php

/*
|--------------------------------------------------------------------------
| The Second Brain command line
|--------------------------------------------------------------------------
|
| Runs without Laravel and without a database, on purpose: `php artisan` cannot
| boot on a machine where MySQL is not running (AppServiceProvider reads the
| `languages` table at boot), and a brain that only rebuilds where the database
| happens to be up is a brain that goes stale.
|
|   php .second-brain/bin/brain.php index
|   php .second-brain/bin/brain.php update
|   php .second-brain/bin/brain.php search "where is the delivery fee calculated"
|
| `php artisan second-brain:index` is the same thing with a Laravel wrapper,
| for the times artisan is already to hand.
|
*/

require __DIR__.'/../autoload.php';

use Laundo\SecondBrain\Bench\Benchmark;
use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Build\Indexer;
use Laundo\SecondBrain\Export\Exporter;
use Laundo\SecondBrain\Parse\GitHistory;
use Laundo\SecondBrain\Support\Paths;

$paths = Paths::discover(__DIR__);
$arguments = array_slice($argv, 1);
$command = array_shift($arguments) ?: 'help';

$options = [];
$positional = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--')) {
        $parts = explode('=', substr($argument, 2), 2);
        $options[$parts[0]] = $parts[1] ?? true;
    } else {
        $positional[] = $argument;
    }
}

$json = isset($options['json']);

/** Print a structure as JSON, or as something a person can read. */
$render = function (mixed $value, int $indent = 0) use (&$render, $json): void {
    if ($json) {
        echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";

        return;
    }

    $pad = str_repeat('  ', $indent);

    if (! is_array($value)) {
        echo $pad, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, "\n";

        return;
    }

    foreach ($value as $key => $item) {
        if (is_array($item)) {
            if ($item === []) {
                continue;
            }
            echo $pad, is_int($key) ? '-' : $key.':', "\n";
            $render($item, $indent + 1);

            continue;
        }
        echo $pad, is_int($key) ? '- ' : $key.': ', is_bool($item) ? ($item ? 'true' : 'false') : (string) $item, "\n";
    }
};

$log = static function (string $message) use ($json): void {
    if (! $json) {
        fwrite(STDERR, '  · '.$message."\n");
    }
};

$needsBrain = static function (Brain $brain): void {
    if (! $brain->isBuilt()) {
        fwrite(STDERR, "The brain has not been built. Run:\n\n  php .second-brain/bin/brain.php index\n\n");
        exit(1);
    }
};

switch ($command) {
    case 'index':
        $manifest = Indexer::make($paths)->run(null, $log);
        $render($manifest);
        break;

    case 'update':
        $git = new GitHistory($paths);
        $changed = $git->changedSince($options['since'] ?? 'HEAD');

        // Files git reports that the brain is allowed to look at. A change to
        // `.env` is a change git reports and the brain must not follow.
        $scanner = new Laundo\SecondBrain\Parse\FileScanner($paths);
        $relevant = array_values(array_filter($changed, static fn (string $f) => $scanner->accepts($f)));

        if (! $json) {
            fwrite(STDERR, '  · git reports '.count($changed).' changed file(s), '.count($relevant)." indexable\n");
        }

        $manifest = Indexer::make($paths)->run($relevant, $log);
        $render(['changed_files' => $relevant] + $manifest);
        break;

    case 'search':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render($brain->search(
            implode(' ', $positional),
            (int) ($options['limit'] ?? 10),
            $options['type'] ?? null,
            $options['module'] ?? null,
        ));
        break;

    case 'feature':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render($brain->feature(implode(' ', $positional)));
        break;

    case 'module':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render($brain->module(implode(' ', $positional)));
        break;

    case 'deps':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render($brain->dependencies(
            implode(' ', $positional),
            $options['direction'] ?? 'both',
            (int) ($options['depth'] ?? 1),
        ));
        break;

    case 'related':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render($brain->related(implode(' ', $positional), (int) ($options['limit'] ?? 12)));
        break;

    case 'arch':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render($brain->architecture($positional[0] ?? null));
        break;

    case 'export':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $written = (new Exporter($brain))->export($options['format'] ?? 'all');
        $render(['written' => $written]);
        break;

    case 'visualize':
    case 'visualise':
        $brain = new Brain($paths);
        $needsBrain($brain);

        $viewer = (new Laundo\SecondBrain\Export\ViewerData($brain))->write();
        $entry = $paths->brain('viewer/index.html');

        $render([
            'nodes' => $viewer['nodes'],
            'edges' => $viewer['edges'],
            'payload_kb' => (int) round($viewer['bytes'] / 1024),
            'written' => [$viewer['json'], $viewer['js']],
            'open' => $entry,
            'note' => 'Open that file in a browser. It reads the generated data directly, so no server is needed.',
        ]);
        break;

    case 'bench':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render((new Benchmark($brain))->run());
        break;

    case 'doctor':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render((new Laundo\SecondBrain\Build\Doctor($brain))->run());
        break;

    case 'stats':
        $brain = new Brain($paths);
        $needsBrain($brain);
        $render($brain->manifest());
        break;

    case 'help':
    default:
        echo <<<'TEXT'
Laundo Second Brain — a queryable map of this codebase.

  index                       Build everything from scratch (safe to repeat).
  update [--since=HEAD]       Re-parse only what git says changed.
  search "<question>"         Find where something lives. --limit --type --module
  feature <id|group>          One capability: entry points, files, tables, tests.
  module <Name>               One module: layers, models, routes, dependencies.
  deps <symbol>               What it needs / what needs it. --direction --depth
  related <symbol|path>       Files that travel with this one.
  arch [section]              overview | communities | modules | database | routes | git
  export [--format=mermaid|html|json|viewer|all]
  visualize                   Build the interactive graph viewer and print its path.
  bench                       Token cost, with the brain and without it.
  doctor                      Check the built brain for gaps and stale entries.
  stats                       The manifest.

  --json on any command for machine-readable output.

TEXT;
        break;
}
