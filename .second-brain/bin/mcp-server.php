<?php

/*
|--------------------------------------------------------------------------
| MCP entry point
|--------------------------------------------------------------------------
|
| Registered in `.mcp.json` at the repository root, so Claude Code starts it
| automatically. It speaks JSON-RPC 2.0 over stdio and writes nothing else to
| stdout — see Mcp\Server for why that matters.
|
| Start it by hand to check it:
|
|   echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
|     | php .second-brain/bin/mcp-server.php
|
*/

declare(strict_types=1);

require __DIR__.'/../autoload.php';

use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Mcp\Server;
use Laundo\SecondBrain\Support\Paths;

// Errors on stdout would corrupt the protocol stream, and a corrupted stream
// looks to the client like a server that hangs rather than one that failed.
ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

try {
    $brain = new Brain(Paths::discover(__DIR__));
} catch (Throwable $exception) {
    fwrite(STDERR, 'second-brain: '.$exception->getMessage()."\n");
    exit(1);
}

(new Server($brain))->serve();
