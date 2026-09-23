<?php

/*
| The brain's own autoloader.
|
| Deliberately not composer's. Adding `Laundo\SecondBrain\` to composer.json
| would make the tooling a dependency of the application's autoload map, and
| every `composer dump-autoload` a step somebody has to remember. This file is
| four lines and the MCP server, the CLI and the PHPUnit tests all require it.
*/

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'Laundo\\SecondBrain\\')) {
        return;
    }

    $relative = substr($class, strlen('Laundo\\SecondBrain\\'));

    // A class name reaches an autoloader verbatim — `class_exists($input)`
    // triggers it with whatever string it was given. Only the shape a real
    // class name has is turned into a path, so no separator or `..` can be
    // smuggled into the `require`.
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative) !== 1) {
        return;
    }

    $path = __DIR__.'/src/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});
