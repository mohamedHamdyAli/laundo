<?php

namespace Laundo\SecondBrain\Support;

/**
 * Running a child process without a shell.
 *
 * `proc_open()` takes an **array** of arguments since PHP 7.4, and passing one
 * bypasses the shell entirely. That is not a style preference here — on Windows
 * PHP's `escapeshellarg()` replaces `%` with a space so cmd.exe cannot expand a
 * variable out of it, which silently destroyed
 * `git log --pretty=format:%x01%H%x02%ad%x02%s`: git received a format string
 * with the placeholders blanked, every commit parsed as malformed, and the
 * history came back with zero commits and no error anywhere.
 *
 * No shell also means no quoting rules to get wrong on a path with a space in
 * it, which `D:\nahr\in-house\laundo` does not have and the next checkout might.
 */
final class Process
{
    /**
     * @param  list<string>  $command
     * @param  array<string,string>  $environment  merged over the parent's
     * @return array{status:int,stdout:string,stderr:string}
     */
    public static function run(array $command, string $workingDirectory, array $environment = [], int $timeout = 120): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $environment === [] ? null : array_merge(self::parentEnvironment(), $environment);

        $process = @proc_open($command, $descriptors, $pipes, $workingDirectory, $env);

        if (! is_resource($process)) {
            return ['status' => -1, 'stdout' => '', 'stderr' => 'could not start '.($command[0] ?? '?')];
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process);
                $stderr .= "\ntimed out after {$timeout}s";
                break;
            }

            usleep(2000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return [
            'status' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /** @return array<string,string> */
    private static function parentEnvironment(): array
    {
        $environment = [];

        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) === 1) {
                $environment[(string) $key] = $value;
            }
        }

        return $environment;
    }
}
