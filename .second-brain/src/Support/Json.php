<?php

namespace Laundo\SecondBrain\Support;

/**
 * Reading and writing the brain's files.
 *
 * `JSON_UNESCAPED_UNICODE` for the same reason the models override `asJson()`:
 * half the docblocks in this codebase are Arabic, and a summary stored as
 * `طلب` is unreadable in a diff and unsearchable by eye.
 */
final class Json
{
    /**
     * `JSON_INVALID_UTF8_SUBSTITUTE` is the safety net, not decoration.
     * `json_encode` returns **false** on one invalid byte anywhere in the
     * structure, so a single mangled docblock in one of 360 files would write
     * an empty graph and report success.
     */
    public const WRITE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function read(string $path, mixed $default = null): mixed
    {
        if (! is_file($path)) {
            return $default;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return $decoded === null ? $default : $decoded;
    }

    public static function write(string $path, mixed $value, bool $pretty = true): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $flags = $pretty
            ? self::WRITE_FLAGS
            : (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        file_put_contents($path, json_encode($value, $flags)."\n");
    }

    /** Compact — used for the index files, which nobody reads by hand. */
    public static function writeCompact(string $path, mixed $value): void
    {
        self::write($path, $value, false);
    }
}
