<?php

namespace Laundo\SecondBrain\Parse;

use Laundo\SecondBrain\Support\Paths;

/**
 * Which files exist, and which of them the brain is allowed to open.
 *
 * The deny list is applied twice on purpose — once while walking, so a denied
 * directory is never descended into, and once per file, so a path reached some
 * other way (an incremental update handed a list by `git diff`) meets the same
 * rule. `.env` must be unreadable by every route into this class, not only by
 * the one that happens to be used today.
 */
final class FileScanner
{
    /** @var list<string> */
    private array $deny;

    /** @var list<string> */
    private array $denyFiles;

    /** @var list<string> */
    private array $extensions;

    public function __construct(private readonly Paths $paths)
    {
        $config = $paths->config();
        $this->deny = $config['deny'];
        $this->denyFiles = $config['deny_files'];
        $this->extensions = $config['extensions'];
    }

    /**
     * Every indexable file under the configured roots, repo-relative and sorted.
     *
     * Sorted because the graph is written to disk and a set of files that came
     * back in filesystem order would reshuffle the whole thing on another
     * machine — a diff nobody can read is a diff nobody reviews.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $files = [];

        foreach ($this->paths->config()['roots'] as $root) {
            $absolute = $this->paths->abs($root);
            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                    function (\SplFileInfo $current): bool {
                        $relative = $this->paths->relative($current->getPathname());

                        return ! $this->isDenied($relative);
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile()) {
                    continue;
                }

                $relative = $this->paths->relative($file->getPathname());
                if ($this->accepts($relative)) {
                    $files[] = $relative;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Resolve `.` and `..` lexically, and refuse anything that climbs out of
     * the repository.
     *
     * This is what makes the deny list a rule rather than a suggestion.
     * Without it the checks below are pure string prefixes, so
     * `app/../vendor/autoload.php` does not start with `vendor/` and sails
     * through — as does `app/../../anything`. Nothing reaches `accepts()` with
     * a `..` today (the walker emits real paths and `git diff` never does), so
     * this was latent; a deny list that `..` walks around is not a deny list,
     * and the next caller will not know that.
     *
     * Returns null when the path escapes the root or is otherwise unusable.
     */
    private function normalise(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        // A null byte truncates the path at the OS layer, so a name carrying
        // one is refused rather than cleaned.
        if (str_contains($path, "\0")) {
            return null;
        }

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out === []) {
                    return null;   // climbs above the repository root
                }
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }

        return $out === [] ? null : implode('/', $out);
    }

    /** The single gate. Anything the brain opens has passed through here. */
    public function accepts(string $relative): bool
    {
        $relative = $this->normalise($relative);

        if ($relative === null || $this->isDenied($relative)) {
            return false;
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (! in_array($extension, $this->extensions, true)) {
            return false;
        }

        // Under a root? An incremental update may be handed a path from
        // anywhere in the repository.
        foreach ($this->paths->config()['roots'] as $root) {
            if ($relative === $root || str_starts_with($relative, rtrim($root, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    public function isDenied(string $relative): bool
    {
        $relative = $this->normalise($relative);

        if ($relative === null) {
            return true;   // unresolvable or outside the root: denied
        }

        foreach ($this->deny as $denied) {
            if ($relative === $denied || str_starts_with($relative, rtrim($denied, '/').'/')) {
                return true;
            }
        }

        $basename = basename($relative);
        foreach ($this->denyFiles as $pattern) {
            if (preg_match($pattern, $basename) === 1) {
                return true;
            }
        }

        return false;
    }

    public function hash(string $relative): string
    {
        $absolute = $this->paths->abs($relative);

        return is_file($absolute) ? (string) sha1_file($absolute) : '';
    }

    public function read(string $relative): string
    {
        if (! $this->accepts($relative)) {
            throw new \RuntimeException("Refused to read [{$relative}] — it is on the deny list.");
        }

        // Read the *normalised* path, never the caller's spelling — otherwise
        // the gate checks one path and the filesystem opens another.
        $absolute = $this->paths->abs((string) $this->normalise($relative));

        return is_file($absolute) ? (string) file_get_contents($absolute) : '';
    }
}
