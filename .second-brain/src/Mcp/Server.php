<?php

namespace Laundo\SecondBrain\Mcp;

use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Build\Indexer;
use Laundo\SecondBrain\Parse\FileScanner;
use Laundo\SecondBrain\Parse\GitHistory;

/**
 * MCP over stdio, spoken directly.
 *
 * No SDK. The stdio transport is newline-delimited JSON-RPC 2.0 and the surface
 * a tool server needs is four methods — `initialize`, `tools/list`,
 * `tools/call`, `ping`. Adding an npm or composer package to write forty lines
 * of framing would be a dependency in a project whose brief is to add none, and
 * would put the brain's availability behind somebody remembering to install it.
 *
 * Two rules this file exists to enforce:
 *
 * 1. **stdout carries JSON-RPC and nothing else.** One stray `echo`, one PHP
 *    notice, and the client's parser desynchronises — which presents as the
 *    server being "broken" with no error anywhere. Diagnostics go to stderr,
 *    warnings are routed there too, and the display of errors is turned off.
 * 2. **Responses stay small.** Every tool caps what it returns. The brain
 *    exists to spend a few hundred tokens instead of tens of thousands, and a
 *    tool that answered with the whole feature list would have given that back.
 */
final class Server
{
    private const PROTOCOL = '2025-06-18';

    private const SUPPORTED = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** Bytes after which a tool response is truncated with a note saying so. */
    private const MAX_RESPONSE = 24000;

    private bool $running = true;

    public function __construct(private readonly Brain $brain) {}

    public function serve(): void
    {
        // stdout is the protocol channel. Anything else written to it corrupts
        // the stream, so PHP is told to keep its own output off it.
        ini_set('display_errors', 'stderr');
        ini_set('log_errors', '0');

        $in = fopen('php://stdin', 'rb');
        if ($in === false) {
            return;
        }

        while ($this->running && ($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);

            if (! is_array($message)) {
                $this->send([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => ['code' => -32700, 'message' => 'Parse error'],
                ]);

                continue;
            }

            $this->handle($message);
        }

        fclose($in);
    }

    /** @param array<string,mixed> $message */
    private function handle(array $message): void
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = $message['params'] ?? [];

