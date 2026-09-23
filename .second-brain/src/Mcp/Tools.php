<?php

namespace Laundo\SecondBrain\Mcp;

use Laundo\SecondBrain\Brain;

/**
 * Six tools, and the reason there are six.
 *
 * Every tool a server exposes is schema text in the model's context on every
 * single turn, whether it is used or not. Twenty narrow tools would cost more
 * than they save, and the model would spend turns choosing between them. These
 * six cover the questions that actually get asked of a codebase — *where is
 * this*, *what is this made of*, *what breaks if I change it*, *what travels
 * with it*, *what is the shape of the whole thing* — and each answers in a few
 * hundred tokens.
 *
 * Nothing here returns source code. The contract is "find first, read second":
 * these say which files matter and how they connect, and the caller opens the
 * two or three that do with its own file tools.
 */
final class Tools
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'second_brain_search',
                'title' => 'Search the codebase map',
                'description' =>
                    "Find where something lives in this codebase. Ask in plain words — \"where is the delivery fee calculated\", ".
                    "\"coupon expiry\", \"CouponService\", \"order_settlements\". Returns ranked file paths with a one-line summary each, ".
                    "plus the features they belong to. Returns metadata only; read the top files yourself afterwards. ".
                    "Question forms like \"what depends on X\" are routed to the dependency graph automatically.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'What you are looking for, in plain words or as a symbol name.'],
                        'limit' => ['type' => 'integer', 'description' => 'How many results (default 8, max 25).', 'default' => 8],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Restrict to one node type.',
                            'enum' => ['class', 'method', 'route', 'table', 'view', 'feature', 'test', 'command', 'permission', 'enum'],
                        ],
                        'module' => ['type' => 'string', 'description' => 'Restrict to one module, e.g. Order, Payment, Driver.'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'second_brain_get_feature',
                'title' => 'Get one capability end to end',
                'description' =>
                    "Everything behind one product capability: its routes, the permission gating them, the controller/service/repository/model ".
                    "files in relevance order, the database tables it touches and the tests that cover it. ".
                    "Accepts a feature id (`discount-codes.create`), a feature label, or a group name (`discount codes`) for every capability in it.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'feature' => ['type' => 'string', 'description' => 'Feature id, label, or group name. Use second_brain_search first if unsure.'],
                    ],
                    'required' => ['feature'],
                ],
            ],
            [
                'name' => 'second_brain_get_module',
                'title' => 'Get one module',
                'description' =>
                    "One module's shape: which layers it has, its key classes with summaries, its models and tables, its routes and ".
                    "permissions, which modules it depends on and which depend on it, and its tests. ".
                    "Note that a sidebar screen is often not its own module here — use second_brain_search if the name is a screen rather than a directory.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'module' => ['type' => 'string', 'description' => 'Module name, e.g. Order, Payment, Driver, Coupon.'],
                    ],
                    'required' => ['module'],
                ],
            ],
            [
                'name' => 'second_brain_get_dependencies',
                'title' => 'What needs this, and what does it need',
                'description' =>
                    "The blast radius of a change. Give a class name, FQCN, file path, route name or table and get what it depends on and ".
                    "what depends on it, with the edge kind that proves each one (constructor injection, resolved call, import, inheritance). ".
                    "Framework classes are excluded — this is the project's own code.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => ['type' => 'string', 'description' => 'Class name, FQCN, file path, route name or table name.'],
                        'direction' => [
                            'type' => 'string',
                            'description' => 'inbound = what would break if you change it; outbound = what it needs. Default both.',
                            'enum' => ['both', 'inbound', 'outbound'],
                            'default' => 'both',
                        ],
                        'depth' => ['type' => 'integer', 'description' => 'Hops to follow (1 or 2; default 1).', 'default' => 1],
                    ],
                    'required' => ['target'],
                ],
            ],
            [
                'name' => 'second_brain_get_related_files',
                'title' => 'Files that travel with this one',
                'description' =>
                    "Given a file or class, the files most likely to need changing alongside it — by call graph, by rendering, by validation, ".
                    "by model relationship, and by git co-change history. Each result says why it is related.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => ['type' => 'string', 'description' => 'File path, class name or FQCN.'],
                        'limit' => ['type' => 'integer', 'description' => 'How many (default 12, max 30).', 'default' => 12],
                    ],
                    'required' => ['target'],
                ],
            ],
            [
                'name' => 'second_brain_get_architecture',
                'title' => 'The shape of the whole codebase',
                'description' =>
                    "Read this once at the start of an unfamiliar task. The stack, the three surfaces (admin panel, mobile API, landing page), ".
                    "the layer contract, the naming conventions that decide where new code goes, what this project deliberately does NOT have ".
                    "(no policies, no events, no API resources), and the communities with their modules. ".
                    "Sections: overview (default), communities, modules, database, routes, git.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'section' => [
                            'type' => 'string',
                            'description' => 'Which part. Default overview.',
                            'enum' => ['overview', 'communities', 'modules', 'database', 'routes', 'git'],
                            'default' => 'overview',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public static function run(Brain $brain, string $name, array $arguments): array
    {
        return match ($name) {
            'second_brain_search' => $brain->search(
                (string) ($arguments['query'] ?? ''),
                self::clamp($arguments['limit'] ?? 8, 1, 25),
                isset($arguments['type']) ? (string) $arguments['type'] : null,
                isset($arguments['module']) ? (string) $arguments['module'] : null,
            ),

            'second_brain_get_feature' => $brain->feature((string) ($arguments['feature'] ?? '')),

            'second_brain_get_module' => $brain->module((string) ($arguments['module'] ?? '')),

            // The direction is coalesced *once* and then tested. Testing the
            // coalesced value and casting the raw key meant an omitted
            // `direction` passed the check and then emitted an undefined-key
            // warning and an empty string — benign only by accident, and a
            // warning from an MCP server is one stray output handler away from
            // corrupting the protocol stream.
            'second_brain_get_dependencies' => $brain->dependencies(
                (string) ($arguments['target'] ?? ''),
                self::oneOf($arguments['direction'] ?? null, ['both', 'inbound', 'outbound'], 'both'),
                self::clamp($arguments['depth'] ?? 1, 1, 2),
            ),

            'second_brain_get_related_files' => $brain->related(
                (string) ($arguments['target'] ?? ''),
                self::clamp($arguments['limit'] ?? 12, 1, 30),
            ),

            'second_brain_get_architecture' => $brain->architecture(
                isset($arguments['section']) ? (string) $arguments['section'] : null
            ),

            default => [
                'error' => "Unknown tool [{$name}].",
                'available' => array_column(self::definitions(), 'name'),
            ],
        };
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        $value = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $value));
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }
}
