<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laundo\SecondBrain\Build\Indexer;
use Laundo\SecondBrain\Parse\FileScanner;
use Laundo\SecondBrain\Parse\GitHistory;
use Laundo\SecondBrain\Support\Paths;

/**
 * `php artisan second-brain:update` — re-parse only what changed.
 *
 * Changed files come from git: the working tree against `HEAD`, the index, and
 * untracked files, because work in progress is exactly what somebody
 * re-indexing wants picked up. Everything downstream of parsing — the graph,
 * the detectors, the search index — is rebuilt in full from the cached
 * artifacts, which takes a couple of seconds and guarantees the result is
 * identical to a cold build. Patching nodes in place would be faster and would
 * drift, and a graph that has drifted answers confidently and wrongly.
 *
 * Paths git reports are still filtered through the scanner's deny list before
 * anything is opened, so a changed `.env` is a changed file the brain declines
 * to look at.
 */
class SecondBrainUpdate extends Command
{
    protected $signature = 'second-brain:update
                            {--since=HEAD : Git reference to diff against}
                            {--json : Print the manifest as JSON}';

    protected $description = 'Incrementally re-index the Second Brain from git changes';

    public function handle(): int
    {
        require_once base_path('.second-brain/autoload.php');

        $paths = Paths::discover(base_path());
        $scanner = new FileScanner($paths);
        $git = new GitHistory($paths);

        if (! $git->available()) {
            $this->warn('No git repository here — falling back to a full index.');

            return $this->call('second-brain:index', ['--json' => $this->option('json')]);
        }

        $changed = $git->changedSince((string) $this->option('since'));
        $indexable = array_values(array_filter($changed, fn (string $file) => $scanner->accepts($file)));

        $this->line(sprintf(
            '  <fg=gray>· git reports %d changed file(s), %d of them indexable</>',
            count($changed),
            count($indexable)
        ));

        foreach (array_slice($indexable, 0, 20) as $file) {
            $this->line('    <fg=gray>'.$file.'</>');
        }
        if (count($indexable) > 20) {
            $this->line('    <fg=gray>… and '.(count($indexable) - 20).' more</>');
        }

        $manifest = Indexer::make($paths)->run(
            $indexable,
            fn (string $message) => $this->line('  <fg=gray>· '.$message.'</>')
        );

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['changed_files' => $indexable] + $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            'Second Brain updated in %ss — %d file(s) re-parsed, %d reused from cache.',
            $manifest['built_in_seconds'],
            $manifest['files_parsed'],
            $manifest['files_reused_from_cache'],
        ));

        return self::SUCCESS;
    }
}