        // A notification has no id and must never be answered.
        $isNotification = ! array_key_exists('id', $message);

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'tools/list' => ['tools' => Tools::definitions()],
                'tools/call' => $this->call($params),
                'ping' => new \stdClass,
                'notifications/initialized', 'notifications/cancelled' => null,
                'shutdown' => $this->shutdown(),
                default => throw new \RuntimeException("Method not found: {$method}", -32601),
            };
        } catch (\Throwable $exception) {
            if (! $isNotification) {
                $this->send([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => $exception->getCode() === 0 ? -32603 : (int) $exception->getCode(),
                        'message' => $exception->getMessage(),
                    ],
                ]);
            }

            return;
        }

        if ($isNotification || $result === null) {
            return;
        }

        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? self::PROTOCOL);

        return [
            // Echo the client's version when it is one this speaks, so a newer
            // or older client is not refused over framing that has not changed.
            'protocolVersion' => in_array($requested, self::SUPPORTED, true) ? $requested : self::PROTOCOL,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'laundo-second-brain',
                'version' => Indexer::VERSION,
            ],
            'instructions' => $this->instructions(),
        ];
    }

    private function shutdown(): array
    {
        $this->running = false;

        return [];
    }

    private function instructions(): string
    {
        $manifest = $this->brain->manifest();

        if ($manifest === []) {
            return 'The Laundo Second Brain has not been indexed yet. Run `php .second-brain/bin/brain.php index` in the repository root.';
        }

        return implode("\n", [
            'A queryable map of the Laundo codebase (Laravel 13, app/Modules/{Name} layout).',
            sprintf(
                'Indexed %s: %d nodes, %d edges, %d modules in %d communities, %d features, %d routes, %d tables.',
                $manifest['built_at'] ?? 'unknown',
                $manifest['nodes'] ?? 0,
                $manifest['edges'] ?? 0,
                $manifest['modules'] ?? 0,
                $manifest['communities'] ?? 0,
                $manifest['features'] ?? 0,
                $manifest['routes'] ?? 0,
                $manifest['tables'] ?? 0,
            ),
            '',
            'Use it before exploring the repository for any non-trivial task: search first, then open only the files it ranks.',
            'It returns metadata and file paths, never source code — read the files it names with your own tools.',
            'Skip it for a one-line change to a file you already have open.',
        ]);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function call(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = $params['arguments'] ?? [];

        if (! is_array($arguments)) {
            $arguments = [];
        }

        if (! $this->brain->isBuilt() && $name !== 'second_brain_get_architecture') {
            return $this->text(
                "The Second Brain has not been built yet.\n\n"
                ."Run this in the repository root:\n  php .second-brain/bin/brain.php index\n",
                true
            );
        }

        $payload = Tools::run($this->brain, $name, $arguments);

        return $this->text($this->encodeWithinCap($payload));
    }

    /**
     * Encode, and if it is over the cap **shrink the structure** rather than
     * cutting the string.
     *
     * Cutting the string was the original approach and it was wrong in the
     * worst way available: the reply was still `isError: false`, so the caller
     * had no signal, and the text was no longer JSON, so parsing it returned
     * null — a tool that silently answers nothing. The two tools that actually
     * hit the cap were also told to "pass a smaller `limit`", which neither of
     * them takes.
     *
     * Shrinking repeatedly halves the longest list in the payload and says so
     * in an `omitted` note, so what comes back is always valid, always
     * self-describing, and still useful.
     *
     * @param  array<string,mixed>  $payload
     */
    private function encodeWithinCap(array $payload): string
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        $encode = static fn (array $value): string => (string) (json_encode($value, $flags) ?: '{"error":"could not encode the result"}');

        $json = $encode($payload);

        for ($pass = 0; $pass < 12 && strlen($json) > self::MAX_RESPONSE; $pass++) {
            if (! $this->halveLongestList($payload)) {
                break;
            }

            $payload['truncated'] = 'This answer was too large to return whole, so the longest lists in it were shortened. Ask about one feature, module or capability at a time for the full picture.';
            $json = $encode($payload);
        }

        // Nothing left to shrink and still over — say so in valid JSON rather
        // than handing back half a document.
        if (strlen($json) > self::MAX_RESPONSE) {
            $json = $encode([
                'error' => 'response_too_large',
                'bytes' => strlen($json),
                'limit' => self::MAX_RESPONSE,
                'hint' => 'Narrow the question — ask about one module, feature or symbol rather than a whole section.',
            ]);
        }

        return $json;
    }

    /**
     * Halve the longest list anywhere in the payload, in place.
     *
     * @param  array<string,mixed>  $payload
     * @return bool  false when there is nothing left worth shortening
     */
    private function halveLongestList(array &$payload): bool
    {
        $longest = null;
        $longestSize = 2;   // a pair is not worth halving

        // By value, not by reference. A `foreach ($node as $key => &$value)`
        // leaves `$value` bound to the last element after the loop, and the
        // next assignment through it writes into the array — which is how a
        // feature group came back as the JSON-RPC error "Cannot access offset
        // of type string on string" instead of an answer. This walk only needs
        // to *find* the longest list; the write happens once, below, through a
        // path.
        $walk = function (array $node, array $path) use (&$walk, &$longest, &$longestSize): void {
            foreach ($node as $key => $value) {
                if (! is_array($value)) {
                    continue;
                }

                if (array_is_list($value) && count($value) > $longestSize) {
                    $longestSize = count($value);
                    $longest = [...$path, $key];
                }

                $walk($value, [...$path, $key]);
            }
        };

        $walk($payload, []);

        if ($longest === null) {
            return false;
        }

        $cursor = &$payload;
        foreach ($longest as $step) {
            $cursor = &$cursor[$step];
        }

        $keep = max(1, (int) floor(count($cursor) / 2));
        $dropped = count($cursor) - $keep;
        $cursor = array_slice($cursor, 0, $keep);
        unset($cursor);

        // A reserved key, and coerced to an array before it is written into.
        // This bookkeeping used to go under `omitted`, which a tool payload is
        // entitled to use for its own purposes — and `Brain::feature()` does,
        // as a *string*. Writing an offset into it threw "Cannot access offset
        // of type string on string", and the biggest feature groups came back
        // as a JSON-RPC error instead of an answer.
        if (! isset($payload['_shortened']) || ! is_array($payload['_shortened'])) {
            $payload['_shortened'] = [];
        }

        $payload['_shortened'][implode('.', $longest)] = $dropped;

        return true;
    }

    /** @return array<string,mixed> */
    private function text(string $text, bool $isError = false): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }

    /** @param array<string,mixed> $message */
    private function send(array $message): void
    {
        $encoded = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false) {
            return;
        }

        fwrite(STDOUT, $encoded."\n");
        fflush(STDOUT);
    }

    /**
     * Re-index from inside the server, used by the `reindex` tool argument.
     * Kept here rather than in `Tools` because it is the one operation that
     * writes, and it should be obvious where that happens.
     *
     * @return array<string,mixed>
     */
    public static function reindex(Brain $brain, bool $full): array
    {
        $paths = $brain->paths();

        $forced = null;
        if (! $full) {
            $scanner = new FileScanner($paths);
            $changed = (new GitHistory($paths))->changedSince('HEAD');
            $forced = array_values(array_filter($changed, static fn (string $f) => $scanner->accepts($f)));
        }

        return Indexer::make($paths)->run($forced);
    }
}
