<?php

namespace Laundo\SecondBrain\Support;

/**
 * Where everything lives, and the one place a path is spelled.
 *
 * Every path the brain reads or writes is relative to the repository root and
 * is stored that way too — a graph carrying `D:\nahr\...` would be useless to
 * anybody else and would churn on every machine that rebuilt it.
 */
final class Paths
{
    private readonly string $root;

    /**
     * The root is normalised here and nowhere else.
     *
     * `relative()` strips the root from a path by string comparison, so a root
     * holding `C:\Users\…\Temp` while the walker produces `C:/Users/…/Temp/app`
     * matches nothing: every path stays absolute, every `accepts()` check fails,
     * and the scanner returns an empty file list without erroring. Normalising
     * at the single point of entry is the fix; normalising at each call site is
     * how one of them gets missed.
     */
    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public static function discover(?string $start = null): self
    {
        $dir = $start ?? __DIR__;

        // Walk up until composer.json and artisan sit together — that pair is
        // the repository root and nothing else in the tree has both.
        for ($i = 0; $i < 10; $i++) {
            if (is_file($dir.'/composer.json') && is_file($dir.'/artisan')) {
                return new self(str_replace('\\', '/', realpath($dir)));
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Could not locate the repository root from '.($start ?? __DIR__));
    }

    public function root(): string
    {
        return $this->root;
    }

    /*
     * `join()` rather than concatenation with a trailing slash. The version
     * that built the path as `rtrim(base.'/cache/'.$sub, '/')` returned
     * `.second-brain/cache` for an empty `$sub` — correct — and
     * `.second-brain/cacheartifacts.json` for a non-empty one, because the
     * inner `rtrim` had already eaten the separator. The parse cache was
     * therefore written **beside** the cache directory, `rm -rf cache/` never
     * removed it, and a rebuild silently reused artifacts from before a fix.
     */
    public function brain(string $sub = ''): string
    {
        return $this->join($this->root.'/.second-brain', $sub);
    }

    public function data(string $sub = ''): string
    {
        return $this->join($this->brain('data'), $sub);
    }

    public function cache(string $sub = ''): string
    {
        return $this->join($this->brain('cache'), $sub);
    }

    private function join(string $base, string $sub): string
    {
        $sub = trim(str_replace('\\', '/', $sub), '/');

        return $sub === '' ? $base : $base.'/'.$sub;
    }

    public function abs(string $relative): string
    {
        return $this->root.'/'.ltrim(str_replace('\\', '/', $relative), '/');
    }

    /** Absolute (or already relative) path expressed against the root. */
    public function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = $this->root.'/';

        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return ltrim($path, '/');
    }

    /**
     * `static` here was a real bug, not a micro-optimisation gone wrong: a
     * `static` inside a method is shared by every instance of the class, so
     * the *second* repository root asked for its config silently received the
     * first one's — **including the `deny` and `deny_files` security lists**.
     * A test fixture and the real tree in one process would have had the
     * fixture reading the repository's rules, or worse the other way round.
     */
    private ?array $config = null;

    public function config(): array
    {
        return $this->config ??= require $this->brain('config.php');
    }

    /**
     * A hash of everything that decides what an artifact contains: the config
     * **and the parsers themselves**.
     *
     * An artifact stores `layer`, `kind`, `module` and `integrations` (derived
     * from `config.php`) and the whole parsed shape of the file (derived from
     * `src/Parse/**` and `FileIndexer`). Keyed on the source file's own bytes
     * alone, none of that is covered.
     *
     * This bit me twice. First a layer rule was edited and every cached
     * artifact kept claiming the old layer. Then Blade extraction was rewritten
     * and the rebuild reused artifacts from the previous parser — the fix
     * looked like it had not worked, and 153 fragments of template residue
     * survived a guard that would have rejected every one of them. A stale
     * artifact does not error; it answers, with last version's parse. Nothing
     * measured on top of it can be trusted, which makes this the one cache key
     * in the system worth being conservative about.
     */
    public function configHash(): string
    {
        $inputs = [$this->brain('config.php')];

        foreach (['src/Parse', 'src/Build'] as $directory) {
            $absolute = $this->brain($directory);
            if (! is_dir($absolute)) {
                continue;
            }

            $files = glob($absolute.'/*.php') ?: [];
            sort($files, SORT_STRING);   // deterministic: glob order is not guaranteed
            $inputs = array_merge($inputs, $files);
        }

        $parts = [];
        foreach ($inputs as $path) {
            $parts[] = is_file($path) ? sha1_file($path) : 'missing';
        }

        return substr(sha1(implode('|', $parts)), 0, 12);
    }
}
