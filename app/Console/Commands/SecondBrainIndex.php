<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laundo\SecondBrain\Build\Indexer;
use Laundo\SecondBrain\Support\Paths;

/**
 * `php artisan second-brain:index` — a wrapper, not an implementation.
 *
 * The indexer deliberately does not depend on Laravel: `php artisan` cannot
 * boot on a machine where MySQL is down, because `AppServiceProvider::boot()`
 * reads the `languages` table, and a code map that only rebuilds when the
 * database happens to be up is a code map that goes stale. So the real entry
 * point is `php .second-brain/bin/brain.php index`, and this exists because
 * artisan is often the console already open.
 *
 * Nothing about the application is touched. The command reads source files and
 * writes JSON under `.second-brain/`.
 */
class SecondBrainIndex extends Command
{
    protected $signature = 'second-brain:index
                            {--json : Print the manifest as JSON}';

    protected $description = 'Rebuild the codebase Second Brain (safe to run repeatedly)';

    public function handle(): int
    {
        require_once base_path('.second-brain/autoload.php');

        $paths = Paths::discover(base_path());

        $manifest = Indexer::make($paths)->run(
            null,
            fn (string $message) => $this->line('  <fg=gray>· '.$message.'</>')
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Second Brain rebuilt in '.$manifest['built_in_seconds'].'s');

        $this->table(['', ''], [
            ['files scanned', $manifest['files_scanned']],
            ['files parsed', $manifest['files_parsed'].' ('.$manifest['files_reused_from_cache'].' reused from cache)'],
            ['nodes', $manifest['nodes']],
            ['edges', $manifest['edges']],
            ['communities', $manifest['communities']],
            ['modules', $manifest['modules']],
            ['features', $manifest['features']],
            ['routes', $manifest['routes'].' (from '.$manifest['route_source'].')'],
            ['tables', $manifest['tables']],
            ['models', $manifest['models']],
            ['tests', $manifest['tests']],
        ]);

        if ($manifest['route_source'] !== 'artisan') {
            $this->warn('Routes came from the static reader, so middleware and permissions are missing: '.$manifest['route_error']);
        }

        return self::SUCCESS;
    }
}
