<?php

namespace Laundo\SecondBrain\Parse;

use Laundo\SecondBrain\Support\Paths;

/**
 * The two config files that already describe this project's domains.
 *
 * `config/menu.php` groups every panel screen into eight named dropdowns the
 * owner arranged deliberately — "the list reads in the order the platform is
 * built", says the file itself. That is a domain map somebody who knows the
 * business wrote down, and inventing communities by clustering class names
 * beside it would be replacing evidence with a guess.
 *
 * `config/dashboard.php` is the list of models that carry permissions.
 *
 * Both are plain `return [...]` files. They are `require`d rather than parsed:
 * `Model::class` resolves from the file's own `use` statements at compile time
 * and never loads the class, so this costs nothing and cannot be wrong.
 */
final class ConfigReader
{
    public function __construct(private readonly Paths $paths) {}

    /**
     * @return array{
     *     groups:array<string,array<string,mixed>>,
     *     singles:array<string,int>,
     *     icons:array<string,string>,
     *     titles:array<string,string>,
     *     routes:array<string,string>,
     * }
     */
    public function menu(): array
    {
        $path = $this->paths->abs('config/menu.php');

        if (! is_file($path)) {
            return ['groups' => [], 'singles' => [], 'icons' => [], 'titles' => [], 'routes' => []];
        }

        $menu = require $path;

        return [
            'groups' => $menu['groups'] ?? [],
            'singles' => $menu['singles'] ?? [],
            'icons' => $menu['icons'] ?? [],
            'titles' => $menu['titles'] ?? [],
            'routes' => $menu['routes'] ?? [],
        ];
    }

    /**
     * Permission slugs, derived the way `PermissionGenerator` derives them:
     * snake_case of the class basename, crossed with the five fixed actions.
     *
     * @return array{models:list<string>,slugs:array<string,list<string>>}
     */
    public function dashboardModels(): array
    {
        $path = $this->paths->abs('config/dashboard.php');

        if (! is_file($path)) {
            return ['models' => [], 'slugs' => []];
        }

        $config = require $path;
        $models = $config['models'] ?? [];

        $actions = $this->permissionActions();
        $slugs = [];

        foreach ($models as $fqcn) {
            $separator = strrpos((string) $fqcn, '\\');
            $basename = $separator === false ? (string) $fqcn : substr((string) $fqcn, $separator + 1);
            $key = \Laundo\SecondBrain\Support\Text::snake($basename);

            $slugs[$fqcn] = array_map(static fn (string $action) => $key.'.'.$action, $actions);
        }

        return ['models' => array_values($models), 'slugs' => $slugs];
    }

    /**
     * Read from `PermissionGenerator::$actions` rather than hardcoded, so the
     * day a sixth action is added this follows it.
     *
     * @return list<string>
     */
    public function permissionActions(): array
    {
        $path = $this->paths->abs('app/Services/PermissionGenerator.php');
        $default = ['view', 'create', 'update', 'delete', 'toggle'];

        if (! is_file($path)) {
            return $default;
        }

        $source = (string) file_get_contents($path);

        if (preg_match('/\$actions\s*=\s*\[(.*?)\]/s', $source, $matches) !== 1) {
            return $default;
        }

        preg_match_all('/[\'"]([a-z_]+)[\'"]/', $matches[1], $found);

        return $found[1] === [] ? $default : array_values($found[1]);
    }

    /**
     * Named rate limiters, queue connection, timezone — the handful of
     * configuration facts that answer "how does this behave" without opening a
     * file. Read as text: `config/*.php` may reference env() and must not run.
     *
     * @return array<string,mixed>
     */
    public function runtimeFacts(): array
    {
        $facts = [];

        $provider = $this->paths->abs('app/Providers/AppServiceProvider.php');
        if (is_file($provider)) {
            preg_match_all(
                '/RateLimiter::for\(\s*[\'"]([a-z0-9_-]+)[\'"]/i',
                (string) file_get_contents($provider),
                $matches
            );
            $facts['rate_limiters'] = array_values(array_unique($matches[1] ?? []));
        }

        $console = $this->paths->abs('routes/console.php');
        if (is_file($console)) {
            preg_match_all(
                '/Schedule::command\(\s*[\'"]([^\'"]+)[\'"]\s*\)((?:(?!;).)*)/s',
                (string) file_get_contents($console),
                $matches,
                PREG_SET_ORDER
            );

            $facts['schedule'] = array_map(static function (array $match): array {
                preg_match('/->(daily|dailyAt|hourly|weekly|weeklyOn|everyMinute|everyTenMinutes|everyFiveMinutes|monthly|monthlyOn|cron)\(([^)]*)\)/', $match[2], $cadence);

                return [
                    'command' => $match[1],
                    'cadence' => trim(($cadence[1] ?? 'unknown').'('.trim($cadence[2] ?? '').')'),
                ];
            }, $matches);
        }

        return $facts;
    }
}
